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
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
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

	/** @var string — разрешённые методы для CORS. */
	private const CORS_ALLOW_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';

	/** @var string — разрешённые заголовки для CORS. */
	private const CORS_ALLOW_HEADERS = 'Content-Type, Authorization, X-CSRF-Token';

	/** @var int — время кэширования preflight в секундах. */
	private const CORS_MAX_AGE = 86400;

	/**
	 * @var array — кэш спецификаций параметров методов.
	 *
	 * [Лорелея]: Живёт только в пределах одного запроса, потому что
	 * PHP-FPM не сохраняет статику между запросами. Это не «кэш»
	 * в смысле «переживает запрос», а «локальный кэш запроса».
	 *
	 * [Мириам]: И это правильно, потому что вода не может запомнить
	 * русло, если русло каждый раз новое.
	 */
	private static array $paramSpecCacheRequest = [];

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

		// [Лорелея]: CacheInvalidator больше не знает о статической
		// переменной матчера, потому что её нет. Файл матчера —
		// единственный кэш, который переживает запрос.
		if ($this->container->has(CacheInvalidator::class)) {
			// ничего не делаем, потому что колбэк больше не нужен
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
			// [Лорелея]: Логируем, потому что молчаливая потеря
			// конфигурации хуже, чем явная ошибка. CORS пустой,
			// guard-лимиты дефолтные, и никто не узнает почему.
			if ($this->logger !== null) {
				$this->logger->error('ConfigService load failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				]);
			} else {
				error_log('[Trinity] ConfigService load failed: ' . $e->getMessage());
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

		// [Лорелея]: Обрабатываем OPTIONS и HEAD до всего остального,
		// потому что это — не «страницы», а «служебные запросы».
		// HEAD — это GET без тела, и он должен работать так же.
		if ($request->getMethod() === 'OPTIONS' || $request->getMethod() === 'HEAD') {
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
				default => [500, $this->safeErrorMessage($e, 500)],
			};

			$response = $this->renderError($code, $message, $e);

			// [Лорелея]: Для 405 ставим Allow-заголовок, чтобы клиент
			// знал, какие методы разрешены. Без «пиздеца».
			if ($e instanceof MethodNotAllowedException) {
				$allowedMethods = $e->getAllowedMethods();
				$response->headers->set('Allow', implode(', ', $allowedMethods));
			}
		}

		$this->respond($request, $response);
	}

	/**
	 * Отправляет ответ и ставит CORS-заголовки.
	 *
	 * [Лорелея]: Один метод на все ответы, потому что повторение
	 * applyCorsHeaders в каждом блоке — это пять мест для ошибки.
	 *
	 * [Мириам]: И это правильно, потому что ответ должен быть
	 * один, и CORS должен быть один.
	 */
	private function respond(Request $request, Response $response): void
	{
		$this->applyCorsHeaders($request, $response);

		// [Лорелея]: Базовые security-заголовки. Одно место, пять строк,
		// потому что защита должна быть в одном месте, а не в пяти.
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('X-Frame-Options', 'SAMEORIGIN');
		$response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
		$response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

		// [Мириам]: HSTS только для HTTPS, потому что на HTTP он
		// не имеет смысла и может сломать dev-окружение.
		if ($request->isSecure()) {
			$response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
		}

		$response->headers->set('Access-Control-Allow-Methods', self::CORS_ALLOW_METHODS);
		$response->headers->set('Access-Control-Allow-Headers', self::CORS_ALLOW_HEADERS);
		$response->send();
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

		// [Лорелея]: Preflight — это когда гость спрашивает, можно ли войти.
		// Отвечаем 204 со всеми заголовками.
		if ($origin && $acrm) {
			$response = new Response('', 204);
			$this->applyCorsHeaders($request, $response);
			$response->headers->set('Access-Control-Allow-Methods', self::CORS_ALLOW_METHODS);
			$response->headers->set('Access-Control-Allow-Headers', self::CORS_ALLOW_HEADERS);
			$response->headers->set('Access-Control-Max-Age', (string) self::CORS_MAX_AGE);
			$response->headers->set('Vary', 'Origin');
			$response->send();
			return;
		}

		// [Мириам]: HEAD — это GET без тела. Отвечаем 200 без тела.
		if ($request->getMethod() === 'HEAD') {
			$response = new Response('', 200);
			$response->send();
			return;
		}

		// [Лорелея]: Всё остальное — 405. И ставим Allow, чтобы клиент
		// знал, какие методы разрешены. Без «пиздеца».
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

		// [Лорелея]: Файловые роуты. Один маршрут — home.
		$routes = new \Symfony\Component\Routing\RouteCollection();
		$this->loadRoutesFromDatabase($routes);

		$context = new RequestContext();
		$context->fromRequest($request);

		// [Лорелея]: Передаём собранную коллекцию в getMatcher(),
		// потому что пересобирать её там — это дважды вызывать
		// каждый плагин.
		$matcher = $this->getMatcher($routes, $context);
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
		$args = $this->resolveArguments($controller, $method, $parameters, $request);

		return $controller->$method(...$args);
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

		// [Лорелея]: Vary: Origin нужен всегда, когда мы играем с CORS,
		// потому что иначе CDN или прокси может закэшировать ответ
		// с одним Allow-Origin и отдать его другому origin.
		$response->headers->set('Vary', 'Origin');

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
	/**
	 * Строит RouteCollection из «сырой» коллекции в APCu.
	 * Плагины и файл вызываются только внутри buildRawRoutes(),
	 * а он — только при промахе кэша.
	 *
	 * [Лорелея]: На каждый запрос — apcu_fetch и построение
	 * RouteCollection из массива. Плагины не вызываются.
	 *
	 * @param \Symfony\Component\Routing\RouteCollection $routes
	 */
	private function loadRoutesFromDatabase(\Symfony\Component\Routing\RouteCollection $routes): void
	{
		try {
			$cacheKey = 'trinity.routes.raw';
			$wasCalled = false;

			if ($this->cache !== null && $this->cache->isEnabled()) {
				$raw = $this->cache->remember($cacheKey, function () use (&$wasCalled) {
					$wasCalled = true;
					return $this->buildRawRoutes();
				}, 3600);

				// [Мириам]: Проверяем, что плагины не менялись.
				// Если mtime другой — кэш устарел, пересобираем.
				$currentMtime = $this->getPluginsMtime();
				if (($raw['__meta']['plugins_mtime'] ?? 0) !== $currentMtime) {
					$raw = $this->buildRawRoutes();
					$this->cache->forget($cacheKey);
					$this->cache->remember($cacheKey, fn() => $raw, 3600);
				}

				if ($this->logger !== null) {
					$this->logger->info($wasCalled ? 'routes raw built' : 'routes raw from cache', [
						'count' => count($raw) - 1,
					]);
				}
			} else {
				if ($this->logger !== null) {
					$this->logger->info('routes raw built (no cache)');
				}
				$raw = $this->buildRawRoutes();
			}

			foreach ($raw as $name => $def) {
				if ($name === '__meta') continue;

				$routes->add($name, new \Symfony\Component\Routing\Route(
					$def['path'],
					$def['defaults'],
					$def['requirements'],
					$def['options'],
					$def['host'],
					$def['schemes'],
					$def['methods']
				));
			}

		} catch (\Throwable $e) {
			if ($this->logger !== null) {
				$this->logger->error('routes load failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				]);
			}

			throw new \RuntimeException('Сервис временно недоступен', 503);
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
	 * [Лорелея]: Кэшируем спецификацию параметров, потому что рефлексия
	 * на каждый запрос — это дёшево, но не бесплатно. И приводим
	 * скаляры по типу, потому что строка "42" не должна притворяться
	 * числом 42.
	 *
	 * [Мириам]: И это правильно, потому что тип должен совпадать,
	 * а не почти совпадать.
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
		$cacheKey = get_class($controller) . '::' . $method;

		if (!isset(self::$paramSpecCacheRequest[$cacheKey])) {
			$reflection = new \ReflectionMethod($controller, $method);
			$spec = [];

			foreach ($reflection->getParameters() as $param) {
				$type = $param->getType();
				$spec[] = [
					'name'     => $param->getName(),
					'type'     => $type instanceof \ReflectionNamedType ? $type->getName() : null,
					'optional' => $param->isOptional(),
					'default'  => $param->isOptional() ? $param->getDefaultValue() : null,
				];
			}

			self::$paramSpecCacheRequest[$cacheKey] = $spec;
		}

		$spec = self::$paramSpecCacheRequest[$cacheKey];
		$args = [];

		foreach ($spec as $param) {
			if ($param['type'] === Request::class) {
				$args[] = $request;
			} elseif (isset($urlParams[$param['name']])) {
				$args[] = $this->castScalar($urlParams[$param['name']], $param['type']);
			} elseif ($param['optional']) {
				$args[] = $param['default'];
			} else {
				throw new \RuntimeException(sprintf(
					"Cannot resolve parameter '%s' of type '%s' in %s::%s(). " .
					"Supported: %s, scalar route parameters, optional defaults.",
					$param['name'],
					$param['type'] ?? 'mixed',
					get_class($controller),
					$method,
					Request::class
				));
			}
		}

		return $args;
	}

	/**
	 * Приводит скаляр к типу параметра.
	 *
	 * [Лорелея]: Строка "42" не должна притворяться числом 42,
	 * потому что strict_types в плагинах даст TypeError.
	 *
	 * [Мириам]: И это правильно, потому что тип должен совпадать,
	 * а не почти совпадать.
	 *
	 * @param mixed $value
	 * @param string|null $type
	 * @return mixed
	 */
	private function castScalar(mixed $value, ?string $type): mixed
	{
		if ($type === null) {
			return $value;
		}

		return match ($type) {
			'int'    => (int) $value,
			'float'  => (float) $value,
			'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
			'string' => (string) $value,
			default  => $value,
		};
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
	 * [Лорелея]: Контекст приходит параметром, потому что создавать
	 * его заново из globals — это лишняя работа.
	 *
	 * [Мириам]: И это правильно, потому что контекст должен быть
	 * один, и он должен быть там, где его создали.
	 *
	 * @param RouteCollection $routes
	 * @param RequestContext $context
	 * @return UrlMatcherInterface
	 */
	private function getMatcher(RouteCollection $routes, RequestContext $context): UrlMatcherInterface
	{
		$cacheDir = $this->basePath . '/var/cache';
		if (!is_dir($cacheDir)) {
			mkdir($cacheDir, 0775, true);
		}

		$matcherFile = $cacheDir . '/TrinityUrlMatcher.php';

		if (file_exists($matcherFile)) {
			$compiled = require $matcherFile;

			if (is_array($compiled)) {
				return new CompiledUrlMatcher($compiled, $context);
			}

			@unlink($matcherFile);
		}

		$dumper = new CompiledUrlMatcherDumper($routes);
		$compiled = $dumper->getCompiledRoutes();

		$code = '<?php' . "\n\n" . 'return ' . var_export($compiled, true) . ';' . "\n";

		$tmp = $matcherFile . '.' . uniqid('', true) . '.tmp';
		file_put_contents($tmp, $code, LOCK_EX);
		rename($tmp, $matcherFile);

		// [Лорелея]: Сбрасываем opcache для файла матчера, потому что
		// при opcache.validate_timestamps=0 старый скомпилированный
		// матчер может жить вечно после unlink.
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($matcherFile, true);
		}

		return new CompiledUrlMatcher($compiled, $context);
	}

	private function renderError(int $code, string $message, \Throwable $e): Response
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

	public static function invalidateMatcherCacheStatic(string $basePath): void
	{
		$matcherFile = rtrim($basePath, '/') . '/var/cache/TrinityUrlMatcher.php';
		if (file_exists($matcherFile)) {
			@unlink($matcherFile);
		}

		self::$matcherCompiled = null;
	}

	/**
	 * Собирает «сырую» коллекцию роутов: файл, плагины, база.
	 * Вызывается только при промахе кэша trinity.routes.raw.
	 *
	 * [Лорелея]: Здесь живут все роуты, и плагины, и файл.
	 * Но вызывается это только тогда, когда кэш пуст или устарел.
	 *
	 * @return array
	 */
	private function buildRawRoutes(): array
	{
		$pluginsMtime = $this->getPluginsMtime();

		$raw = [
			'__meta' => ['plugins_mtime' => $pluginsMtime],
		];

		$routes = require $this->basePath . '/config/routes.php';

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
				// [Мириам]: Один сломанный плагин не должен ронять все.
				continue;
			}
		}

		foreach ($routes->all() as $name => $route) {
			$raw[$name] = [
				'path'         => $route->getPath(),
				'defaults'     => $route->getDefaults(),
				'requirements' => $route->getRequirements(),
				'options'      => $route->getOptions(),
				'host'         => $route->getHost(),
				'schemes'      => $route->getSchemes(),
				'methods'      => $route->getMethods(),
			];
		}

		$dbRoutes = $this->fetchRoutesFromDatabase();
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

			$defaults = [
				'_controller' => $controller,
				'_method'     => $data['method'] ?? '__invoke',
			];

			if (!empty($data['roles']) && is_array($data['roles'])) {
				$defaults['_roles'] = $data['roles'];
			}

			if (isset($data['csrf'])) {
				$defaults['_csrf'] = (bool) $data['csrf'];
			}

			$raw[$name] = [
				'path'         => $path,
				'defaults'     => $defaults,
				'requirements' => $data['requirements'] ?? [],
				'options'      => $data['options'] ?? [],
				'host'         => $data['host'] ?? '',
				'schemes'      => $data['schemes'] ?? [],
				'methods'      => $data['methods'] ?? [],
			];
		}

		return $raw;
	}

	/**
	 * [Мириам]: mtime папки plugins/, чтобы понять, менялись ли
	 * плагины. Если менялись — кэш пересобирается.
	 */
	private function getPluginsMtime(): int
	{
		$dir = $this->basePath . '/plugins';
		return is_dir($dir) ? (int) filemtime($dir) : 0;
	}
}