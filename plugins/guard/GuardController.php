<?php

/**
 * ПЛАГИН "СТРАЖ" — БЕЗОПАСНОСТЬ
 * 
 * Третий плагин Trinity.
 * Защищает систему от атак, перебора паролей и спама.
 * 
 * === ЧТО ДЕЛАЕТ ===
 * 1. Rate limiting — ограничение частоты запросов
 * 2. Защита от перебора паролей (brute force)
 * 3. Блокировка IP после множества ошибок
 * 4. Логирование попыток входа
 * 5. CSRF-защита для форм
 * 6. Белый/чёрный список IP
 * 
 * === ДЛЯ МЕНЯ ===
 * Это иммунная система Trinity.
 * Как Страж ворот в Амбере — он проверяет каждого кто входит.
 * Без него система уязвима. С ним — защищена.
 * 
 * === КНИГА ===
 * Глава 16. Страж ворот.
 * 
 * У входа в Амбер стоит Страж.
 * Он знает кто друг, а кто враг.
 * И он никогда не спит.
 * 
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 * 
 * [Лорелея]: Методы blockIpApi(), unblockIpApi() и getBlockedIpsApi()
 * теперь требуют роль администратора. Раньше любой авторизованный
 * пользователь мог заблокировать любого IP. Это была дыра.
 * 
 * [Мириам]: В blockIpApi() и unblockIpApi() добавлена проверка CSRF.
 * Это POST-запросы, они меняют состояние. Без CSRF любой сайт мог
 * заблокировать IP от имени админа, если он залогинен.
 * 
 * [Лорелея]: Добавлен trait AuthMiddleware и Session в конструктор.
 * Это нужно для requireAdminForApi() и проверки CSRF.
 */

namespace Jan\Trinity\Plugin\Guard;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class GuardController
{
	use AuthMiddleware;

	private NeuronRepository $neuronRepo;

	/** @var string Путь к корню проекта */
	private string $basePath;

	/** @var array Настройки плагина из базы */
	private array $config;

    /**
     * @var LoggerInterface — логгер.
     * [Лорелея]: Теперь через LoggerInterface. Вместо file_put_contents.
     * Это даёт ротацию, уровни, формат. И — единый подход с Monitor.
     */
    private LoggerInterface $logger;

	/**
	 * Конструктор.
	 */
	public function __construct(
        NeuronRepository $neuronRepo,
        Session $session,
        string $basePath,
        LoggerInterface $logger
	) {
		$this->neuronRepo = $neuronRepo;
		$this->basePath = $basePath;
		$this->logger = $logger;
		$this->config = $this->loadConfig();

		// Инициализация middleware авторизации
		$this->initAuth($session);
	}

	/**
	 * Загружает настройки из базы.
	 */
	private function loadConfig(): array
	{
		$keys = ['max_attempts', 'decay_seconds', 'block_duration'];
		$result = [];
		foreach ($keys as $key) {
			$result[$key] = $this->neuronRepo->findConfigValue('guard.' . $key);
		}
		return $result;
	}

	/**
	 * Получить значение настройки.
	 */
	private function getConfig(string $key, $default = null)
	{
		return $this->config[$key] ?? $default;
	}

	// ============================================
	// 1. RATE LIMITING
	// ============================================

