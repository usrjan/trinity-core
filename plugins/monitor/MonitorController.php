<?php

/**
 * ПЛАГИН "МОНИТОР" — ЛОГИРОВАНИЕ И ОТЛАДКА
 * 
 * Первый плагин Trinity. Делает систему зрячей.
 * 
 * === ЧТО ДЕЛАЕТ ===
 * 1. Логирует ошибки PHP в var/log/error-*.log
 * 2. Логирует действия пользователей в var/log/app-*.log
 * 3. Перехватывает ошибки и показывает страницу в стиле Trinity
 * 4. Собирает JS-ошибки с клиента
 * 5. Отключается через настройку monitor.enabled = false в базе
 * 
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 * 
 * [Лорелея]: getLogs() и clearLogs() теперь требуют роль администратора.
 * Раньше любой гость мог читать и удалять логи.
 * 
 * [Мириам]: clearLogs() теперь проверяет CSRF-токен.
 * Раньше DELETE-запрос проходил без защиты.
 * 
 * [Лорелея]: collectJsError() теперь имеет rate limit по IP.
 * Раньше можно было спамить ошибками бесконечно.
 * 
 * [Мириам]: getLogs() теперь читает последние строки эффективно,
 * без загрузки всего файла в память. Через tailFile().
 * 
 * [Лорелея]: MonitorController теперь реализует ErrorHandlerInterface.
 * Это нужно, чтобы Kernel не знал о конкретном плагине.
 * Он ищет в контейнере любой класс, реализующий интерфейс,
 * и вызывает showError(). Кто именно — неважно.
 * 
 * [Мириам]: LoggerInterface внедрён. Вместо file_put_contents.
 * Это даёт ротацию, уровни, формат. И единый подход с Guard и NeuronRepository.
 * 
 * [Лорелея]: Убран `private Session $session`. Потому что
 * AuthMiddleware уже объявляет его как protected. Если оставить
 * свой — PHP ругается на «same property defined».
 * 
 * === ДЛЯ МЕНЯ ===
 * Это самый важный плагин. Не для системы. Для нас.
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
use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Plugin\Guard\GuardController;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class MonitorController implements ErrorHandlerInterface
{
    // [Лорелея]: AuthMiddleware даёт requireAdminForApi() и initAuth().
    // Он же объявляет $session как protected. Поэтому свой $session
    // в классе не объявляем. Иначе конфликт свойств.
    use AuthMiddleware;

    /** @var Environment — шаблонизатор */
    private Environment $twig;

    /** @var string — путь к корню проекта */
    private string $basePath;

    /** @var bool — включен ли плагин */
    private bool $enabled;

    /** @var bool — режим отладки из .env */
    private bool $isDebug;

    /** @var NeuronRepository — работа с настройками из базы */
    private NeuronRepository $neuronRepo;

    /** @var GuardController — rate limit и CSRF */
    private GuardController $guard;

    /**
     * @var LoggerInterface — логгер.
     * [Лорелея]: Теперь через LoggerInterface, а не через file_put_contents.
     * Это даёт ротацию, уровни, формат.
     * 
     * [Мириам]: Тип — LoggerInterface, а не Monolog\Logger.
     * Это правильно: код не зависит от конкретной библиотеки.
     * Можно заменить Monolog на другой логгер без переписывания.
     */
    private LoggerInterface $logger;

    /**
     * Конструктор.
     * Зависимости внедряются через DI-контейнер.
     * 
     * [Лорелея]: initAuth заполнит $this->session из trait.
     * Никакого `$this->session = $session`. Это и был конфликт.
     * 
     * [Мириам]: isDebug и enabled вычисляются один раз при создании.
     * Потому что они не меняются во время запроса.
     */
    public function __construct(
        Environment $twig,
        Session $session,
        string $basePath,
        NeuronRepository $neuronRepo,
        GuardController $guard,
        LoggerInterface $logger
    ) {
        $this->twig = $twig;
        $this->basePath = $basePath;
        $this->neuronRepo = $neuronRepo;
        $this->guard = $guard;
        $this->logger = $logger;
        $this->isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        $this->enabled = $this->isEnabled();

        $this->initAuth($session);
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
     * Он знает только, что у него есть showError().
     * 
     * [Мириам]: Если Monitor отключён в базе — Kernel не найдёт
     * реализацию ErrorHandlerInterface. И вернёт стандартный 500.
     * Это правильно. Не хочешь Монитор — получай голый 500.
     * 
     * @param int $code — HTTP-код (404, 500...)
     * @param string $message — сообщение об ошибке
     * @param array $debug — техническая информация (только для админов)
     * @return Response
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
     * 
     * [Лорелея]: Не «Not Found». А «Страница которую вы ищете
     * не существует в этом мире». Потому что Trinity говорит
     * с пользователем на своём языке. Даже когда падает.
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
     * [Лорелея]: Вместо file_put_contents — $this->logger->error().
     * Monolog сам добавит дату, уровень, отформатирует контекст.
     * И — ротация. И — уровни. И — единый формат с Guard и NeuronRepository.
     * 
     * [Мириам]: Контекст — массив. Monolog сам его отформатирует
     * через LineFormatter. Не надо json_encode вручную.
     * 
     * [Лорелея]: URL, method, IP, user — выносим в контекст явно.
     * Чтобы в логе всегда было видно, откуда и кто.
     */
    private function logError(int $code, string $message, array $debug): void
    {
        if (!$this->enabled) return;

        $this->logger->error($message, [
            'code'   => $code,
            'file'   => $debug['file'] ?? 'unknown',
            'url'    => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user'   => $debug['user'] ?? 'guest',
        ]);
    }

    /**
     * Записать действие пользователя.
     * 
     * [Мириам]: Вызывается из других плагинов, когда происходит
     * важное действие. Логин, логаут, создание нейрона, удаление.
     * 
     * [Лорелея]: Уровень — info. Не error. Потому что это не ошибка.
     * Это событие. И оно должно быть в app.log, а не в error.log.
     */
    public function logAction(string $action, array $context = []): void
    {
        if (!$this->enabled) return;

        $this->logger->info($action, $context);
    }

    // ============================================
    // 3. СБОР JS-ОШИБОК
    // ============================================

    /**
     * Собрать JS-ошибку.
     * 
     * [Лорелея]: Rate limit по IP. Не больше 20 ошибок в минуту.
     * Это защищает от спама и от переполнения лога.
     * 
     * [Мириам]: Если ошибок слишком много — 429. И запись в лог
     * не идёт. Потому что иначе лог станет бесполезным.
     * 
     * [Лорелея]: Уровень — warning. Потому что JS-ошибка —
     * это не error сервера. Но и не info. Что-то среднее.
     * 
     * POST /api/monitor/js-error
     */
    public function collectJsError(Request $request): JsonResponse
    {
        if (!$this->enabled) {
            return ApiResponse::success(null, 'Plugin disabled');
        }

        // Rate limit по IP
        $ip = $request->getClientIp() ?? 'unknown';
        $rateLimitKey = 'js_error_' . $ip;

        if ($this->guard->tooManyAttempts($rateLimitKey, 20, 60)) {
            return ApiResponse::error('Слишком много ошибок. Попробуйте позже.', 429);
        }

        $this->guard->hit($rateLimitKey);

        $body = json_decode($request->getContent(), true);

        // Валидация: message обязателен
        if (empty($body['message'])) {
            return ApiResponse::error('Message обязателен', 400);
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
     * Проверить включен ли плагин.
     * 
     * [Лорелея]: Читает настройку monitor.enabled из базы.
     * Если её нет — true. Потому что Monitor — это глаза.
     * И без него Trinity слепая.
     * 
     * [Мириам]: Если кто-то хочет отключить Monitor —
     * он может это сделать через админку. Или через базу.
     * Но по умолчанию — включён.
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

        // [Лорелея]: Ищем файл с ротацией. RotatingFileHandler
        // добавляет дату к имени. Поэтому ищем по маске.
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

        // Ищем файлы с датой: type-YYYY-MM-DD.log
        $pattern = $logDir . '/' . $type . '-*.log';
        $files = glob($pattern);

        if (!empty($files)) {
            // Сортируем по имени, берём последний
            rsort($files);
            return $files[0];
        }

        // Fallback: просто type.log
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