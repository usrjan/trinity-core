<?php

/**
 * СЕРВИС БЕЗОПАСНОСТИ "СТРАЖ"
 * ===========================
 *
 * Вся логика безопасности Trinity. Без HTTP. Без JsonResponse.
 * Только данные. Только работа с файлами, сессией и базой.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. CSRF-токен теперь живёт
 * в Symfony Session. Не в $_SESSION. Один источник состояния.
 * Без «двух источников». Без «пиздеца». Без «потом».
 *
 * [Мириам]: И — это правильно. Потому что мы уже перешли на
 * $session->migrate(true). И $_SESSION — не нужен. И — «навсегда».
 *
 * @package Jan\Trinity\Core\Service
 */

namespace Jan\Trinity\Core\Service;

use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Session;

class GuardService
{
	private NeuronRepository $neuronRepo;

	/** @var string Путь к корню проекта */
	private string $basePath;

	/** @var array Настройки плагина из базы */
	private array $config = [];

	/** @var bool Загружен ли конфиг */
	private bool $configLoaded = false;

	/** @var LoggerInterface — логгер */
	private LoggerInterface $logger;

	/** @var ConfigService — сервис конфигурации */
	private ConfigService $configService;

	/** @var Session — сессия Symfony. Один источник CSRF-токена. */
	private Session $session;

	public function __construct(
		NeuronRepository $neuronRepo,
		string $basePath,
		LoggerInterface $logger,
		ConfigService $configService,
		Session $session
	) {
		$this->neuronRepo = $neuronRepo;
		$this->basePath = $basePath;
		$this->logger = $logger;
		$this->configService = $configService;
		$this->session = $session;
	}

	private function ensureConfigLoaded(): void
	{
		if ($this->configLoaded) {
			return;
		}

		$keys = ['max_attempts', 'decay_seconds', 'block_duration'];
		foreach ($keys as $key) {
			$this->config[$key] = $this->configService->get('guard.' . $key);
		}

		$this->configLoaded = true;
	}

	private function getConfig(string $key, $default = null)
	{
		$this->ensureConfigLoaded();
		return $this->config[$key] ?? $default;
	}

	// ============================================
	// 1. RATE LIMITING
	// ============================================

