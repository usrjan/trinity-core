<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Service;

use Jan\Trinity\Core\Cache;
use Psr\Log\LoggerInterface;

class IpBlocker
{
	private string $basePath;
	private ?Cache $cache;
	private ?LoggerInterface $logger;

	public function __construct(
		string $basePath,
		?Cache $cache = null,
		?LoggerInterface $logger = null
	) {
		$this->basePath = rtrim($basePath, '/');
		$this->cache = $cache;
		$this->logger = $logger;
	}

	/**
	 * Блокирует IP.
	 */
	public function block(string $ip, string $reason = 'blocked', int $duration = 3600): void
	{
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

		$blocked[$ip] = [
			'blocked_at' => date('c'),
			'reason'     => $reason,
			'expires_at' => $duration > 0 ? date('c', time() + $duration) : null,
		];

		$this->writeJsonFile($file, $blocked);

		if ($this->logger !== null) {
			$this->logger->info('guard: ip blocked', [
				'ip'       => $ip,
				'reason'   => $reason,
				'duration' => $duration,
			]);
		}

		if ($this->cache !== null) {
			$this->cache->forget('guard.blocked_ips');
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	/**
	 * Разблокирует IP.
	 */
	public function unblock(string $ip): void
	{
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
		unset($blocked[$ip]);
		$this->writeJsonFile($file, $blocked);

		if ($this->cache !== null) {
			$this->cache->forget('guard.blocked_ips');
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	/**
	 * Проверяет, заблокирован ли IP.
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
	 */
	public function getBlockedIps(): array
	{
		return $this->getBlockedIpsCached();
	}

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

	private function getBlockedIpsCached(): array
	{
		$cacheKey = 'guard.blocked_ips';
		$file = $this->basePath . '/var/guard/blocked_ips.json';

		if ($this->cache !== null && $this->cache->isEnabled()) {
			return $this->cache->remember($cacheKey, fn() => $this->readJsonFile($file), 30);
		}

		return $this->readJsonFile($file);
	}

	private function readJsonFile(string $file): array
	{
		if (!file_exists($file)) return [];
		$content = file_get_contents($file);
		if (empty($content)) return [];
		$data = json_decode($content, true);
		return is_array($data) ? $data : [];
	}

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