<?php

declare(strict_types=1);

/**
 * БЛОКИРОВЩИК IP
 *
 * Хранит заблокированные IP в JSON-файле. С flock для атомарности.
 * С кэшем в APCu на 30 секунд, чтобы не читать файл на каждом запросе.
 *
 * === ПОЧЕМУ JSON, А НЕ БАЗА ===
 * Потому что блокировка IP — это не «данные». Это — «состояние».
 * Состояние живёт в файле. Файл — рядом. База — далеко. А блокировка
 * нужна быстро. Без SELECT. Без JOIN. Без «подожди».
 *
 * @package Jan\Trinity\Core\Service
 */

namespace Jan\Trinity\Core\Service;

use Jan\Trinity\Core\Cache;
use Psr\Log\LoggerInterface;

class IpBlocker
{
	/** @var string — путь к корню проекта. */
	private string $basePath;

	/** @var Cache|null — кэш. Может быть null. */
	private ?Cache $cache;

	/** @var LoggerInterface|null — логгер. */
	private ?LoggerInterface $logger;

	/**
	 * Конструктор.
	 *
	 * @param string $basePath
	 * @param Cache|null $cache
	 * @param LoggerInterface|null $logger
	 */
	public function __construct(
		string $basePath,
		?Cache $cache = null,
		?LoggerInterface $logger = null
	) {
		$this->basePath = rtrim($basePath, '/');
		$this->cache = $cache;
		$this->logger = $logger;
	}

	// ============================================
	// БЛОКИРОВКА
	// ============================================

	/**
	 * Блокирует IP.
	 *
	 * @param string $ip
	 * @param string $reason
	 * @param int $duration — секунды. 0 — навсегда.
	 */
	public function block(string $ip, string $reason = 'blocked', int $duration = 3600): void
	{
		$this->blockMany([$ip], $reason, $duration);
	}

	/**
	 * Блокирует несколько IP за одну операцию.
	 *
	 * @param array $ips — список IP
	 * @param string $reason — причина
	 * @param int $duration — секунды. 0 — навсегда.
	 */
	public function blockMany(array $ips, string $reason = 'blocked', int $duration = 3600): void
	{
		if (empty($ips)) {
			return;
		}

		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) {
			if ($this->logger !== null) {
				$this->logger->warning('guard: failed to open lock file', ['file' => $lockFile]);
			}
			return;
		}

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			if ($this->logger !== null) {
				$this->logger->warning('guard: failed to lock file', ['file' => $lockFile]);
			}
			return;
		}

		$blocked = $this->readJsonFile($file);
		$now = date('c');
		$expires = $duration > 0 ? date('c', time() + $duration) : null;

		foreach ($ips as $ip) {
			$blocked[$ip] = [
				'blocked_at' => $now,
				'reason'     => $reason,
				'expires_at' => $expires,
			];
		}

		$this->writeJsonFile($file, $blocked);

		if ($this->logger !== null) {
			$this->logger->info('guard: ips blocked', [
				'ips'      => $ips,
				'reason'   => $reason,
				'duration' => $duration,
				'count'    => count($ips),
			]);
		}

		if ($this->cache !== null) {
			$this->cache->forget('guard.blocked_ips');
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	// ============================================
	// РАЗБЛОКИРОВКА
	// ============================================

	/**
	 * Разблокирует IP.
	 *
	 * @param string $ip
	 */
	public function unblock(string $ip): void
	{
		$this->unblockMany([$ip]);
	}

	/**
	 * Разблокирует несколько IP за одну операцию.
	 *
	 * @param array $ips — список IP
	 */
	public function unblockMany(array $ips): void
	{
		if (empty($ips)) {
			return;
		}

		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) {
			if ($this->logger !== null) {
				$this->logger->warning('guard: failed to open lock file', ['file' => $lockFile]);
			}
			return;
		}

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			if ($this->logger !== null) {
				$this->logger->warning('guard: failed to lock file', ['file' => $lockFile]);
			}
			return;
		}

		$blocked = $this->readJsonFile($file);
		foreach ($ips as $ip) {
			unset($blocked[$ip]);
		}
		$this->writeJsonFile($file, $blocked);

		if ($this->logger !== null) {
			$this->logger->info('guard: ips unblocked', [
				'ips'   => $ips,
				'count' => count($ips),
			]);
		}

		if ($this->cache !== null) {
			$this->cache->forget('guard.blocked_ips');
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	// ============================================
	// ПРОВЕРКА
	// ============================================

	/**
	 * Проверяет, заблокирован ли IP.
	 *
	 * @param string $ip
	 * @return bool
	 */
	public function isBlocked(string $ip): bool
	{
		$blocked = $this->getBlockedIpsCached();

		if (!isset($blocked[$ip])) {
			return false;
		}

		$expiresAt = $blocked[$ip]['expires_at'] ?? null;
		if ($expiresAt === null) {
			return true;
		}

		if (strtotime($expiresAt) < time()) {
			return false;
		}

		return true;
	}

	/**
	 * Возвращает список заблокированных IP.
	 *
	 * @return array
	 */
	public function getBlockedIps(): array
	{
		return $this->getBlockedIpsCached();
	}

	// ============================================
	// ОЧИСТКА
	// ============================================

	/**
	 * Очищает истёкшие блокировки.
	 */
	public function cleanupExpired(): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) return;

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			return;
		}

		$blocked = $this->readJsonFile($file);
		$now = time();
		$removed = 0;

		foreach ($blocked as $ip => $data) {
			$expiresAt = $data['expires_at'] ?? null;
			if ($expiresAt !== null && strtotime($expiresAt) < $now) {
				unset($blocked[$ip]);
				$removed++;
			}
		}

		if ($removed > 0) {
			$this->writeJsonFile($file, $blocked);

			if ($this->cache !== null) {
				$this->cache->forget('guard.blocked_ips');
			}

			if ($this->logger !== null) {
				$this->logger->info('guard: cleanup removed expired blocked ips', ['removed' => $removed]);
			}
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	// ============================================
	// ВНУТРЕННЕЕ
	// ============================================

	/**
	 * Возвращает заблокированные IP из кэша или из файла.
	 *
	 * @return array
	 */
	private function getBlockedIpsCached(): array
	{
		$cacheKey = 'guard.blocked_ips';
		$file = $this->basePath . '/var/guard/blocked_ips.json';

		if ($this->cache !== null && $this->cache->isEnabled()) {
			return $this->cache->remember($cacheKey, fn() => $this->readJsonFile($file), 30);
		}

		return $this->readJsonFile($file);
	}

	/**
	 * Читает JSON-файл.
	 *
	 * @param string $file
	 * @return array
	 */
	private function readJsonFile(string $file): array
	{
		if (!file_exists($file)) return [];
		$content = file_get_contents($file);
		if (empty($content)) return [];
		$data = json_decode($content, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * Пишет JSON-файл атомарно.
	 *
	 * @param string $file
	 * @param array $data
	 */
	private function writeJsonFile(string $file, array $data): void
	{
		$dir = dirname($file);
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}

		$tmp = $file . '.' . uniqid('', true) . '.tmp';
		$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

		$handle = fopen($tmp, 'c');
		if ($handle === false) return;

		if (flock($handle, LOCK_EX)) {
			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, $payload);
			fflush($handle);
			flock($handle, LOCK_UN);
		}
		fclose($handle);

		rename($tmp, $file);
	}
}