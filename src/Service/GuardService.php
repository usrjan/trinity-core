<?php
declare(strict_types=1);

/**
 * СЕРВИС БЕЗОПАСНОСТИ "СТРАЖ"
 *
 * Вся логика безопасности Trinity. Без HTTP. Без JsonResponse.
 * Только данные. Только работа с файлами, сессией и базой.
 *
 * @package Jan\Trinity\Core\Service
 */

namespace Jan\Trinity\Core\Service;

use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Session;

class GuardService
{
	/** @var NeuronRepository — репозиторий нейронов. */
	private NeuronRepository $neuronRepo;

	/** @var string Путь к корню проекта. */
	private string $basePath;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	/** @var Session — сессия Symfony. Один источник CSRF-токена. */
	private Session $session;

	/** @var Cache|null — кэш. Может быть null, и это нормально. */
	private ?Cache $cache;

	/**
	 * @var bool Доступен ли APCu. Если нет — работаем с файлами.
	 */
	private bool $apcuAvailable;

	/**
	 * Конструктор. Принимает всё, что нужно.
	 *
	 * @param NeuronRepository $neuronRepo
	 * @param string $basePath
	 * @param LoggerInterface $logger
	 * @param ConfigService $configService
	 * @param Session $session
	 */
	public function __construct(
		NeuronRepository $neuronRepo,
		string $basePath,
		LoggerInterface $logger,
		ConfigService $configService,
		Session $session,
		?Cache $cache = null
	) {
		$this->neuronRepo = $neuronRepo;
		$this->basePath = $basePath;
		$this->logger = $logger;
		$this->configService = $configService;
		$this->session = $session;
		$this->cache = $cache;

		$this->apcuAvailable = extension_loaded('apcu') && (bool) ini_get('apc.enabled');
	}

	// ============================================
	// 1. RATE LIMITING
	// ============================================

