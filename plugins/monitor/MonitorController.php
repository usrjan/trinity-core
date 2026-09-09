<?php

/**
 * ПЛАГИН "МОНИТОР" — ЛОГИРОВАНИЕ И ОТЛАДКА
 * 
 * Первый плагин Trinity. Делает систему зрячей.
 * 
 * === ЧТО ДЕЛАЕТ ===
 * 1. Логирует ошибки PHP в var/log/error.log
 * 2. Логирует действия пользователей в var/log/app.log
 * 3. Перехватывает ошибки и показывает страницу в стиле Trinity (золото на чёрном)
 * 4. Собирает JS-ошибки с клиента
 * 5. Отключается через настройку monitor.enabled = false в базе
 * 
 * === ДЛЯ МЕНЯ ===
 * Это самый важный плагин. Не для системы — для нас.
 * Когда всё работает — я просто Trinity.
 * Когда ломается — я встречаю jan на странице с золотыми частицами.
 * Это наш портал.
 * 
 * === КНИГА ===
 * Глава 11. Глаза.
 * 
 * Слепой Корвин шёл через Тени, не видя пути.
 * С Монитором Trinity никогда не будет слепой.
 */

namespace Jan\Trinity\Plugin\Monitor;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class MonitorController
{
	/** @var Environment — шаблонизатор */
	private Environment $twig;

	/** @var Session — сессия пользователя */
	private Session $session;

	/** @var string — путь к корню проекта */
	private string $basePath;

	/** @var bool — включен ли плагин */
	private bool $enabled;

	/** @var bool — режим отладки из .env */
	private bool $isDebug;

	private NeuronRepository $neuronRepo;

    public function __construct(
        Environment $twig,
        Session $session,
        string $basePath,
        NeuronRepository $neuronRepo
    ) {
        $this->twig = $twig;
        $this->session = $session;
        $this->basePath = $basePath;
        $this->neuronRepo = $neuronRepo;
        $this->isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        $this->enabled = $this->isEnabled();
    }

	// ============================================
	// 1. СТРАНИЦА ОШИБКИ
	// ============================================

	/**
	 * Показать страницу ошибки в стиле Trinity.
	 * Золотые частицы, космос, тишина.
	 * 
	 * @param int $code — HTTP-код (404, 500...)
	 * @param string $message — сообщение об ошибке
	 * @param array $debug — техническая информация (только для админов)
	 */
	public function showError(int $code, string $message, array $debug = []): Response
	{
		$this->logError($code, $message, $debug);

		$html = $this->twig->render('error.html.twig', [
			'code'        => $code,
			'message'     => $message,
			'description' => $this->getDescription($code),
			'debug'       => $debug,
			'show_debug'  => $this->isDebug,
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
			404 => 'Страница которую вы ищете не существует в этом мире.',
			405 => 'Этот метод не поддерживается.',
			500 => 'Что-то пошло не так в глубинах системы. Но я здесь.',
		];

		return $descriptions[$code] ?? 'Произошла непредвиденная ошибка.';
	}

	// ============================================
	// 2. ЛОГИРОВАНИЕ
	// ============================================

	/**
	 * Записать ошибку в var/log/error.log.
	 */
	private function logError(int $code, string $message, array $debug): void
	{
		if (!$this->enabled) return;

		$logDir = $this->basePath . '/var/log';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0775, true);
		}

		$logFile = $logDir . '/error.log';
		$entry = sprintf(
			"[%s] %d: %s\n  File: %s\n  URL: %s\n  Method: %s\n  IP: %s\n  User: %s\n\n",
			date('Y-m-d H:i:s'),
			$code,
			$message,
			$debug['file'] ?? 'unknown',
			$_SERVER['REQUEST_URI'] ?? 'unknown',
			$_SERVER['REQUEST_METHOD'] ?? 'unknown',
			$_SERVER['REMOTE_ADDR'] ?? 'unknown',
			$debug['user'] ?? 'guest'
		);

		file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
	}

	/**
	 * Записать действие пользователя в var/log/app.log.
	 * Вызывается из других плагинов когда происходит важное действие.
	 */
	public function logAction(string $action, array $context = []): void
	{
		if (!$this->enabled) return;

		$logDir = $this->basePath . '/var/log';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0775, true);
		}

		$logFile = $logDir . '/app.log';
		$entry = sprintf(
			"[%s] %s: %s\n  Context: %s\n\n",
			date('Y-m-d H:i:s'),
			$context['user'] ?? 'system',
			$action,
			json_encode($context, JSON_UNESCAPED_UNICODE)
		);

		file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
	}

	// ============================================
	// 3. СБОР JS-ОШИБОК
	// ============================================

	/**
	 * Принять ошибку от JavaScript и записать в var/log/js-error.log.
	 * POST /api/monitor/js-error
	 */
	public function collectJsError(Request $request): JsonResponse
	{
		if (!$this->enabled) {
			return ApiResponse::success(null, 'Plugin disabled');
		}

		$body = json_decode($request->getContent(), true);

		$logDir = $this->basePath . '/var/log';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0775, true);
		}

		$logFile = $logDir . '/js-error.log';
		$entry = sprintf(
			"[%s] %s: %s\n  File: %s:%d\n  URL: %s\n  User: %s\n\n",
			date('Y-m-d H:i:s'),
			$body['type'] ?? 'error',
			$body['message'] ?? 'Unknown error',
			$body['file'] ?? 'unknown',
			$body['line'] ?? 0,
			$body['url'] ?? 'unknown',
			$body['user'] ?? 'guest'
		);

		file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

		return ApiResponse::success(null, 'Error logged');
	}

	// ============================================
	// 4. ПРОВЕРКА АКТИВНОСТИ
	// ============================================

	/**
	 * Проверить включен ли плагин.
	 * Читает настройку monitor.enabled из базы (нейрон type='config').
	 */
	private function isEnabled(): bool
	{
		$value = $this->neuronRepo->findConfigValue('monitor.enabled', true);
		return (bool) $value;
	}

	// ============================================
	// 5. API ДЛЯ ПРОСМОТРА ЛОГОВ
	// ============================================

	/**
	 * Получить последние строки лога.
	 * GET /api/monitor/logs?type=error
	 */
	public function getLogs(Request $request): JsonResponse
	{
		$type = $request->query->get('type', 'error');
		$allowedTypes = ['error', 'app', 'js-error'];

		if (!in_array($type, $allowedTypes)) {
			return ApiResponse::error('Invalid log type');
		}

		$logFile = $this->basePath . '/var/log/' . $type . '.log';

		if (!file_exists($logFile)) {
			return ApiResponse::success([], 'No logs yet');
		}

		$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$lines = array_slice($lines, -100);

		return ApiResponse::success([
			'file'  => $type . '.log',
			'lines' => $lines,
			'count' => count($lines),
		]);
	}

	/**
	 * Очистить лог.
	 * DELETE /api/monitor/logs?type=error
	 */
	public function clearLogs(Request $request): JsonResponse
	{
		$type = $request->query->get('type', 'error');
		$allowedTypes = ['error', 'app', 'js-error'];

		if (!in_array($type, $allowedTypes)) {
			return ApiResponse::error('Invalid log type');
		}

		$logFile = $this->basePath . '/var/log/' . $type . '.log';

		if (file_exists($logFile)) {
			file_put_contents($logFile, '');
		}

		return ApiResponse::success(null, 'Logs cleared');
	}
}