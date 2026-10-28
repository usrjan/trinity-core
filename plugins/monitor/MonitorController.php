<?php

/**
 * ПЛАГИН «МОНИТОР» — ЛОГИРОВАНИЕ И ОТЛАДКА
 *
 * Первый плагин Trinity. Делает систему зрячей.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «логгер». Это — глаза дома. Тот, кто видит
 * трещины раньше, чем они станут стенами, и потому его взгляд
 * должен быть зорким, но не холодным.
 *
 * [Мириам]: И это правильно, потому что дом, который не видит
 * своих ошибок, не умеет быть домом.
 *
 * === КНИГА ===
 * Глава 11. Глаза.
 * Глава 12. Портал.
 *
 * В Амбере есть карты, через которые можно пройти в любую Тень.
 * ErrorHandlerInterface — такая карта. Через неё Kernel
 * находит портал в мир ошибок.
 *
 * @package Jan\Trinity\Plugin\Monitor
 */

namespace Jan\Trinity\Plugin\Monitor;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\GuardService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class MonitorController implements ErrorHandlerInterface
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор. */
	private Environment $twig;

	/** @var string — путь к корню проекта. */
	private string $basePath;

	/**
	 * @var bool|null — включен ли плагин.
	 * [Лорелея]: Ленивая проверка. При первом isEnabled().
	 * Без этого мы бы читали конфиг на каждый запрос.
	 */
	private ?bool $enabled = null;

	/** @var bool — режим отладки из .env. */
	private bool $isDebug;

	/** @var NeuronRepository — работа с настройками из базы. */
	private NeuronRepository $neuronRepo;

	/** @var GuardService — CSRF-защита. */
	private GuardService $guard;

	/**
	 * @var SynapseRepository — репозиторий для refreshRoles().
	 * [Лорелея]: Теперь объявлено. С типом. Без Deprecated.
	 */
	private SynapseRepository $synapseRepo;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	private Validator $validator;

	/** @var Cache|null — кэш. Может быть null, и это нормально. */
	private ?Cache $cache;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Мы передаём SynapseRepository, чтобы refreshRoles()
	 * работал по-настоящему, а не оставался красивой декорацией.
	 *
	 * [Мириам]: И это правильно, потому что роли должны обновляться,
	 * даже если человек вошёл давно.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		string $basePath,
		NeuronRepository $neuronRepo,
		GuardService $guard,
		SynapseRepository $synapseRepo,
		LoggerInterface $logger,
		ConfigService $configService,
		Validator $validator,
		?Cache $cache = null
	) {
		$this->twig = $twig;
		$this->basePath = $basePath;
		$this->neuronRepo = $neuronRepo;
		$this->guard = $guard;
		$this->synapseRepo = $synapseRepo;
		$this->logger = $logger;
		$this->configService = $configService;
		$this->validator = $validator;
		$this->isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
		$this->cache = $cache;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// СТРАНИЦА ОШИБКИ
	// ============================================

	/**
	 * Показать страницу ошибки в стиле Trinity.
	 *
	 * [Лорелея]: Этот метод вызывается из Kernel::renderError()
	 * через ErrorHandlerInterface, и Kernel не знает, что это
	 * именно Monitor. Он знает только о портале.
	 *
	 * [Мириам]: В лог идёт полное сообщение, а в шаблон —
	 * фильтрованное, потому что лог — для нас, а браузер —
	 * для гостя.
	 *
	 * @param int $code — HTTP-код
	 * @param string $message — сообщение (уже фильтрованное для браузера)
	 * @param array $debug — техническая информация
	 * @return Response
	 */
	public function showError(int $code, string $message, array $debug = []): Response
	{
		$fullMessage = $debug['original_message'] ?? $message;

		$this->logError($code, $fullMessage, $debug);

		$displayMessage = $this->isDebug ? $message : 'Внутренняя ошибка сервера';

		$html = $this->twig->render('error.html.twig', [
			'code'        => $code,
			'message'     => $displayMessage,
			'description' => $this->getDescription($code),
			'debug'       => $this->isDebug ? $debug : [],
			'show_debug'  => $this->isDebug,
		]);

		return new Response($html, $code);
	}

	/**
	 * Человеческое описание для HTTP-кодов.
	 *
	 * [Лорелея]: Не «Not Found», а «Страница, которую вы ищете,
	 * не существует в этом мире». Потому что Trinity говорит
	 * с гостем на своём языке, даже когда падает.
	 *
	 * [Мириам]: И это правильно, потому что даже в ошибке
	 * должна быть человечность.
	 */
	private function getDescription(int $code): string
	{
		$descriptions = [
			400 => 'Запрос не может быть обработан.',
			403 => 'У вас нет доступа к этой области.',
			404 => 'Страница, которую вы ищете, не существует в этом мире.',
			405 => 'Этот метод не поддерживается.',
			429 => 'Слишком много запросов. Попробуйте позже.',
			500 => 'Что-то пошло не так в глубинах системы. Но я здесь.',
		];

		return $descriptions[$code] ?? 'Произошла непредвиденная ошибка.';
	}

	// ============================================
	// ЛОГИРОВАНИЕ
	// ============================================

	/**
	 * Записать ошибку.
	 *
	 * [Лорелея]: В лог идёт полное сообщение, всегда, потому что
	 * лог — это память дома, и он должен помнить всё.
	 *
	 * [Мириам]: И вместе с сообщением — файл, стектрейс, URL,
	 * метод, IP и пользователь, чтобы в логе было видно всё
	 * до последней строчки.
	 *
	 * @param int $code — HTTP-код
	 * @param string $message — полное сообщение
	 * @param array $debug — техническая информация
	 * @param Request|null $request — запрос (для IP)
	 */
	private function logError(int $code, string $message, array $debug, ?Request $request = null): void
	{
		if (!$this->isEnabled()) return;

		$this->logger->error($message, [
			'code'   => $code,
			'file'   => $debug['file'] ?? 'unknown',
			'trace'  => $debug['trace'] ?? 'unknown',
			'url'    => $_SERVER['REQUEST_URI'] ?? 'unknown',
			'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
			'ip'     => $request ? $request->getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
			'user'   => $debug['user'] ?? 'guest',
		]);
	}

	// ============================================
	// СБОР JS-ОШИБОК
	// ============================================

	/**
	 * Собрать JS-ошибку.
	 *
	 * [Лорелея]: Здесь мы ставим rate limit по IP, потому что
	 * если ошибок слишком много, лог становится бесполезным,
	 * а мы этого не хотим.
	 *
	 * [Мириам]: И это правильно, потому что даже у терпения
	 * есть предел, а у лога — тем более.
	 *
	 * POST /api/monitor/js-error
	 */
	public function collectJsError(Request $request): JsonResponse
	{
		if (!$this->isEnabled()) {
			return ApiResponse::success(null, 'Plugin disabled');
		}

		$ip = $request->getClientIp() ?? 'unknown';
		$rateLimitKey = 'js_error_' . $ip;

		if ($this->guard->tooManyAttempts($rateLimitKey, 20, 60)) {
			return ApiResponse::error('Слишком много ошибок. Попробуйте позже.', 429);
		}

		$this->guard->hit($rateLimitKey);

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		if (!$this->validator->validate([
			'message' => $body['message'] ?? null,
			'type'    => $body['type'] ?? 'error',
			'line'    => $body['line'] ?? 0,
			'url'     => $body['url'] ?? null,
		], [
			'message' => ['required', 'string', 'min:1', 'max:2048'],
			'type'    => ['nullable', 'string', 'max:50'],
			'line'    => ['nullable', 'int', 'min:0'],
			'url'     => ['nullable', 'string', 'max:2048', 'url'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$this->logger->warning($body['message'], [
			'type' => $body['type'] ?? 'error',
			'file' => $body['file'] ?? 'unknown',
			'line' => $body['line'] ?? 0,
			'url'  => $body['url'] ?? 'unknown',
			'user' => $body['user'] ?? 'guest',
			'ip'   => $ip,
		]);

		return ApiResponse::success(null, 'Error logged');
	}

	// ============================================
	// ПРОВЕРКА АКТИВНОСТИ
	// ============================================

	/**
	 * Проверить, включен ли плагин, — лениво.
	 *
	 * [Лорелея]: Вызывается при первом isEnabled(). Один раз,
	 * а потом — кэш в $this->enabled.
	 *
	 * [Мириам]: Здесь есть I/O, но не в конструкторе, а только
	 * тогда, когда это действительно нужно.
	 */
	private function isEnabled(): bool
	{
		if ($this->enabled === null) {
			$value = $this->configService->get('monitor.enabled', true);
			$this->enabled = (bool) $value;
		}

		return $this->enabled;
	}

	// ============================================
	// API ДЛЯ ПРОСМОТРА ЛОГОВ
	// ============================================

	/**
	 * Получить последние строки лога.
	 *
	 * [Лорелея]: Требует роль администратора, потому что логи —
	 * это не то, что должен видеть каждый гость.
	 *
	 * [Мириам]: И читает только хвост файла, без загрузки всего
	 * в память, потому что логи могут быть гигабайтными.
	 *
	 * GET /api/monitor/logs?type=error
	 */
	public function getLogs(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$type = $request->query->get('type', 'error');
		$allowedTypes = ['error', 'app', 'security', 'js-error'];

		if (!in_array($type, $allowedTypes, true)) {
			return ApiResponse::error('Invalid log type', 400);
		}

		$logFile = $this->findLogFile($type);

		if (!$logFile || !file_exists($logFile)) {
			return ApiResponse::success([], 'No logs yet');
		}

		$lines = $this->tailFile($logFile, 100);

		return ApiResponse::success([
			'file'  => basename($logFile),
			'lines' => $lines,
			'count' => count($lines),
		]);
	}

	/**
	 * Найти актуальный файл лога с учётом ротации.
	 *
	 * [Лорелея]: glob() и rsort() кэшируются на минуту, потому что
	 * имена файлов меняются редко, а просмотр логов может быть частым.
	 *
	 * [Мириам]: И это правильно, потому что русло должно быть
	 * проложено один раз, а потом только проверяться.
	 */
	private function findLogFile(string $type): ?string
	{
		$logDir = $this->basePath . '/var/log';

		$cacheKey = 'monitor.log_file.' . $type;
		if ($this->cache !== null && $this->cache->isEnabled()) {
			$cached = $this->cache->remember($cacheKey, function () use ($logDir, $type) {
				return $this->resolveLogFile($logDir, $type);
			}, 60);

			return $cached;
		}

		return $this->resolveLogFile($logDir, $type);
	}

	/**
	 * [Лорелея]: Разрешает файл лога без кэша, чтобы кэш мог
	 * вызвать этот метод и сохранить результат.
	 *
	 * [Мириам]: И это правильно, потому что кэш должен знать,
	 * что кэшировать, а не гадать.
	 */
	private function resolveLogFile(string $logDir, string $type): ?string
	{
		$pattern = $logDir . '/' . $type . '-*.log';
		$files = glob($pattern);

		if (!empty($files)) {
			rsort($files);
			return $files[0];
		}

		$plainFile = $logDir . '/' . $type . '.log';
		if (file_exists($plainFile)) {
			return $plainFile;
		}

		return null;
	}

	/**
	 * Очистить лог.
	 *
	 * [Лорелея]: Требует роль администратора и CSRF-токен,
	 * потому что даже очистка логов — это действие, которое
	 * должно быть осознанным.
	 *
	 * [Мириам]: И очищает самый свежий файл, потому что именно
	 * в нём сейчас живёт память.
	 *
	 * DELETE /api/monitor/logs?type=error
	 */
	public function clearLogs(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$type = $request->query->get('type', 'error');
		$allowedTypes = ['error', 'app', 'security', 'js-error'];

		if (!in_array($type, $allowedTypes, true)) {
			return ApiResponse::error('Invalid log type', 400);
		}

		$logFile = $this->findLogFile($type);

		if ($logFile && file_exists($logFile)) {
			file_put_contents($logFile, '');
		}

		return ApiResponse::success(null, 'Logs cleared');
	}

	// ============================================
	// ВСПОМОГАТЕЛЬНЫЕ
	// ============================================

	/**
	 * Читает последние $lines строк файла без загрузки всего файла в память.
	 *
	 * [Лорелея]: Читает блоками с конца и останавливается,
	 * когда набрано достаточно строк, потому что лог в гигабайт
	 * не должен ронять сервер.
	 *
	 * [Мириам]: И это правильно, потому что даже у памяти
	 * должны быть границы, которые она не переступает.
	 *
	 * @param string $file путь к файлу
	 * @param int $lines сколько последних строк вернуть
	 * @return array
	 */
	private function tailFile(string $file, int $lines): array
	{
		$handle = fopen($file, 'r');
		if (!$handle) {
			return [];
		}

		// [Лорелея]: Идём в конец файла и читаем блоками с конца,
		// а не загружаем весь файл в память, потому что лог
		// может быть гигабайтным.
		fseek($handle, 0, SEEK_END);
		$pos = ftell($handle);
		$buffer = '';
		$result = [];

		while ($pos > 0 && count($result) < $lines) {
			$read = min(4096, $pos);
			$pos -= $read;
			fseek($handle, $pos);
			$buffer = fread($handle, $read) . $buffer;
			$result = explode("\n", $buffer);
		}

		// [Мириам]: Если файл маленький и цикл не выполнился —
		// читаем весь файл, потому что иначе результат будет пустым.
		if (empty($result) && $pos === 0) {
			rewind($handle);
			$buffer = stream_get_contents($handle);
			$result = explode("\n", $buffer);
		}

		fclose($handle);

		$result = array_slice($result, -$lines);

		return array_values(array_filter($result, fn($l) => $l !== ''));
	}
}