	/**
	 * Проверить, не слишком ли много попыток.
	 *
	 * @param string $key
	 * @param int $maxAttempts
	 * @param int $decaySeconds
	 * @return bool
	 */
	public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 120): bool
	{
		$attempts = $this->getAttempts($key);
		return $attempts >= $maxAttempts;
	}

	/**
	 * Записать попытку и вернуть актуальное количество.
	 *
	 * @param string $key
	 * @param int|null $decaySeconds
	 * @return int — количество попыток после записи
	 */
	public function hit(string $key, ?int $decaySeconds = null): int
	{
		if ($this->apcuAvailable) {
			$attemptsKey = 'guard.attempts.' . $key;
			$attempts = apcu_fetch($attemptsKey);
			$attempts = $attempts === false ? 1 : (int) $attempts + 1;
			apcu_store($attemptsKey, $attempts, $decaySeconds ?? 300);
			return $attempts;
		}

		$file = $this->getAttemptsFile($key);

		$handle = fopen($file, 'c+');
		if ($handle === false) {
			return PHP_INT_MAX;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			return PHP_INT_MAX;
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

		$count = count($data);

		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, implode("\n", $data));
		fflush($handle);

		flock($handle, LOCK_UN);
		fclose($handle);

		$this->cleanupAttempts();

		return $count;
	}

	/**
	 * Получить количество попыток.
	 *
	 * @param string $key
	 * @return int
	 */
	private function getAttempts(string $key): int
	{
		if ($this->apcuAvailable) {
			$attempts = apcu_fetch('guard.attempts.' . $key);
			if ($attempts === false) {
				return 0;
			}
			return (int) $attempts;
		}

		$file = $this->getAttemptsFile($key);

		if (!file_exists($file)) {
			return 0;
		}

		$handle = fopen($file, 'r');
		if ($handle === false) {
			$this->logger->warning('guard: failed to open attempts file', [
				'file' => $file,
			]);
			return PHP_INT_MAX;
		}

		if (!flock($handle, LOCK_SH)) {
			fclose($handle);
			$this->logger->warning('guard: failed to lock attempts file', [
				'file' => $file,
			]);
			return PHP_INT_MAX;
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

	/**
	 * Очистить попытки.
	 *
	 * @param string $key
	 */
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

	/**
	 * Проверить попытку входа.
	 *
	 * @param string $login
	 * @param string $ip
	 * @return array
	 */
	public function checkLoginAttempt(string $login, string $ip, bool $captchaSolved = false): array
	{
		$maxAttempts = $this->getMaxAttempts();
		$decaySeconds = $this->getDecaySeconds();

		$pairKey = 'login_pair_' . md5($ip . '|' . strtolower($login));

		if ($this->tooManyAttempts($pairKey, $maxAttempts, $decaySeconds)) {
			if (!$captchaSolved) {
				$this->hit($pairKey, $decaySeconds);
				return [
					'success' => false,
					'captcha' => true,
					'message' => 'Подтвердите, что вы человек.',
				];
			}

			return [
				'success' => false,
				'message' => 'Слишком много попыток. Попробуйте позже.',
			];
		}

		$ipKey = 'login_ip_' . $ip;
		if ($this->tooManyAttempts($ipKey, $maxAttempts * 10, $decaySeconds)) {
			return [
				'success' => false,
				'message' => 'Слишком много попыток с вашего IP. Попробуйте позже.',
			];
		}

		return ['success' => true];
	}

	/**
	 * Записать неудачную попытку входа.
	 *
	 * @param string $login
	 * @param string $ip
	 */
	public function recordFailedLogin(string $login, string $ip): void
	{
		$decaySeconds = (int) $this->getConfig('decay_seconds', 300);

		$pairKey = 'login_pair_' . md5($ip . '|' . strtolower($login));
		$this->hit($pairKey, $decaySeconds);
		$this->hit('login_ip_' . $ip, $decaySeconds);

		$this->logSecurityEvent('failed_login', [
			'login' => $login,
			'ip'    => $ip,
		]);
	}

	/**
	 * Очистить попытки входа.
	 *
	 * @param string $login
	 * @param string $ip
	 */
	public function clearLoginAttempts(string $login, string $ip): void
	{
		$pairKey = 'login_pair_' . md5($ip . '|' . strtolower($login));
		$this->clear($pairKey);
		$this->clear('login_ip_' . $ip);
	}

	// ============================================
	// 3. БЛОКИРОВКА IP
	// ============================================

	/**
	 * Заблокировать IP.
	 *
	 * @param string $ip
	 * @param string $reason
	 * @param int $duration
	 */
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
		$ip = $this->normalizeIp($ip);
		$blocked[$ip] = [
			'blocked_at' => date('c'),
			'reason'     => $reason,
			'expires_at' => $duration > 0 ? date('c', time() + $duration) : null,
		];

		$this->writeJsonFile($file, $blocked);

		$this->logger->info('guard: ip blocked', [
			'ip'       => $ip,
			'reason'   => $reason,
			'duration' => $duration,
		]);

		if ($this->cache !== null) {
			$this->cache->forget('guard.blocked_ips');
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	/**
	 * Разблокировать IP.
	 *
	 * @param string $ip
	 */
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
		$ip = $this->normalizeIp($ip);
		unset($blocked[$ip]);
		$this->writeJsonFile($file, $blocked);

		if ($this->cache !== null) {
			$this->cache->forget('guard.blocked_ips');
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	/**
	 * Проверить, заблокирован ли IP.
	 *
	 * @param string $ip
	 * @return bool
	 */
	public function isIpBlocked(string $ip): bool
	{
		$blocked = $this->getBlockedIpsCached();
		$ip = $this->normalizeIp($ip);
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
	 * Возвращает список заблокированных IP с кэшем в APCu.
	 *
	 * @return array
	 */
	private function getBlockedIpsCached(): array
	{
		$cacheKey = 'guard.blocked_ips';

		if ($this->cache !== null && $this->cache->isEnabled()) {
			return $this->cache->remember($cacheKey, function () {
				$file = $this->basePath . '/var/guard/blocked_ips.json';
				return $this->readJsonFile($file);
			}, 30);
		}

		$file = $this->basePath . '/var/guard/blocked_ips.json';
		return $this->readJsonFile($file);
	}

	/**
	 * Получить список заблокированных IP.
	 *
	 * @return array
	 */
	public function getBlockedIps(): array
	{
		return $this->getBlockedIpsCached();
	}

	/**
	 * Очистить истёкшие блокировки.
	 */
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

			if ($this->cache !== null) {
				$this->cache->forget('guard.blocked_ips');
			}

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

	/**
	 * Генерирует новый CSRF-токен.
	 * @return string
	 */
	public function generateCsrfToken(): string
	{
		return (new CsrfService($this->session))->generateToken();
	}

	/**
	 * Проверить CSRF-токен.
	 *
	 * @param string $token
	 * @return bool
	 */
	public function validateCsrfToken(string $token): bool
	{
		return (new CsrfService($this->session))->validateToken($token);
	}

	// ============================================
	// 5. ЛОГИРОВАНИЕ БЕЗОПАСНОСТИ
	// ============================================

	/**
	 * Записать событие безопасности.
	 *
	 * @param string $event
	 * @param array $context
	 */
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

	/**
	 * Очистить старые файлы попыток.
	 */
	private function cleanupAttempts(): void
	{
		if ($this->apcuAvailable) {
			return;
		}
		
		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) {
			return;
		}

		$marker = $dir . '/.last_cleanup';
		$now = time();

		if (file_exists($marker)) {
			$last = (int) file_get_contents($marker);
			if ($last > 0 && ($now - $last) < 3600) {
				return;
			}
		}

		file_put_contents($marker, (string) $now, LOCK_EX);

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

	/**
	 * Записать JSON-файл атомарно.
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

	/**
	 * Возвращает максимальное количество попыток из конфига.
	 *
	 * @return int
	 */
	public function getMaxAttempts(): int
	{
		return (int) $this->getConfig('max_attempts', 5);
	}

	/**
	 * Возвращает окно попыток в секундах.
	 *
	 * @return int
	 */
	public function getDecaySeconds(): int
	{
		return (int) $this->getConfig('decay_seconds', 300);
	}

	/**
	 * Возвращает длительность блокировки из конфига.
	 *
	 * @return int
	 */
	public function getBlockDuration(): int
	{
		return (int) $this->getConfig('block_duration', 3600);
	}

	/**
	 * Возвращает существующий CSRF-токен или создаёт новый.
	 *
	 * @return string
	 */
	public function getOrCreateCsrfToken(): string
	{
		// [Ревью]: Делегирование в CsrfService — один источник правды.
		return (new CsrfService($this->session))->getOrCreateToken();
	}

	/**
	 * Генерирует простую арифметическую капчу.
	 *
	 * @return array ['question' => string, 'expected' => string]
	 */
	public function generateCaptcha(): array
	{
		$a = random_int(1, 9);
		$b = random_int(1, 9);

		return [
			'question' => "Сколько будет {$a} + {$b}?",
			'expected' => (string) ($a + $b),
		];
	}

	/**
	 * Проверяет ответ на капчу.
	 *
	 * @param string $answer — ответ гостя
	 * @param string $expected — ожидаемый ответ
	 * @return bool
	 */
	public function validateCaptcha(string $answer, string $expected): bool
	{
		return hash_equals($expected, $answer);
	}

	/**
	 * Возвращает значение конфига.
	 */
	public function getConfig(string $key, $default = null)
	{
		return $this->configService->get('guard.' . $key, $default);
	}

	/**
	 * IPv6 — 2001:db8::1 и 2001:0DB8:1 — один адрес,
	 * но разные строки. inet_pton(inet_ntop) — нормализует.
	 * Если IP битый — возвращаем как есть, чтобы не терять.
	 */
	private function normalizeIp(string $ip): string
	{
		$packed = @inet_pton($ip);
		if ($packed === false) {
			return $ip;
		}

		$normalized = @inet_ntop($packed);
		return $normalized !== false ? $normalized : $ip;
	}
}