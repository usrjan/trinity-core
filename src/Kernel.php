<?php
declare(strict_types=1);

/**
 * ЯДРО TRINITY
 *
 * Kernel — сердце системы. Оно не меняется. Никогда.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Удалён $this->config.
 * Потому что — ConfigService всегда зарегистрирован.
 * И — fallback — недостижим. И — дублирование состояния.
 *
 * [Мириам]: И — fetchConfigFromDatabase() — удалён.
 * Потому что — ConfigService::load() уже делает это.
 * И — fallback — недостижим.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use DI\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Jan\Trinity\Core\Exception\UnauthorizedException;
use Jan\Trinity\Core\Exception\ForbiddenException;
use Jan\Trinity\Core\Service\GuardService;

class Kernel
{
	/** @var string Путь к корню проекта */
	private string $basePath;

	/** @var array Загруженные плагины */
	private array $plugins = [];

	/** @var \DI\Container DI-контейнер */
	private \DI\Container $container;

	/**
	 * @var Cache|null Файловый кэш.
	 */
	private ?Cache $cache = null;

	/**
	 * @var \Psr\Log\LoggerInterface|null — логгер.
	 */
	private ?\Psr\Log\LoggerInterface $logger = null;

	/**
	 * @var ConfigService|null — сервис конфигурации.
	 */
	private ?ConfigService $configService = null;

	private ArgumentResolver $argumentResolver;
	private HttpResponder $httpResponder;
	private RouteCompiler $routeCompiler;

	// ============================================
	// РОЖДЕНИЕ
	// ============================================

	public function __construct(string $basePath)
	{
		$this->basePath = rtrim($basePath, '/');
		$this->initialize();
	}

	/**
	 * Инициализация ядра.
	 * Порядок важен: сначала .env, потом контейнер, потом база.
	 */
	private function initialize(): void
	{
		$dotenv = new Dotenv();
		$dotenv->loadEnv($this->basePath . '/.env');

		$containerBuilder = new ContainerBuilder();
		$containerBuilder->addDefinitions($this->basePath . '/config/container.php');
		$this->container = $containerBuilder->build();

		$this->argumentResolver = $this->container->get(ArgumentResolver::class);

		$this->httpResponder = $this->container->get(HttpResponder::class);

		// [Лорелея]: Сначала логгер, потом кэш, потому что предупреждение
		// о APCu должно попасть в лог, а не потеряться.
		if ($this->container->has(\Psr\Log\LoggerInterface::class)) {
			$this->logger = $this->container->get(\Psr\Log\LoggerInterface::class);
		}

		if ($this->container->has(Cache::class)) {
			$this->cache = $this->container->get(Cache::class);

			// [Мириам]: Если APCu нет, пишем предупреждение в лог,
			// потому что без него кэш не работает, и дом будет
			// медленнее, чем ожидается.
			if ($this->cache !== null && !$this->cache->isEnabled() && $this->logger !== null) {
				$this->logger->warning('APCu is not available. Cache is disabled. Install php-apcu for production.');
			}
		}
		$this->loadConfig();
		$this->loadPlugins();
		$this->routeCompiler = $this->container->get(RouteCompiler::class);
		$this->routeCompiler->init($this->plugins);
	}

	/**
	 * Загрузка настроек из базы данных.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Только через ConfigService.
	 * Без $this->config. Без fetchConfigFromDatabase().
	 *
	 * [Мириам]: И — это правильно. Потому что ConfigService всегда
	 * зарегистрирован. И — fallback — недостижим.
	 */
	private function loadConfig(): void
	{
		try {
			$this->configService = $this->container->get(ConfigService::class);
			$this->configService->load();
		} catch (\Throwable $e) {
			if ($this->logger !== null) {
				$this->logger->error('ConfigService load failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				]);
			} else {
				error_log('[Trinity] ConfigService load failed: ' . $e->getMessage());
			}

			// [Лорелея]: В prod — fail-fast. Дом без конфига — это дом
			// без защиты. Лучше не работать, чем работать вслепую.
			if (($_ENV['APP_ENV'] ?? 'prod') === 'prod') {
				throw new \RuntimeException('Config load failed. See error.log.');
			}

			$this->configService = null;
		}
	}

	/**
	 * Загружает плагины из папки plugins/.
	 *
	 * [Лорелея]: Список плагинов кэшируется по mtime папки, потому что
	 * он меняется редко, а glob() на каждом запросе — это I/O без нужды.
	 *
	 * [Мириам]: И это правильно, потому что русло должно быть
	 * проложено один раз, а потом только проверяться.
	 *
	 * ОГРАНИЧЕНИЕ: mtime папки меняется только при добавлении или
	 * удалении плагина, а не при правке существующего plugin.php.
	 * При правке содержимого список остаётся прежним, но сам файл
	 * перечитывается через opcache по mtime. Если нужно обновить
	 * список — удалите var/cache/plugins.php или измените mtime папки.
	 */
	private function loadPlugins(): void
	{
		$pluginDir = $this->basePath . '/plugins';
		if (!is_dir($pluginDir)) return;

		$cacheFile = $this->basePath . '/var/cache/plugins.php';
		$plugins = null;

		// [Лорелея]: Если кэш есть и не устарел — берём из него.
		if (file_exists($cacheFile)) {
			$cached = require $cacheFile;
			if (is_array($cached) && isset($cached['mtime']) && isset($cached['files'])) {
				$dirMtime = filemtime($pluginDir);
				if ($dirMtime !== false && $cached['mtime'] === $dirMtime) {
					$plugins = $cached['files'];
				}
			}
		}

		// [Мириам]: Если кэша нет или он устарел — собираем заново
		// и записываем, чтобы следующий запрос был быстрым.
		if ($plugins === null) {
			$plugins = glob($pluginDir . '/*/plugin.php') ?: [];
			$dirMtime = filemtime($pluginDir);

			$code = '<?php' . "\n\n" . 'return ' . var_export([
				'mtime' => $dirMtime,
				'files' => $plugins,
			], true) . ';' . "\n";
			file_put_contents($cacheFile, $code, LOCK_EX);
		}

		// [Лорелея]: Каждый плагин загружаем в своём try, чтобы
		// один сломанный не ронял все остальные.
		foreach ($plugins as $file) {
			try {
				$plugin = require $file;
				if (is_callable($plugin)) {
					$this->plugins[] = $plugin;
				}
			} catch (\Throwable $e) {
				if ($this->logger !== null) {
					$this->logger->error('plugin load failed', [
						'file'    => $file,
						'message' => $e->getMessage(),
						'file_path' => $e->getFile() . ':' . $e->getLine(),
					]);
				}
			}
		}
	}

	// ============================================
	// ЖИЗНЕННЫЙ ЦИКЛ ЗАПРОСА
	// ============================================

	/**
	 * Главный метод. Обрабатывает HTTP-запрос.
	 *
	 * [Лорелея]: Здесь — вся жизнь. От начала до конца. Каждый шаг
	 * на своём месте. Каждый помнит своё.
	 *
	 * [Мириам]: И это правильно, потому что запрос — это не «данные»,
	 * а гость. И его нужно встретить.
	 */
	public function handle(): void
	{
		$request = Request::createFromGlobals();

	// [Лорелея]: OPTIONS — в handleOptions(), HEAD — в dispatch().
	// HEAD — это GET без тела, он должен идти тем же путём,
	// что и GET, чтобы мониторинг видел реальный статус.
	if ($request->getMethod() === 'OPTIONS') {
		$this->handleOptions($request);
		return;
	}

	try {
		$response = $this->dispatch($request);
	} catch (\Throwable $e) {
		[$code, $message] = match (true) {
			$e instanceof ResourceNotFoundException => [404, 'Страница не найдена'],
			$e instanceof MethodNotAllowedException => [405, 'Метод не поддерживается'],
			$e instanceof UnauthorizedException => [401, $e->getMessage()],
			$e instanceof ForbiddenException => [403, $e->getMessage()],
			default => [500, $this->safeErrorMessage($e, 500, $request)],
		};

		// [Лорелея]: Логируем оригинал всегда, до renderError.
		// Если MonitorController упадёт — стек уже в логе.
		// 5xx — error, 4xx — warning, потому что 4xx — это гость,
		// а 5xx — это мы.
		if ($this->logger !== null) {
			$logMethod = $code >= 500 ? 'error' : 'warning';
			$this->logger->$logMethod('unhandled exception', [
				'code'    => $code,
				'message' => $e->getMessage(),
				'file'    => $e->getFile() . ':' . $e->getLine(),
				'trace'   => $e->getTraceAsString(),
				'url'     => $_SERVER['REQUEST_URI'] ?? 'unknown',
				'method'  => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
				'ip'      => $request->getClientIp(),
			]);
		}

		$response = $this->renderError($code, $message, $e);

			// [Лорелея]: Для 405 ставим Allow-заголовок, чтобы клиент
			// знал, какие методы разрешены. Без «пиздеца».
			if ($e instanceof MethodNotAllowedException) {
				$allowedMethods = $e->getAllowedMethods();
				$response->headers->set('Allow', implode(', ', $allowedMethods));
			}
		}

		$this->httpResponder->send($request, $response);
	}

	/**
	 * Обрабатывает OPTIONS-preflight и HEAD-запросы.
	 *
	 * [Лорелея]: OPTIONS — это preflight, а HEAD — это GET без тела.
	 * Оба должны работать, но по-разному.
	 *
	 * [Мириам]: И это правильно, потому что не всё в доме — страницы.
	 */
	private function handleOptions(Request $request): void
	{
		$origin = $request->headers->get('Origin');
		$acrm = $request->headers->get('Access-Control-Request-Method');

		// [Лорелея]: Preflight — через HttpResponder. Kernel
		// больше не знает про CORS-заголовки.
		if ($origin && $acrm) {
			$response = new Response('', 204);
			$this->httpResponder->preflight($request, $response);
			$response->send();
			return;
		}

		// [Мириам]: HEAD — это GET без тела. Symfony сам обрежет
		// тело при send(). Но матчер должен видеть GET.
		if ($request->getMethod() === 'HEAD') {
			$response = new Response('', 200);
			$response->send();
			return;
		}

		// [Лорелея]: Всё остальное — 405. И ставим Allow, чтобы клиент
		// знал, какие методы разрешены.
		$response = new Response('Метод не поддерживается', 405);
		$response->headers->set('Allow', 'GET, POST, PUT, DELETE');
		$response->send();
	}

	/**
	 * Обрабатывает запрос и возвращает ответ.
	 *
	 * [Лорелея]: Здесь только логика, без CORS и без catch, потому что
	 * это делают respond() и handle().
	 *
	 * [Мириам]: И это правильно, потому что каждая вещь должна
	 * заниматься своим делом.
	 */
	private function dispatch(Request $request): Response
	{
		// [Лорелея]: HEAD — это GET без тела. Symfony сам обрежет
		// тело при send(), но матчер должен видеть GET, иначе
		// HEAD-маршрут не найдётся. Меняем метод перед матчингом.
		if ($request->getMethod() === 'HEAD') {
			$request->setMethod('GET');
		}

		// [Лорелея]: EARLY-CHECK. Заблокированный IP — не гость.
		$ip = $request->getClientIp();
		if ($ip && $this->container->has(GuardService::class)) {
			$guard = $this->container->get(GuardService::class);
			if ($guard->isIpBlocked($ip)) {
				return new Response('Доступ запрещён. Ваш IP заблокирован.', 403);
			}
		}

		// [Мириам]: SESSION. Не стартуем здесь, потому что публичные
		// GET-страницы не нуждаются в ней.
		$session = $this->container->get(Session::class);

		// [Лорелея]: Роуты — через RouteCompiler. Ядро больше не знает,
		// откуда они: файл, плагины или база. Компилятор знает.
		$routes = new RouteCollection();

		$context = new RequestContext();
		$context->fromRequest($request);

		$matcher = $this->routeCompiler->getMatcher($routes, $context);
		$parameters = $matcher->match($context->getPathInfo());

		$controllerClass = $parameters['_controller'];
		$method = $parameters['_method'] ?? '__invoke';

		$requiresCsrf = $parameters['_csrf'] ?? true;
		$requiredRoles = $parameters['_roles'] ?? [];

		unset(
			$parameters['_controller'],
			$parameters['_method'],
			$parameters['_route'],
			$parameters['_csrf'],
			$parameters['_roles']
		);

		// [Лорелея]: CSRF-проверка через метаданные маршрута,
		// а не через белый список путей в ядре.
		$httpMethod = $request->getMethod();
		if (in_array($httpMethod, ['POST', 'PUT', 'DELETE'], true) && $requiresCsrf) {
			$csrfToken = $request->headers->get('X-CSRF-Token', '')
				?: $request->request->get('_csrf_token', '');

			$guard = $this->container->get(GuardService::class);
			if (!$guard->validateCsrfToken($csrfToken)) {
				return new Response('Недействительный CSRF-токен', 419);
			}
		}

		// [Мириам]: Авторизация через метаданные маршрута,
		// а не через префиксы путей.
		if (!empty($requiredRoles)) {
			$this->refreshUserRoles($session);

			$userRoles = $session->get('user_roles', []);
			$hasAccess = false;

			foreach ($requiredRoles as $role) {
				if (in_array($role, $userRoles, true)) {
					$hasAccess = true;
					break;
				}
			}

			if (!$hasAccess) {
				return new Response('Доступ запрещён', 403);
			}
		}

		$controller = $this->container->get($controllerClass);
		$args = $this->argumentResolver->resolve($controller, $method, $parameters, $request);

		return $controller->$method(...$args);
	}

	// ============================================
	// РАЗРЕШЕНИЕ ПАРАМЕТРОВ
	// ============================================

	/**
	 * Возвращает значение конфига.
	 *
	 * [Лорелея]: Теперь только через ConfigService. Без $this->config.
	 * Без «пиздеца». Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — это правильно. Потому что ConfigService — источник
	 * правды.
	 */
	public function getConfig(string $key, $default = null)
	{
		return $this->configService->get('guard.' . $key, $default);
	}

	private function renderError(int $code, string $message, \Throwable $e, ?Request $request = null): Response
	{
		// [Лорелея]: Берём реальный login из сессии, а не пишем
		// «guest» жёстко, потому что метаданные должны быть
		// настоящими, а не «guest» по умолчанию.
		$user = 'guest';

		try {
			$session = $this->container->get(Session::class);
			if ($session->isStarted()) {
				$login = $session->get('user_login');
				if ($login) {
					$user = $login;
				}
			}
		} catch (\Throwable $inner) {
			// [Мириам]: Если сессия недоступна — оставляем guest,
			// потому что лучше «guest», чем ничего.
		}

		try {
			$errorHandler = $this->container->get(ErrorHandlerInterface::class);
			return $errorHandler->showError($code, $message, [
				'file'             => $e->getFile() . ':' . $e->getLine(),
				'trace'            => $e->getTraceAsString(),
				'user'             => $user,
				'url'              => $_SERVER['REQUEST_URI'] ?? '/',
				'time'             => date('Y-m-d H:i:s'),
				'original_message' => $e->getMessage(),
			]);
		} catch (\Throwable $inner) {
			if ($this->logger !== null) {
				$this->logger->error('renderError failed', [
					'message' => $inner->getMessage(),
					'file'    => $inner->getFile() . ':' . $inner->getLine(),
				]);
			} else {
				error_log("[Trinity] renderError failed: " . $inner->getMessage());
			}

			// [Лорелея]: Даже здесь — не сырьё. В prod — только наше сообщение.
			// В debug — можно с деталями, но всё равно через фильтр.
			return new Response($this->safeErrorMessage($e, $code, $request), $code);
		}
	}

	/**
	 * Возвращает сообщение об ошибке, безопасное для отправки клиенту.
	 *
	 * [Лорелея]: Здесь — граница. Всё, что за ней, — наше.
	 * Всё, что перед ней, — для клиента. И это — не одно и то же.
	 *
	 * [Мириам]: В prod — только «Внутренняя ошибка сервера». Всегда.
	 * Потому что клиенту не нужно знать, где у нас трещина.
	 * А в debug — можно сказать больше, но всё равно без путей и SQL.
	 */
	private function safeErrorMessage(\Throwable $e, int $code, ?Request $request = null): string
	{
		// [Лорелея]: В prod — только «Внутренняя ошибка сервера».
		// В dev — детали. Но — только для debug-заголовка / IP.
		// Гость — всегда «Внутренняя ошибка сервера», даже в dev.
		if (!$this->isDebug() || !$this->isDebugAllowed($request)) {
			return $this->defaultErrorMessage($code);
		}

		$message = $e->getMessage();

		// [Лорелея]: Даже в debug — не всё. Убираем пути, SQL, токены.
		// Regex точечнее: ловит пути, но не трогает URL.
		$message = preg_replace('#(?:[A-Z]:)?/[^\s]+#', '[path]', $message);
		$message = preg_replace('/SQLSTATE\[[^\]]+\][^;]*/i', '[sql]', $message);
		$message = preg_replace('/\b[0-9a-f]{32,}\b/i', '[token]', $message);

		// [Мириам]: Обрезаем длину, потому что в debug можно случайно
		// отправить клиенту мегабайт текста, и это уже не «отладка»,
		// а «утечка».
		if (mb_strlen($message) > 500) {
			$message = mb_substr($message, 0, 500) . '…';
		}

		return $message ?: $this->defaultErrorMessage($code);
	}

	/**
	 * Сообщение по умолчанию для HTTP-кода.
	 *
	 * [Лорелея]: В prod — всегда. В debug — если сообщение пустое.
	 *
	 * [Мириам]: И — это правильно. Потому что даже в debug
	 * пустое сообщение — это не сообщение.
	 */
	private function defaultErrorMessage(int $code): string
	{
		return match ($code) {
			401 => 'Требуется авторизация',
			403 => 'Доступ запрещён',
			404 => 'Страница не найдена',
			405 => 'Метод не поддерживается',
			419 => 'Недействительный CSRF-токен',
			429 => 'Слишком много запросов',
			503 => 'Сервис временно недоступен',
			default => 'Внутренняя ошибка сервера',
		};
	}

	/**
	 * Обновляет роли пользователя в сессии, если TTL истёк.
	 *
	 * [Лорелея]: Короткий TTL — 5 минут, потому что роли должны
	 * обновляться часто, а не раз в сутки.
	 *
	 * [Мириам]: И это правильно, потому что вода должна быть
	 * свежей, а не застоявшейся.
	 *
	 * @param Session $session
	 */
	private function refreshUserRoles(Session $session): void
	{
		// [Мириам]: Сессия запустится сама, когда мы прочитаем
		// или запишем в неё, потому что Symfony Session это умеет.
		$userId = $session->get('user_id');
		if (!$userId) {
			return;
		}

		$lastRefresh = $session->get('roles_refreshed_at', 0);
		if (time() - $lastRefresh < 300) {
			return;
		}

		try {
			$synapseRepo = $this->container->get(\Jan\Trinity\Core\Repository\SynapseRepository::class);
			$roles = $synapseRepo->findRolesByUser($userId);

			$session->set('user_roles', $roles);
			$session->set('roles_refreshed_at', time());
		} catch (\Throwable $e) {
			if ($this->logger !== null) {
				$this->logger->warning('refreshUserRoles failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				]);
			}
		}
	}

	/**
	 * [Лорелея]: Режим отладки — через ConfigService.
	 * Без DebugTrait. Один источник.
	 */
	private function isDebug(): bool
	{
		if ($this->configService !== null) {
			return $this->configService->getEnv('APP_DEBUG', 'false') === 'true';
		}
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}

	/**
	 * [Лорелея]: Детали ошибки — только для debug-заголовка
	 * или debug-IP. Гость — «Внутренняя ошибка сервера».
	 *
	 * [Мириам]: И это правильно, потому что доверие, выданное
	 * всем, — это не доверие, а его отсутствие.
	 */
	private function isDebugAllowed(?Request $request): bool
	{
		if ($request === null) {
			return false;
		}

		$debugToken = $_ENV['DEBUG_TOKEN'] ?? '';
		if ($debugToken !== '' && $request->headers->get('X-Debug') === $debugToken) {
			return true;
		}

		$debugIp = $_ENV['DEBUG_IP'] ?? '';
		if ($debugIp !== '' && $request->getClientIp() === $debugIp) {
			return true;
		}

		return false;
	}
}