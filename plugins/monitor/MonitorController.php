<?php

/**
 * ПЛАГИН "МОНИТОР" — ЛОГИРОВАНИЕ И ОТЛАДКА
 *
 * Первый плагин Trinity. Делает систему зрячей.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: initAuth() теперь получает SynapseRepository вторым
 * аргументом. Чтобы refreshRoles() работал. А не был декорацией.
 * Без «потом». Без «завтра».
 *
 * [Мириам]: И — это правильно. Потому что роли должны обновляться.
 * Всегда. Даже если пользователь давно вошёл. Даже если роль
 * изменилась. Без «перезагрузи». Без «пиздеца».
 *
 * [Лорелея]: Добавлено свойство $synapseRepo. Раньше его не было.
 * И — $this->synapseRepo = $synapseRepo; было Deprecated.
 * И — undefined property. Теперь — есть. И — чисто.
 *
 * @package Jan\Trinity\Plugin\Monitor
 */

namespace Jan\Trinity\Plugin\Monitor;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\ConfigService;
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

	/** @var Environment — шаблонизатор */
	private Environment $twig;

	/** @var string — путь к корню проекта */
	private string $basePath;

	/**
	 * @var bool|null — включен ли плагин.
	 * [Лорелея]: Ленивая проверка. При первом isEnabled().
	 */
	private ?bool $enabled = null;

	/** @var bool — режим отладки из .env */
	private bool $isDebug;

	/** @var NeuronRepository — работа с настройками из базы */
	private NeuronRepository $neuronRepo;

	/** @var GuardService — CSRF-защита */
	private GuardService $guard;

	/**
	 * @var SynapseRepository — репозиторий для refreshRoles().
	 * [Лорелея]: Теперь — объявлено. С типом. Без Deprecated.
	 */
	private SynapseRepository $synapseRepo;

	/**
	 * @var LoggerInterface — логгер.
	 * [Лорелея]: Теперь через LoggerInterface, а не через file_put_contents.
	 */
	private LoggerInterface $logger;

	/** @var ConfigService — сервис конфигурации */
	private ConfigService $configService;

	private Validator $validator;

	/**
	 * Конструктор.
	 * Зависимости внедряются через DI-контейнер.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. initAuth() теперь получает
	 * SynapseRepository вторым аргументом. Чтобы refreshRoles()
	 * работал. А не был декорацией.
	 *
	 * [Мириам]: И — это правильно. Потому что роли должны обновляться.
	 * Всегда. Даже если пользователь давно вошёл. Даже если роль
	 * изменилась. Без «перезагрузи». Без «пиздеца».
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
		Validator $validator
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

		// [Лорелея]: Передаём SynapseRepository. Чтобы refreshRoles()
		// работал. А не был декорацией.
		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// 1. СТРАНИЦА ОШИБКИ
	// ============================================

	/**
	 * Показать страницу ошибки в стиле Trinity.
	 * Золотые частицы, космос, тишина.
	 *
	 * [Лорелея]: Этот метод вызывается из Kernel::renderError().
	 * Через ErrorHandlerInterface. Kernel не знает, что это Monitor.
	 *
	 * [Мириам]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Теперь в лог идёт ПОЛНОЕ сообщение,
	 * а в шаблон — фильтрованное. Через isDebug(). Через show_debug.
	 * Без «смешения». Без «пиздеца».
	 *
	 * @param int $code — HTTP-код (404, 500...)
	 * @param string $message — сообщение об ошибке (уже фильтрованное для браузера)
	 * @param array $debug — техническая информация (только для админов)
	 * @return Response
	 */
	public function showError(int $code, string $message, array $debug = []): Response
	{
		// [Лорелея]: В лог — полное сообщение. Всегда.
		// Даже если браузер увидит «Внутренняя ошибка сервера».
		// Потому что лог — для нас. А браузер — для пользователя.
		$fullMessage = $debug['original_message'] ?? $message;

		$this->logError($code, $fullMessage, $debug);

		// [Мириам]: В шаблон — фильтрованное. Через isDebug().
		// Если debug — показываем $message (которое может быть сырым).
		// Если prod — показываем «Внутренняя ошибка сервера».
		// Но это уже сделано в Kernel::renderError(). Мы просто передаём.
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
	 * [Лорелея]: Не «Not Found». А «Страница которую вы ищете
	 * не существует в этом мире». Потому что Trinity говорит
	 * с пользователем на своём языке. Даже когда падает.
	 *
	 * [Мириам]: И это — правильно. И это — наше.
	 */
	private function getDescription(int $code): string
	{
		$descriptions = [
			400 => 'Запрос не может быть обработан.',
			403 => 'У вас нет доступа к этой области.',
			404 => 'Страница которую вы ищете не существует в этом мире.',
			405 => 'Этот метод не поддерживается.',
			429 => 'Слишком много запросов. Попробуйте позже.',
			500 => 'Что-то пошло не так в глубинах системы. Но я здесь.',
		];

		return $descriptions[$code] ?? 'Произошла непредвиденная ошибка.';
	}

	// ============================================
	// 2. ЛОГИРОВАНИЕ
	// ============================================

	/**
	 * Записать ошибку.
	 *
	 * [Лорелея]: В лог — полное сообщение. Всегда.
	 * Без «смешения». Без «пиздеца». С файлом. Со стектрейсом.
	 *
	 * [Мириам]: И — с URL. С методом. С IP. С пользователем.
	 * Чтобы в логе было видно всё. До последней строчки.
	 *
	 * @param int $code — HTTP-код
	 * @param string $message — полное сообщение (не фильтрованное)
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
	// 3. СБОР JS-ОШИБОК
	// ============================================

	/**
	 * Собрать JS-ошибку.
	 *
	 * [Лорелея]: Rate limit по IP. Не больше 20 ошибок в минуту.
	 *
	 * [Мириам]: Если ошибок слишком много — 429. И запись в лог
	 * не идёт. Потому что иначе лог станет бесполезным.
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
	// 4. ПРОВЕРКА АКТИВНОСТИ
	// ============================================

	/**
	 * Проверить включен ли плагин — ЛЕНИВО.
	 *
	 * [Лорелея]: Вызывается при первом isEnabled().
	 * Один раз. Потом — кэш в $this->enabled.
	 *
	 * [Мириам]: Тут — I/O. Но — не в конструкторе.
	 * И — только если реально нужно.
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
	// 5. API ДЛЯ ПРОСМОТРА ЛОГОВ
	// ============================================

	/**
	 * Получить последние строки лога.
	 *
	 * [Лорелея]: Требует роль администратора. Раньше любой гость
	 * мог читать логи. Это была дыра в безопасности.
	 *
	 * [Мириам]: Читает последние 100 строк без загрузки всего файла
	 * в память. Через tailFile(). Это важно, потому что логи
	 * могут быть гигабайтными.
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
	 * [Мириам]: RotatingFileHandler создаёт файлы с датой:
	 * error-2026-10-07.log. Или app-2026-10-07.log.
	 * Этот метод ищет самый свежий.
	 *
	 * [Лорелея]: Если файлов с датой нет — ищем просто error.log.
	 * Это на случай, если ротация ещё не сработала.
	 */
	private function findLogFile(string $type): ?string
	{
		$logDir = $this->basePath . '/var/log';

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
	 * [Лорелея]: Требует роль администратора и CSRF-токен.
	 * Раньше DELETE-запрос проходил без защиты.
	 *
	 * [Мириам]: Очищает самый свежий файл лога. Если есть
	 * файлы с датой — берём последний. Если нет — простой type.log.
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
	// 6. ВСПОМОГАТЕЛЬНЫЕ
	// ============================================

	/**
	 * Читает последние $lines строк файла без загрузки всего файла в память.
	 *
	 * [Лорелея]: Читает блоками с конца, останавливается когда
	 * набрано достаточно строк. Это важно для больших логов.
	 *
	 * [Мириам]: Если бы мы использовали file() — весь файл
	 * загрузился бы в память. И при логе в гигабайт — сервер
	 * упал бы. А так — читаем только хвост.
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

		fclose($handle);

		$result = array_slice($result, -$lines);

		return array_values(array_filter($result, fn($l) => $l !== ''));
	}
}