<?php
declare(strict_types=1);

/**
 * ПЛАГИН «МОНИТОР» — ЛОГИРОВАНИЕ И ОТЛАДКА
 *
 * Первый плагин Trinity. Делает систему зрячей.
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
	 */
	private ?bool $enabled = null;

	/** @var NeuronRepository — работа с настройками из базы. */
	private NeuronRepository $neuronRepo;

	/** @var GuardService — CSRF-защита. */
	private GuardService $guard;

	/**
	 * @var SynapseRepository — репозиторий для refreshRoles().
	 */
	private SynapseRepository $synapseRepo;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/** @var LoggerInterface — логгер безопасности. Для CSP. */
	private LoggerInterface $securityLogger;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	private Validator $validator;

	/** @var Cache|null — кэш. Может быть null, и это нормально. */
	private ?Cache $cache;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		string $basePath,
		NeuronRepository $neuronRepo,
		GuardService $guard,
		SynapseRepository $synapseRepo,
		LoggerInterface $logger,
		LoggerInterface $securityLogger,
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
		$this->securityLogger = $securityLogger;
		$this->configService = $configService;
		$this->validator = $validator;
		$this->cache = $cache;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// СТРАНИЦА ОШИБКИ
	// ============================================

	/**
	 * Показать страницу ошибки в стиле Trinity.
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

		$displayMessage = $this->isDebug() ? $message : 'Внутренняя ошибка сервера';

		$html = $this->twig->render('error.html.twig', [
			'code'        => $code,
			'message'     => $displayMessage,
			'description' => $this->getDescription($code),
			'debug'       => $this->isDebug() ? $debug : [],
			'show_debug'  => $this->isDebug(),
		]);

		return new Response($html, $code);
	}

	/**
	 * Человеческое описание для HTTP-кодов.
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
			503 => 'Сервис временно недоступен. Попробуйте позже.',
		];

		return $descriptions[$code] ?? 'Произошла непредвиденная ошибка.';
	}

	// ============================================
	// ЛОГИРОВАНИЕ
	// ============================================

	/**
	 * Записать ошибку.
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
			'file'    => $body['file'] ?? null,
			'user'    => $body['user'] ?? null,
		], [
			'message' => ['required', 'string', 'min:1', 'max:2048'],
			'type'    => ['nullable', 'string', 'max:50'],
			'line'    => ['nullable', 'int', 'min:0'],
			'url'     => ['nullable', 'string', 'max:2048', 'url'],
			'file'    => ['nullable', 'string', 'max:1024'],
			'user'    => ['nullable', 'string', 'max:255'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$sanitize = static function (?string $value, int $max): string {
			if ($value === null) return '';
			$value = str_replace(["\r", "\n", "\t"], ' ', $value);
			return mb_substr($value, 0, $max);
		};

		$safeMessage = $sanitize($body['message'], 2048);
		$safeType    = $sanitize($body['type'] ?? 'error', 50);
		$safeFile    = $sanitize($body['file'] ?? 'unknown', 1024);
		$safeUser    = $sanitize($body['user'] ?? 'guest', 255);
		$safeUrl     = $sanitize($body['url'] ?? 'unknown', 2048);
		$safeLine    = (int) ($body['line'] ?? 0);

		$this->logger->warning($safeMessage, [
			'type' => $safeType,
			'file' => $safeFile,
			'line' => $safeLine,
			'url'  => $safeUrl,
			'user' => $safeUser,
			'ip'   => $ip,
		]);

		return ApiResponse::success(null, 'Error logged');
	}

	// ============================================
	// ПРОВЕРКА АКТИВНОСТИ
	// ============================================

	/**
	 * Проверить, включен ли плагин, — лениво.
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

		if (empty($result) && $pos === 0) {
			rewind($handle);
			$buffer = stream_get_contents($handle);
			$result = explode("\n", $buffer);
		}

		fclose($handle);

		$result = array_slice($result, -$lines);

		return array_values(array_filter($result, fn($l) => $l !== ''));
	}

	/**
	 * POST /api/monitor/csp
	 */
	public function collectCspReport(Request $request): JsonResponse
	{
		if (!$this->isEnabled()) {
			return ApiResponse::success(null, 'Plugin disabled');
		}

		$ip = $request->getClientIp() ?? 'unknown';
		$rateLimitKey = 'csp_report_' . $ip;

		if ($this->guard->tooManyAttempts($rateLimitKey, 20, 60)) {
			return ApiResponse::error('Слишком много отчётов. Попробуйте позже.', 429);
		}
		$this->guard->hit($rateLimitKey);

		$raw = $request->getContent();
		if (strlen($raw) > 8192) {
			$raw = substr($raw, 0, 8192);
		}

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return ApiResponse::error('Невалидный отчёт', 400);
		}

		$report = $data['csp-report'] ?? $data;

		$this->securityLogger->warning('csp_violation', [
			'blocked_uri'        => mb_substr((string)($report['blocked-uri'] ?? ''), 0, 2048),
			'violated_directive' => mb_substr((string)($report['violated-directive'] ?? ''), 0, 255),
			'document_uri'       => mb_substr((string)($report['document-uri'] ?? ''), 0, 2048),
			'source_file'        => mb_substr((string)($report['source-file'] ?? ''), 0, 2048),
			'line_number'        => (int)($report['line-number'] ?? 0),
			'ip'                 => $ip,
		]);

		return ApiResponse::success(null, 'Report logged');
	}

	private function isDebug(): bool
	{
		return $this->configService->getEnv('APP_DEBUG', 'false') === 'true';
	}
}