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
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Validator;
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

	/**
	 * @var array Настройки плагина из базы.
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Раньше $config заполнялся
	 * в конструкторе через loadConfig(). И это был I/O в конструкторе.
	 * Теперь — $config пустой. И заполняется ЛЕНИВО. При первом getConfig().
	 *
	 * [Мириам]: Это — правильнее. Потому что:
	 *   - Конструктор — быстрый. Без I/O.
	 *   - Если конфиг не нужен — он не загружается.
	 *   - Если база упадёт — конструктор не упадёт.
	 *   - Стоимость — предсказуема. Ноль в конструкторе.
	 */
	private array $config = [];

	/**
	 * @var bool Загружен ли конфиг.
	 * [Лорелея]: Флаг. Чтобы не загружать дважды.
	 */
	private bool $configLoaded = false;

    /**
     * @var LoggerInterface — логгер.
     * [Лорелея]: Теперь через LoggerInterface. Вместо file_put_contents.
     * Это даёт ротацию, уровни, формат. И — единый подход с Monitor.
     */
    private LoggerInterface $logger;

	/** @var ConfigService — сервис конфигурации */
	private ConfigService $configService;

	private Validator $validator;


	/**
	 * Конструктор.
	 */
	public function __construct(
        NeuronRepository $neuronRepo,
        Session $session,
        string $basePath,
        LoggerInterface $logger,
		ConfigService $configService
	) {
		$this->neuronRepo = $neuronRepo;
		$this->basePath = $basePath;
		$this->logger = $logger;

		// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Присваиваем ConfigService
		// ДО вызова loadConfig(). Потому что loadConfig() использует
		// $this->configService. И если он не инициализирован —
		// PHP падает с "must not be accessed before initialization".
		//
		// [Мириам]: Порядок важен. Сначала — присвоить. Потом — использовать.
		// Это — классика. И мы её забыли. И теперь — исправляем.
		$this->configService = $configService;

		// [Мириам]: НИКАКОГО loadConfig() здесь. НИКАКОГО I/O.
		// Только присвоение зависимостей. Точка.
		// $this->config остаётся пустым. Заполнится при первом getConfig().

		$this->initAuth($session);
	}

	/**
	 * Загрузить конфиг — ЛЕНИВО.
	 *
	 * [Лорелея]: Вызывается при первом getConfig().
	 * Один раз. Потом — кэш в $this->config.
	 *
	 * [Мириам]: Тут — I/O. Но — не в конструкторе.
	 * И — только если конфиг реально нужен.
	 * И — только один раз за запрос.
	 */
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


	/**
	 * Получить значение настройки.
	 *
	 * [Лорелея]: Ленивая загрузка. При первом вызове.
	 * И — кэш. При последующих.
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	private function getConfig(string $key, $default = null)
	{
		$this->ensureConfigLoaded();
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

		// ============================================
		// [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. flock().
		// ============================================
		// Раньше здесь был read-modify-write БЕЗ блокировки:
		//     $data = $this->readAttemptsFile($file);
		//     $data[] = time();
		//     file_put_contents($file, implode("\n", $data));
		//
		// И это — ГОНКА. Если два запроса одновременно — оба
		// читают, оба добавляют, оба пишут. И — ОДИН перезаписывает
		// ДРУГОГО. Попытка — ПОТЕРЯНА. Счётчик — МЕНЬШЕ. Чем должен.
		// И — brute-force — ОБХОДИТСЯ. Потому что tooManyAttempts
		// не срабатывает. Потому что попытки — теряются.
		//
		// [Мириам]: Теперь — flock(LOCK_EX). Эксклюзивная блокировка.
		// Другие процессы ЖДУТ. Пока мы не закончим. И — не теряют.
		// И — не теряются. И — brute-force — НЕ обходится.
		//
		// [Лорелея]: Файл открываем через fopen($file, 'c+').
		// 'c' — создаёт, если нет. 'c+' — ещё и читает. И — НЕ
		// обрезает. В отличие от 'w'. Это важно. Потому что
		// ftruncate() мы делаем ПОСЛЕ чтения. А не до.
		$handle = fopen($file, 'c+');
		if ($handle === false) {
			// Не удалось открыть файл. Странно. Но — не падаем.
			// Просто — логируем. И — выходим.
			error_log("[Guard] Failed to open attempts file: {$file}");
			return;
		}

		// Блокируем. Эксклюзивно. Ждём. Пока не получим.
		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			error_log("[Guard] Failed to lock attempts file: {$file}");
			return;
		}

		// Читаем то, что уже есть
		$content = stream_get_contents($handle);
		$data = empty($content) ? [] : array_map('intval', explode("\n", $content));

		// Добавляем попытку
		$data[] = time();

		// Вычисляем decaySeconds
		if ($decaySeconds === null) {
			$decaySeconds = (int) $this->getConfig('decay_seconds', 120);
		}

		// Фильтруем старые
		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		// ============================================
		// [Мириам]: ПИШЕМ. С ftruncate().
		// ============================================
		// Раньше был file_put_contents(). Он сам обрезает файл.
		// Но — БЕЗ блокировки. Теперь мы внутри LOCK_EX.
		// И — сами обрезаем. Через ftruncate(). И — пишем.
		// И — fflush(). Чтобы точно — на диск. И — flock(LOCK_UN).
		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, implode("\n", $data));
		fflush($handle);

		// Отпускаем блокировку
		flock($handle, LOCK_UN);
		fclose($handle);

		// ============================================
		// [Лорелея]: Probabilistic cleanup. 1%.
		// ============================================
		// Раньше файлы копились ВЕЧНО. Никто их не удалял.
		// Ни clear(), ни cron, ни — ничего. Мусор. Inode. Диск.
		//
		// [Мириам]: Теперь — cleanupAttempts(). С вероятностью 1%.
		// То есть — при 1000 вызовов hit() — 10 cleanup. Достаточно.
		// Потому что hit() вызывается редко. И — файлы не копятся.
		// И — не чистим на каждом запросе. Чтобы не нагружать систему.
		$this->cleanupAttempts();
	}

	/**
	 * Получить количество попыток.
	 */
	private function getAttempts(string $key): int
	{
		$file = $this->getAttemptsFile($key);

		// [Лорелея]: Если файла нет — 0 попыток. Без блокировки.
		// Потому что — читать нечего. И — блокировать нечего.
		if (!file_exists($file)) {
			return 0;
		}

		// [Мириам]: Открываем на чтение. БЕЗ 'c+'. Потому что
		// мы только читаем. И — не создаём. Если файла нет —
		// мы уже проверили. И — вернули 0. Значит — сюда не попадём.
		$handle = fopen($file, 'r');
		if ($handle === false) {
			return 0;
		}

		// [Лорелея]: LOCK_SH — разделяемая блокировка. Читать
		// можно МНОГИМ одновременно. Но — НЕ писать. Потому что
		// писатель ждёт. И — мы не получим битый файл.
		if (!flock($handle, LOCK_SH)) {
			fclose($handle);
			return 0;
		}

		$content = stream_get_contents($handle);
		$data = empty($content) ? [] : array_map('intval', explode("\n", $content));

		flock($handle, LOCK_UN);
		fclose($handle);

		// Фильтруем по decay
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
		if (!file_exists($file)) {
			return;
		}

		// [Лорелея]: Открываем. Блокируем. И — удаляем.
		// Потому что unlink() без блокировки — может удалить
		// файл, который другой процесс только что пишет.
		// И — другой процесс — продолжит писать. В удалённый
		// файл. И — получится мусор. Или — потеря.
		//
		// [Мириам]: ftruncate() вместо unlink(). Потому что
		// unlink() — не атомарен. И — не ждёт. А ftruncate() —
		// внутри LOCK_EX. И — обнуляет файл. И — отпускает.
		// И — файл остаётся. Пустой. И — не мешает.
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

		// [Лорелея]: Теперь — можно и unlink(). Файл — пустой.
		// И — никто в него не пишет. Потому что блокировка —
		// была. И — снята. И — все — ждали. И — получили.
		@unlink($file);
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

		// [Лорелея]: Cookie убрана. Токен передаётся через HTML/JS.
		// Раньше был setcookie с httponly=false. Это XSS-вектор.
		// Теперь — никакой куки. Только сессия. И — HTML.

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

		$rawBody = $request->getContent();
		$body = json_decode($rawBody, true);

		// [Лорелея]: Проверка JSON. Если битый — 400. Не 500.
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ApiResponse::error('Невалидный JSON', 400);
		}

		// [Мириам]: Если не массив — 400. Потому что дальше — $body['...'].
		if (!is_array($body)) {
			return ApiResponse::error('Тело запроса должно быть JSON-объектом', 400);
		}

		$ip = $body['ip'] ?? null;
		$reason = $body['reason'] ?? 'manual_block';
		$duration = (int) ($body['duration'] ?? 3600);

		// [Мириам]: Валидация. Через Validator. И IP. И reason. И duration.
		if (!$this->validator->validate([
			'ip'       => $ip,
			'reason'   => $reason,
			'duration' => $duration,
		], [
			'ip'       => ['required', 'string', 'max:45', 'regex:/^(\d{1,3}\.){3}\d{1,3}$|^[a-f0-9:]+$/iu'],
			'reason'   => ['nullable', 'string', 'max:255'],
			'duration' => ['int', 'min:0', 'max:31536000'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
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

		$rawBody = $request->getContent();
		$body = json_decode($rawBody, true);

		// [Лорелея]: Проверка JSON. Если битый — 400. Не 500.
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ApiResponse::error('Невалидный JSON', 400);
		}

		// [Мириам]: Если не массив — 400. Потому что дальше — $body['...'].
		if (!is_array($body)) {
			return ApiResponse::error('Тело запроса должно быть JSON-объектом', 400);
		}

		$ip = $body['ip'] ?? null;

		// [Лорелея]: Валидация. Через Validator.
		if (!$this->validator->validate(['ip' => $ip], [
			'ip' => ['required', 'string', 'max:45', 'regex:/^(\d{1,3}\.){3}\d{1,3}$|^[a-f0-9:]+$/iu'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
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

	/**
	 * Удаляет устаревшие файлы попыток.
	 *
	 * [Лорелея]: ГЛАВНОЕ ДОБАВЛЕНИЕ. Раньше файлы копились ВЕЧНО.
	 * Никто их не удалял. Ни clear(), ни cron, ни — ничего.
	 * Мусор. Inode. Диск. Тысячи файлов. Мегабайты.
	 *
	 * [Мириам]: Теперь — cleanup. С вероятностью 1%.
	 * Вызывается из hit(). При 1000 вызовов — 10 cleanup.
	 * Достаточно. Потому что hit() вызывается редко.
	 * И — не нагружаем систему. И — файлы не копятся.
	 *
	 * [Лорелея]: Логика простая. Если файл не менялся дольше,
	 * чем decay_seconds — все попытки в нём устарели. Значит —
	 * файл бесполезен. И — можно удалять. Без чтения. Без парсинга.
	 * Только filemtime(). И — unlink().
	 *
	 * [Мириам]: random_int(1, 100) — криптографически стойкий.
	 * Не rand(). Потому что — безопасность. И — привычка.
	 */
	private function cleanupAttempts(): void
	{
		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) {
			return;
		}

		// [Лорелея]: 1% вероятность. Чтобы не нагружать систему.
		// При 1000 вызовов hit() — 10 cleanup. Достаточно.
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
			// [Мириам]: Файл старше decay — удаляем.
			// Если файл не менялся дольше, чем decay — он бесполезен.
			// Все попытки в нём — устарели. Значит — можно удалять.
			if (filemtime($file) < ($now - $decaySeconds)) {
				if (@unlink($file)) {
					$removed++;
				}
			}
		}

		// [Лорелея]: Логируем. Только если что-то удалили.
		// Чтобы в логе было видно. И — чтобы не спамить.
		if ($removed > 0) {
			error_log("[Guard] Cleanup: removed {$removed} stale attempts files");
		}
	}

	/**
	 * Режим отладки из .env.
	 *
	 * [Лорелея]: Тот же метод, что и в других контроллерах.
	 * Локальный. Для единообразия. И — для безопасности.
	 *
	 * @return bool
	 */
	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}