	public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 120): bool
	{
		$attempts = $this->getAttempts($key);
		return $attempts >= $maxAttempts;
	}

	public function hit(string $key, ?int $decaySeconds = null): void
	{
		$file = $this->getAttemptsFile($key);

		$handle = fopen($file, 'c+');
		if ($handle === false) {
			$this->logger->warning('guard: failed to open attempts file', [
				'file' => $file,
			]);
			return;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			$this->logger->warning('guard: failed to lock attempts file', [
				'file' => $file,
			]);
			return;
		}

		$content = stream_get_contents($handle);
		$data = empty($content) ? [] : array_map('intval', explode("\n", $content));

		$data[] = time();

		if ($decaySeconds === null) {
			$decaySeconds = (int) $this->getConfig('decay_seconds', 120);
		}

		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, implode("\n", $data));
		fflush($handle);

		flock($handle, LOCK_UN);
		fclose($handle);

		$this->cleanupAttempts();
	}

	private function getAttempts(string $key): int
	{
		$file = $this->getAttemptsFile($key);

		if (!file_exists($file)) {
			return 0;
		}

		$handle = fopen($file, 'r');
		if ($handle === false) {
			return 0;
		}

		if (!flock($handle, LOCK_SH)) {
			fclose($handle);
			return 0;
		}

		$content = stream_get_contents($handle);
		$data = empty($content) ? [] : array_map('intval', explode("\n", $content));

		flock($handle, LOCK_UN);
		fclose($handle);

		$decaySeconds = (int) $this->getConfig('decay_seconds', 120);
		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		return count($data);
	}

	public function clear(string $key): void
	{
		$file = $this->getAttemptsFile($key);
		if (!file_exists($file)) {
			return;
		}

		$handle = fopen($file, 'c+');
		if ($handle === false) {
			return;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			return;
		}

		ftruncate($handle, 0);
		fflush($handle);

		flock($handle, LOCK_UN);
		fclose($handle);

		@unlink($file);
	}

	private function getAttemptsFile(string $key): string
	{
		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		return $dir . '/' . md5($key) . '.attempts';
	}

	// ============================================
	// 2. ЗАЩИТА ОТ ПЕРЕБОРА ПАРОЛЕЙ
	// ============================================

	public function checkLoginAttempt(string $login, string $ip): array
	{
		$ipKey = 'login_ip_' . $ip;
		if ($this->tooManyAttempts($ipKey, 10, 300)) {
			return [
				'success' => false,
				'message' => 'Слишком много попыток входа с вашего IP. Попробуйте через 5 минут.',
			];
		}

		$loginKey = 'login_user_' . md5($login);
		if ($this->tooManyAttempts($loginKey, 5, 300)) {
			return [
				'success' => false,
				'message' => 'Слишком много попыток входа для этого пользователя. Попробуйте через 5 минут.',
			];
		}

		return ['success' => true];
	}

	public function recordFailedLogin(string $login, string $ip): void
	{
		$this->hit('login_ip_' . $ip);
		$this->hit('login_user_' . md5($login));

		$this->logSecurityEvent('failed_login', [
			'login' => $login,
			'ip'    => $ip,
		]);
	}

	public function clearLoginAttempts(string $login, string $ip): void
	{
		$this->clear('login_ip_' . $ip);
		$this->clear('login_user_' . md5($login));
	}

	// ============================================
	// 3. БЛОКИРОВКА IP
	// ============================================

	public function blockIp(string $ip, string $reason = 'blocked', int $duration = 3600): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) {
			$this->logger->warning('guard: failed to open lock file', [
				'file' => $lockFile,
			]);
			return;
		}

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			$this->logger->warning('guard: failed to lock file', [
				'file' => $lockFile,
			]);
			return;
		}

		$blocked = $this->readJsonFile($file);

		$blocked[$ip] = [
			'blocked_at' => date('c'),
			'reason'     => $reason,
			'expires_at' => $duration > 0 ? date('c', time() + $duration) : null,
		];

		$this->writeJsonFile($file, $blocked);

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	public function unblockIp(string $ip): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) {
			$this->logger->warning('guard: failed to open lock file', [
				'file' => $lockFile,
			]);
			return;
		}

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			$this->logger->warning('guard: failed to lock file', [
				'file' => $lockFile,
			]);
			return;
		}

		$blocked = $this->readJsonFile($file);
		unset($blocked[$ip]);
		$this->writeJsonFile($file, $blocked);

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	public function isIpBlocked(string $ip): bool
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$blocked = $this->readJsonFile($file);

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

	public function getBlockedIps(): array
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		return $this->readJsonFile($file);
	}

	public function cleanupExpiredBlockedIps(): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) {
			return;
		}

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
			$this->logger->info('guard: cleanup removed expired blocked ips', [
				'removed' => $removed,
			]);
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	// ============================================
	// 4. CSRF-ЗАЩИТА
	// ============================================
	// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Токен — в Symfony Session.
	// Не в $_SESSION. Один источник. Без «пиздеца».
	// ============================================

	public function generateCsrfToken(): string
	{
		$token = bin2hex(random_bytes(32));

		// [Лорелея]: Сессия может быть не запущена. Запускаем.
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		// [Мириам]: Один источник. Symfony Session. Без $_SESSION.
		$this->session->set('csrf_token', $token);

		return $token;
	}

	public function validateCsrfToken(string $token): bool
	{
		// [Лорелея]: Сессия может быть не запущена. Запускаем.
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		// [Мириам]: Один источник. Symfony Session. Без $_SESSION.
		$storedToken = $this->session->get('csrf_token');

		if ($storedToken === null || $token === null) {
			return false;
		}

		return hash_equals($storedToken, $token);
	}

	// ============================================
	// 5. ЛОГИРОВАНИЕ БЕЗОПАСНОСТИ
	// ============================================

	private function logSecurityEvent(string $event, array $context = []): void
	{
		if (!isset($context['ip'])) {
			$context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		}
		if (!isset($context['login'])) {
			$context['login'] = 'unknown';
		}

		$this->logger->info($event, $context);
	}

	private function readJsonFile(string $file): array
	{
		if (!file_exists($file)) {
			return [];
		}
		$content = file_get_contents($file);
		if (empty($content)) {
			return [];
		}
		$data = json_decode($content, true);
		return is_array($data) ? $data : [];
	}

	private function cleanupAttempts(): void
	{
		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) {
			return;
		}

		if (random_int(1, 100) > 1) {
			return;
		}

		$now = time();
		$decaySeconds = (int) $this->getConfig('decay_seconds', 120);

		$files = glob($dir . '/*.attempts');
		if ($files === false) {
			return;
		}

		$removed = 0;

		foreach ($files as $file) {
			if (filemtime($file) < ($now - $decaySeconds)) {
				if (@unlink($file)) {
					$removed++;
				}
			}
		}

		if ($removed > 0) {
			$this->logger->info('guard: cleanup removed stale attempts files', [
				'removed' => $removed,
			]);
		}
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
		if ($handle === false) {
			return;
		}

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