	/**
	 * Проверить не превышен ли лимит запросов.
	 */
	public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 120): bool
	{
		$attempts = $this->getAttempts($key);
		return $attempts >= $maxAttempts;
	}

	/**
	 * Записать попытку.
	 */
	public function hit(string $key, ?int $decaySeconds = null): void
	{
		$file = $this->getAttemptsFile($key);
		$data = $this->readAttemptsFile($file);

		$data[] = time();

		// [Лорелея]: Если decaySeconds не передан — берём из конфига.
		// Если передан — используем его. Это нужно, чтобы hit() и
		// tooManyAttempts() работали с одним интервалом.
		if ($decaySeconds === null) {
			$decaySeconds = (int) $this->getConfig('decay_seconds', 120);
		}

		error_log("[Guard] hit: key={$key}, decaySeconds={$decaySeconds}, before=" . count($data));

		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		error_log("[Guard] hit: after=" . count($data));
		file_put_contents($file, implode("\n", $data));
	}

	/**
	 * Получить количество попыток.
	 */
	private function getAttempts(string $key): int
	{
		$file = $this->getAttemptsFile($key);
		$data = $this->readAttemptsFile($file);

		$decaySeconds = (int) $this->getConfig('decay_seconds', 120);
		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		return count($data);
	}

	/**
	 * Очистить попытки.
	 */
	public function clear(string $key): void
	{
		$file = $this->getAttemptsFile($key);
		if (file_exists($file)) {
			unlink($file);
		}
	}

	/**
	 * Путь к файлу с попытками.
	 */
	private function getAttemptsFile(string $key): string
	{
		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		return $dir . '/' . md5($key) . '.attempts';
	}

	/**
	 * Прочитать файл с попытками.
	 */
	private function readAttemptsFile(string $file): array
	{
		if (!file_exists($file)) {
			return [];
		}
		$content = file_get_contents($file);
		if (empty($content)) {
			return [];
		}
		return array_map('intval', explode("\n", $content));
	}

	// ============================================
	// 2. ЗАЩИТА ОТ ПЕРЕБОРА ПАРОЛЕЙ
	// ============================================

	/**
	 * Проверить попытку входа.
	 */
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

	/**
	 * Записать неудачную попытку входа.
	 */
	public function recordFailedLogin(string $login, string $ip): void
	{
		$this->hit('login_ip_' . $ip);
		$this->hit('login_user_' . md5($login));

		$this->logSecurityEvent('failed_login', [
			'login' => $login,
			'ip'    => $ip,
		]);
	}

	/**
	 * Очистить попытки после успешного входа.
	 */
	public function clearLoginAttempts(string $login, string $ip): void
	{
		$this->clear('login_ip_' . $ip);
		$this->clear('login_user_' . md5($login));
	}

	// ============================================
	// 3. БЛОКИРОВКА IP
	// ============================================

	/**
	 * Заблокировать IP-адрес.
	 */
	public function blockIp(string $ip, string $reason = 'blocked', int $duration = 3600): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$blocked = $this->readJsonFile($file);

		$blocked[$ip] = [
			'blocked_at' => date('c'),
			'reason'     => $reason,
			'expires_at' => $duration > 0 ? date('c', time() + $duration) : null,
		];

		file_put_contents($file, json_encode($blocked, JSON_PRETTY_PRINT));
	}

	/**
	 * Разблокировать IP-адрес.
	 */
	public function unblockIp(string $ip): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$blocked = $this->readJsonFile($file);

		unset($blocked[$ip]);

		file_put_contents($file, json_encode($blocked, JSON_PRETTY_PRINT));
	}

	/**
	 * Проверить заблокирован ли IP.
	 */
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
			unset($blocked[$ip]);
			file_put_contents($file, json_encode($blocked, JSON_PRETTY_PRINT));
			return false;
		}

		return true;
	}

	/**
	 * Получить список заблокированных IP.
	 */
	public function getBlockedIps(): array
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		return $this->readJsonFile($file);
	}

	// ============================================
	// 4. CSRF-ЗАЩИТА
	// ============================================

	/**
	 * Сгенерировать CSRF-токен.
	 */
	public function generateCsrfToken(): string
	{
		$token = bin2hex(random_bytes(32));
		$_SESSION['csrf_token'] = $token;

		setcookie(
			'csrf_token',
			$token,
			[
				'expires' => 0,
				'path' => '/',
				'domain' => '',
				'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
				'httponly' => false,
				'samesite' => 'Lax'
			]
		);

		return $token;
	}

	/**
	 * Проверить CSRF-токен.
	 */
	public function validateCsrfToken(string $token): bool
	{
		$storedToken = $_SESSION['csrf_token'] ?? null;
		if ($storedToken === null || $token === null) {
			return false;
		}
		return hash_equals($storedToken, $token);
	}

	// ============================================
	// 5. ЛОГИРОВАНИЕ БЕЗОПАСНОСТИ
	// ============================================

    /**
     * Записать событие безопасности.
     * [Лорелея]: Вместо file_put_contents — $this->logger->info().
     * Monolog сам добавит дату, уровень, отформатирует контекст.
     * И — ротация. И — уровни. И — канал security.
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

	// ============================================
	// 6. API ДЛЯ АДМИНКИ
	// ============================================

	/**
	 * GET /api/guard/blocked-ips
	 * 
	 * [Лорелея]: Теперь требует роль администратора.
	 * Раньше список заблокированных IP видел любой.
	 */
	public function getBlockedIpsApi(): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		return ApiResponse::success($this->getBlockedIps());
	}

	/**
	 * POST /api/guard/block-ip
	 * 
	 * [Мириам]: Теперь требует роль администратора и CSRF.
	 * Раньше любой авторизованный пользователь мог заблокировать IP.
	 */
	public function blockIpApi(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = json_decode($request->getContent(), true);
		$ip = $body['ip'] ?? null;
		$reason = $body['reason'] ?? 'manual_block';
		$duration = (int) ($body['duration'] ?? 3600);

		if (!$ip) {
			return ApiResponse::error('IP обязателен');
		}

		$this->blockIp($ip, $reason, $duration);
		return ApiResponse::success(null, 'IP заблокирован');
	}

	/**
	 * POST /api/guard/unblock-ip
	 * 
	 * [Мириам]: Теперь требует роль администратора и CSRF.
	 */
	public function unblockIpApi(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = json_decode($request->getContent(), true);
		$ip = $body['ip'] ?? null;

		if (!$ip) {
			return ApiResponse::error('IP обязателен');
		}

		$this->unblockIp($ip);
		return ApiResponse::success(null, 'IP разблокирован');
	}

	/**
	 * Прочитать JSON-файл и вернуть массив.
	 */
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
}