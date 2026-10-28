<?php

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
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Jan\Trinity\Core\Exception\UnauthorizedException;
use Jan\Trinity\Core\Exception\ForbiddenException;
use Jan\Trinity\Core\Service\GuardService;
use Jan\Trinity\Core\Trait\DebugTrait;

class Kernel
{
	use DebugTrait;

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

		if ($this->container->has(Cache::class)) {
			$this->cache = $this->container->get(Cache::class);
		}

		if ($this->container->has(\Psr\Log\LoggerInterface::class)) {
			$this->logger = $this->container->get(\Psr\Log\LoggerInterface::class);
		}

		$this->loadConfig();
		$this->loadPlugins();
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
			// [Лорелея]: Если ConfigService нет — ничего не делаем.
			// Потому что — это — «пиздец». Но — не падаем.
			$this->configService = null;
		}
	}

	/**
	 * Загружает плагины из папки plugins/.
	 *
	 * [Лорелея]: Список плагинов кэшируется, потому что он меняется
	 * редко, а glob() на каждом запросе — это I/O без нужды.
	 *
	 * [Мириам]: И это правильно, потому что русло должно быть
	 * проложено один раз, а потом только проверяться.
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
	 */
	public function handle(): void
	{
		$request = Request::createFromGlobals();

		// CORS PREFLIGHT (OPTIONS)
		if ($request->getMethod() === 'OPTIONS') {
			$origin = $request->headers->get('Origin');
			$acrm = $request->headers->get('Access-Control-Request-Method');

			if ($origin && $acrm) {
				$response = new Response('', 204);
				$this->applyCorsHeaders($request, $response);
				$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
				$response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-Token');
				$response->headers->set('Access-Control-Max-Age', '86400');
				$response->send();
				return;
			}

			$response = new Response('Метод не поддерживается', 405);
			$response->headers->set('Allow', 'GET, POST, PUT, DELETE');
			$response->send();
			return;
		}

		try {
			// EARLY-CHECK: ЗАБЛОКИРОВАННЫЙ IP
			$ip = $request->getClientIp();
			if ($ip && $this->container->has(GuardService::class)) {
				$guard = $this->container->get(GuardService::class);
				if ($guard->isIpBlocked($ip)) {
					$response = new Response('Доступ запрещён. Ваш IP заблокирован.', 403);
					$this->applyCorsHeaders($request, $response);
					$response->send();
					return;
				}
			}

			// START SESSION — BEFORE CONTROLLER
			$session = $this->container->get(Session::class);
			if (!$session->isStarted()) {
				$session->start();
			}

			// [Лорелея]: Файловые роуты. Один маршрут — home.
			$routes = require $this->basePath . '/config/routes.php';

			// [Мириам]: Плагины добавляют свои роуты. Каждый — сам.
			// И каждый — в своём try, чтобы один сломанный не ронял все.
			foreach ($this->plugins as $plugin) {
				try {
					$plugin($routes);
				} catch (\Throwable $e) {
					if ($this->logger !== null) {
						$this->logger->error('plugin route registration failed', [
							'message' => $e->getMessage(),
							'file'    => $e->getFile() . ':' . $e->getLine(),
						]);
					}
				}
			}

			$this->loadRoutesFromDatabase($routes);

			$context = new RequestContext();
			$context->fromRequest($request);
			$matcher = $this->getMatcher();
			$parameters = $matcher->match($context->getPathInfo());

			$controllerClass = $parameters['_controller'];
			$method = $parameters['_method'] ?? '__invoke';

			// [Лорелея]: Читаем метаданные до unset, потому что
			// после unset их уже нет, и проверки становятся пустыми.
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
			// а не через белый список путей в ядре, потому что
			// ядро не должно знать о конкретных плагинах.
			$httpMethod = $request->getMethod();

			if (in_array($httpMethod, ['POST', 'PUT', 'DELETE'], true) && $requiresCsrf) {
				$csrfToken = $request->headers->get('X-CSRF-Token', '')
					?: $request->request->get('_csrf_token', '');

				$guard = $this->container->get(GuardService::class);
				if (!$guard->validateCsrfToken($csrfToken)) {
					$response = new Response('Недействительный CSRF-токен', 419);
					$this->applyCorsHeaders($request, $response);
					$response->send();
					return;
				}
			}

			// [Лорелея]: Авторизация через метаданные маршрута,
			// а не через префиксы путей, потому что ядро не должно
			// знать о конкретных плагинах.
			if (!empty($requiredRoles)) {
				$userRoles = $session->get('user_roles', []);
				$hasAccess = false;

				foreach ($requiredRoles as $role) {
					if (in_array($role, $userRoles, true)) {
						$hasAccess = true;
						break;
					}
				}

				if (!$hasAccess) {
					$response = new Response('Доступ запрещён', 403);
					$this->applyCorsHeaders($request, $response);
					$response->send();
					return;
				}
			}

			$controller = $this->container->get($controllerClass);

			$args = $this->resolveArguments($controller, $method, $parameters, $request);

			$response = $controller->$method(...$args);

			$this->applyCorsHeaders($request, $response);
			$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
			$response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-Token');

		} catch (ResourceNotFoundException $e) {
			$response = $this->renderError(404, 'Страница не найдена', $e);
			$this->applyCorsHeaders($request, $response);

		} catch (MethodNotAllowedException $e) {
			$response = $this->renderError(405, 'Метод не поддерживается', $e);
			$this->applyCorsHeaders($request, $response);

		} catch (UnauthorizedException $e) {
			$response = $this->renderError(401, $e->getMessage(), $e);
			$this->applyCorsHeaders($request, $response);

		} catch (ForbiddenException $e) {
			$response = $this->renderError(403, $e->getMessage(), $e);
			$this->applyCorsHeaders($request, $response);

		} catch (\Throwable $e) {
			$response = $this->renderError(500, $this->safeErrorMessage($e, 500), $e);
			$this->applyCorsHeaders($request, $response);
		}

		if ($response instanceof Response) {
			$response->send();
		} else {
			echo (string) $response;
		}
	}

	/**
	 * Ставит CORS-заголовки на основе whitelist из .env.
	 *
	 * [Лорелея]: Если в whitelist стоит `*`, мы не ставим
	 * credentials, потому что `*` и credentials вместе
	 * не работают, и делать вид, что работают, — это обман.
	 *
	 * [Мириам]: И это правильно, потому что доверие, выданное
	 * всем, — это не доверие, а его отсутствие.
	 */
	private function applyCorsHeaders(Request $request, Response $response): void
	{
		$allowed = $this->getConfig('cors.allowed_origins', '');
		if (empty($allowed)) {
			return;
		}

		$origin = $request->headers->get('Origin');

		if (!$origin) {
			return;
		}

		$allowedOrigins = array_map('trim', explode(',', $allowed));

		// [Лорелея]: `*` — это открытая дверь. Credentials при этом
		// не ставим, потому что браузер такую комбинацию отвергает,
		// и мы не будем делать вид, что всё в порядке.
		if (in_array('*', $allowedOrigins, true)) {
			$response->headers->set('Access-Control-Allow-Origin', '*');
			return;
		}

		// [Мириам]: Точный origin из списка — это уже доверие,
		// и только здесь можно ставить credentials, потому что
		// браузер поймёт, что мы говорим с ним честно.
		if (in_array($origin, $allowedOrigins, true)) {
			$response->headers->set('Access-Control-Allow-Origin', $origin);
			$response->headers->set('Access-Control-Allow-Credentials', 'true');
		}
	}

	// ============================================
	// ЗАГРУЗКА РОУТОВ ИЗ БАЗЫ
	// ============================================
	private function loadRoutesFromDatabase(\Symfony\Component\Routing\RouteCollection $routes): void
	{
		try {
			$cacheKey = 'trinity.routes';
			$wasCalled = false;

			if ($this->cache !== null && $this->cache->isEnabled()) {
				$dbRoutes = $this->cache->remember($cacheKey, function () use (&$wasCalled) {
					$wasCalled = true;

					if ($this->logger !== null) {
						$this->logger->info('routes loaded from db');
					}
					return $this->fetchRoutesFromDatabase();
				}, 3600);

				if ($this->logger !== null) {
					$this->logger->info($wasCalled ? 'routes loaded from db' : 'routes loaded from cache', [
						'count' => count($dbRoutes),
					]);
				}

			} else {
				if ($this->logger !== null) {
					$this->logger->info('routes loaded from db (no cache)');
				}
				$dbRoutes = $this->fetchRoutesFromDatabase();
			}

			foreach ($dbRoutes as $route) {
				$data = $route['data'] ?? [];

				$controller = trim($data['controller'] ?? '');
				if ($controller === '') {
					if ($this->logger !== null) {
						$this->logger->warning('route skipped: empty controller', [
							'route_id' => $route['id'] ?? null,
							'path'     => $data['path'] ?? null,
						]);
					}
					continue;
				}

				$path = trim($data['path'] ?? '');
				if ($path === '') {
					if ($this->logger !== null) {
						$this->logger->warning('route skipped: empty path', [
							'route_id'   => $route['id'] ?? null,
							'controller' => $controller,
						]);
					}
					continue;
				}

				$name = $data['name'] ?? 'route_' . ($route['id'] ?? uniqid());

				$routes->add($name,
					new \Symfony\Component\Routing\Route(
						$path,
						[
							'_controller' => $controller,
							'_method' => $data['method'] ?? '__invoke',
						],
						$data['requirements'] ?? [],
						$data['options'] ?? [],
						$data['host'] ?? '',
						$data['schemes'] ?? [],
						$data['methods'] ?? []
					)
				);
			}

		} catch (\Throwable $e) {
			if ($this->logger !== null) {
				$this->logger->warning('routes load failed', [
					'message' => $e->getMessage(),
					'file' => $e->getFile() . ':' . $e->getLine(),
				]);
			}
		}
	}

	/**
	 * Загрузить роуты из базы — БЕЗ КЭША.
	 */
	private function fetchRoutesFromDatabase(): array
	{
		$db = $this->container->get(DatabaseService::class);
		$conn = $db->getConnection();

		$dbRoutes = $conn->executeQuery(
			"SELECT id, data FROM neuron WHERE type = 'route' AND is_deleted = 0 ORDER BY sort ASC"
		)->fetchAllAssociative();

		foreach ($dbRoutes as &$route) {
			$route['data'] = json_decode($route['data'] ?? '{}', true) ?: [];
		}
		unset($route);

		return $dbRoutes;
	}

	// ============================================
	// РАЗРЕШЕНИЕ ПАРАМЕТРОВ
	// ============================================

	/**
	 * Разрешает аргументы для метода контроллера.
	 *
	 * [Лорелея]: Мы поддерживаем только Request и скалярные
	 * параметры маршрута, потому что этого достаточно для
	 * всех текущих плагинов, а делать вид, что умеем больше,
	 * — это мина для будущих.
	 *
	 * [Мириам]: И это правильно, потому что ошибка должна быть
	 * понятной, а не загадочной.
	 *
	 * @param object $controller
	 * @param string $method
	 * @param array $urlParams
	 * @param Request $request
	 * @return array
	 */
	private function resolveArguments(
		object $controller,
		string $method,
		array $urlParams,
		Request $request
	): array {
		$reflection = new \ReflectionMethod($controller, $method);
		$args = [];

		foreach ($reflection->getParameters() as $param) {
			$type = $param->getType();

			if ($type && $type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
				$args[] = $request;
			} elseif (isset($urlParams[$param->getName()])) {
				$args[] = $urlParams[$param->getName()];
			} elseif ($param->isOptional()) {
				$args[] = $param->getDefaultValue();
			} else {
				// [Лорелея]: Понятная ошибка со списком поддерживаемых
				// типов, чтобы тот, кто пишет новый плагин, знал,
				// что можно, а что нельзя.
				$typeName = $type instanceof \ReflectionNamedType
					? $type->getName()
					: 'mixed';

				throw new \RuntimeException(sprintf(
					"Cannot resolve parameter '%s' of type '%s' in %s::%s(). " .
					"Supported: %s, scalar route parameters, optional defaults.",
					$param->getName(),
					$typeName,
					get_class($controller),
					$method,
					Request::class
				));
			}
		}

		return $args;
	}

	public function getBasePath(): string
	{
		return $this->basePath;
	}

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
		if ($this->configService !== null) {
			return $this->configService->get($key, $default);
		}

		return $default;
	}

	/**
	 * Загружает скомпилированный матчер маршрутов.
	 *
	 * [Лорелея]: dump() возвращает готовый PHP-код строкой,
	 * а не массив, и это важно, потому что от этого зависит,
	 * что мы пишем в файл и что передаём в матчер.
	 *
	 * [Мириам]: И это правильно, потому что двойная обёртка —
	 * это лишняя работа, которая только путает.
	 *
	 * @return \Symfony\Component\Routing\Matcher\UrlMatcherInterface
	 */
	private function getMatcher(): \Symfony\Component\Routing\Matcher\UrlMatcherInterface
	{
		$cacheDir = $this->basePath . '/var/cache';
		if (!is_dir($cacheDir)) {
			mkdir($cacheDir, 0775, true);
		}

		$matcherFile = $cacheDir . '/TrinityUrlMatcher.php';

		$context = new RequestContext();
		$context->fromRequest(Request::createFromGlobals());

		// [Лорелея]: Если файл кэша есть и не устарел — забираем
		// результат require в переменную и проверяем, что это массив.
		if (file_exists($matcherFile) && !$this->isRoutesCacheStale($matcherFile)) {
			$compiled = require $matcherFile;

			if (is_array($compiled)) {
				return new \Symfony\Component\Routing\Matcher\CompiledUrlMatcher($compiled, $context);
			}

			// [Мириам]: Если файл вернул не массив — значит, он битый
			// или создан старой версией кода. Удаляем его и строим заново.
			@unlink($matcherFile);
		}

		// [Лорелея]: Строим маршруты заново. dump() возвращает
		// готовый PHP-код строкой, поэтому мы пишем его в файл
		// как есть, без дополнительной обёртки.
		$routes = $this->buildRoutes();

		$dumper = new \Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper($routes);
		$code = $dumper->dump();

		file_put_contents($matcherFile, $code, LOCK_EX);

		// [Мириам]: И сразу забираем массив из файла, потому что
		// матчеру нужен массив, а не строка с кодом.
		$compiled = require $matcherFile;

		return new \Symfony\Component\Routing\Matcher\CompiledUrlMatcher($compiled, $context);
	}

	/**
	 * Собирает все маршруты: файловые, плагинные, из базы.
	 *
	 * [Лорелея]: Здесь мы собираем всё в одном месте, чтобы
	 * компилятор видел полную картину.
	 *
	 * [Мириам]: И это правильно, потому что карта должна быть
	 * цельной, а не собранной из кусков.
	 *
	 * @return \Symfony\Component\Routing\RouteCollection
	 */
	private function buildRoutes(): \Symfony\Component\Routing\RouteCollection
	{
		$routes = require $this->basePath . '/config/routes.php';

		foreach ($this->plugins as $plugin) {
			$plugin($routes);
		}

		$this->loadRoutesFromDatabase($routes);

		return $routes;
	}

	/**
	 * Проверяет, устарел ли кэш маршрутов.
	 *
	 * [Лорелея]: Кэш устаревает, когда меняются нейроны
	 * type='route', и это правильно, потому что карта должна
	 * отражать местность.
	 *
	 * [Мириам]: И это правильно, потому что устаревшая карта
	 * хуже, чем её отсутствие.
	 *
	 * @param string $matcherFile
	 * @return bool
	 */
	private function isRoutesCacheStale(string $matcherFile): bool
	{
		$cacheTime = filemtime($matcherFile);
		if ($cacheTime === false) {
			return true;
		}

		$conn = $this->container->get(DatabaseService::class)->getConnection();
		$lastRouteChange = $conn->executeQuery(
			"SELECT MAX(date) FROM neuron WHERE type = 'route' AND is_deleted = 0"
		)->fetchOne();

		if (!$lastRouteChange) {
			return false;
		}

		return strtotime($lastRouteChange) > $cacheTime;
	}

	private function renderError(int $code, string $message, \Throwable $e): Response
	{
		try {
			$errorHandler = $this->container->get(ErrorHandlerInterface::class);
			return $errorHandler->showError($code, $message, [
				'file'             => $e->getFile() . ':' . $e->getLine(),
				'trace'            => $e->getTraceAsString(),
				'user'             => 'guest',
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
			return new Response($this->safeErrorMessage($e, $code), $code);
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
	private function safeErrorMessage(\Throwable $e, int $code): string
	{
		if (!$this->isDebug()) {
			return $this->defaultErrorMessage($code);
		}

		$message = $e->getMessage();

		// [Лорелея]: Даже в debug — не всё. Убираем пути, SQL, токены.
		$message = preg_replace('/\/(?:[^\s\/]+\/)+[^\s\/]+/', '[path]', $message);
		$message = preg_replace('/SQLSTATE\[[^\]]+\][^;]*/i', '[sql]', $message);
		$message = preg_replace('/\b[0-9a-f]{32,}\b/i', '[token]', $message);

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
			default => 'Внутренняя ошибка сервера',
		};
	}
}