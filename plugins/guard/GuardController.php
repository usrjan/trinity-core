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
 */

namespace Jan\Trinity\Plugin\Guard;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class GuardController
{
	private NeuronRepository $neuronRepo;

	/** @var string Путь к корню проекта */
	private string $basePath;

	/** @var array Настройки плагина из базы */
	private array $config;

	/**
	 * Конструктор.
	 */
	public function __construct(NeuronRepository $neuronRepo, string $basePath)
    {
        $this->neuronRepo = $neuronRepo;
        $this->basePath = $basePath;
        $this->config = $this->loadConfig();
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
	 * 
	 * @param string $key — ключ (например, 'login', 'api')
	 * @param int $maxAttempts — максимальное количество попыток
	 * @param int $decaySeconds — за сколько секунд
	 * @return bool — true если лимит превышен
	 */
	public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 60): bool
	{
		$attempts = $this->getAttempts($key);
		return $attempts >= $maxAttempts;
	}

	/**
	 * Записать попытку.
	 * 
	 * @param string $key — ключ
	 */
	public function hit(string $key): void
	{
		$file = $this->getAttemptsFile($key);
		$data = $this->readAttemptsFile($file);
		
		// Добавляем текущую попытку
		$data[] = time();
		
		// Оставляем только попытки за последний интервал
		$decaySeconds = (int) $this->getConfig('decay_seconds', 60);
		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		file_put_contents($file, implode("\n", $data));
	}

	/**
	 * Получить количество попыток.
	 * 
	 * @param string $key — ключ
	 * @return int
	 */
	private function getAttempts(string $key): int
	{
		$file = $this->getAttemptsFile($key);
		$data = $this->readAttemptsFile($file);
		
		$decaySeconds = (int) $this->getConfig('decay_seconds', 60);
		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		return count($data);
	}

	/**
	 * Очистить попытки (после успешного входа).
	 * 
	 * @param string $key — ключ
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
	 * 
	 * @param string $login — логин пользователя
	 * @param string $ip — IP-адрес
	 * @return array — [success => bool, message => string]
	 */
	public function checkLoginAttempt(string $login, string $ip): array
	{
		// Проверяем лимит по IP
		$ipKey = 'login_ip_' . $ip;
		if ($this->tooManyAttempts($ipKey, 10, 300)) {
			return [
				'success' => false,
				'message' => 'Слишком много попыток входа с вашего IP. Попробуйте через 5 минут.',
			];
		}

		// Проверяем лимит по логину
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
	 * 
	 * @param string $login — логин
	 * @param string $ip — IP-адрес
	 */
	public function recordFailedLogin(string $login, string $ip): void
	{
		$this->hit('login_ip_' . $ip);
		$this->hit('login_user_' . md5($login));

		// Логируем попытку
		$this->logSecurityEvent('failed_login', [
			'login' => $login,
			'ip'    => $ip,
		]);
	}

	/**
	 * Очистить попытки после успешного входа.
	 * 
	 * @param string $login — логин
	 * @param string $ip — IP-адрес
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
	 * 
	 * @param string $ip — IP-адрес
	 * @param string $reason — причина
	 * @param int $duration — на сколько секунд (0 = навсегда)
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
	 * 
	 * @param string $ip — IP-адрес
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
	 * 
	 * @param string $ip — IP-адрес
	 * @return bool
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
			return true; // Заблокирован навсегда
		}

		if (strtotime($expiresAt) < time()) {
			unset($blocked[$ip]);
			file_put_contents($file, json_encode($blocked, JSON_PRETTY_PRINT));
			return false; // Блокировка истекла
		}

		return true;
	}

	/**
	 * Получить список заблокированных IP (для админки).
	 * 
	 * @return array
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
	 * 
	 * @return string
	 */
	public function generateCsrfToken(): string
	{
		$token = bin2hex(random_bytes(32));
		
		// Записываем в сессию только если она активна
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION['csrf_token'] = $token;
		}

		// Записываем в cookie, доступный для JavaScript (не HttpOnly)
		setcookie(
			'csrf_token',
			$token,
			[
				'expires' => 0,        // до закрытия браузера
				'path' => '/',
				'domain' => '',
				'secure' => false,     // true для HTTPS
				'httponly' => false,   // ДОЛЖЕН быть false, чтобы JS мог читать
				'samesite' => 'Lax'
			]
		);
		
		return $token;
	}

	/**
	 * Проверить CSRF-токен.
	 * 
	 * @param string $token — токен из формы
	 * @return bool
	 */
	public function validateCsrfToken(string $token): bool
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return false;
		}
		$storedToken = $_SESSION['csrf_token'] ?? null;
		if ($storedToken === null || $token === null) {
			return false;
		}
		return hash_equals($storedToken, $token);
	}
	
	/**
	 * Получить CSRF-токен из сессии.
	 * 
	 * @return string|null
	 */
	public function getCsrfToken(): ?string
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return null;
		}
		return $_SESSION['csrf_token'] ?? null;
	}

	// ============================================
	// 5. ЛОГИРОВАНИЕ БЕЗОПАСНОСТИ
	// ============================================

	/**
	 * Записать событие безопасности.
	 * 
	 * @param string $event — тип события
	 * @param array $context — контекст
	 */
	private function logSecurityEvent(string $event, array $context = []): void
	{
		$logDir = $this->basePath . '/var/log';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0775, true);
		}

		$logFile = $logDir . '/security.log';
		
		// Безопасно получаем логин из сессии
		$login = 'unknown';
		if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_login'])) {
			$login = $_SESSION['user_login'];
		}
		
		$entry = sprintf(
			"[%s] %s: %s\n  Context: %s\n  IP: %s\n\n",
			date('Y-m-d H:i:s'),
			$event,
			$login,
			json_encode($context, JSON_UNESCAPED_UNICODE),
			$context['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'
		);

		file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
	}

	// ============================================
	// 6. API ДЛЯ АДМИНКИ
	// ============================================

	/**
	 * GET /api/guard/blocked-ips
	 * Получить список заблокированных IP.
	 */
	public function getBlockedIpsApi(): JsonResponse
	{
		return ApiResponse::success($this->getBlockedIps());
	}

	/**
	 * POST /api/guard/block-ip
	 * Заблокировать IP.
	 */
	public function blockIpApi(Request $request): JsonResponse
	{
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
	 * Разблокировать IP.
	 */
	public function unblockIpApi(Request $request): JsonResponse
	{
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
	 * Если файла нет — вернуть пустой массив.
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