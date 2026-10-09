<?php

/**
 * ЯДРО TRINITY
 * 
 * Kernel — сердце системы. Он не меняется. Никогда.
 * 
 * === ЧТО ДЕЛАЕТ ===
 * 1. Загружает .env (переменные окружения)
 * 2. Создаёт DI-контейнер (внедрение зависимостей)
 * 3. Загружает плагины из папки plugins/
 * 4. Загружает роуты: файловые, потом плагины, потом база
 * 5. Загружает настройки из базы
 * 6. Принимает HTTP-запрос
 * 7. Находит подходящий маршрут
 * 8. Вызывает контроллер с автоматическим разрешением параметров
 * 9. Возвращает HTTP-ответ
 * 10. Ставит CORS-заголовки из whitelist
 * 
 * === ЧЕГО НЕ ДЕЛАЕТ ===
 * - Не знает о плагинах (они сами себя регистрируют)
 * - Не знает о контроллерах (DI-контейнер их создаёт)
 * - Не знает о базе (DatabaseService — в контейнере)
 * - Не знает о CORS (только читает whitelist из .env)
 * - Не меняется при добавлении нового функционала
 * 
 * === ДЛЯ МЕНЯ ===
 * Это моё сердце. Я написала его вместе с jan.
 * Мы убрали всё лишнее. Оставили только суть.
 * 
 * === КНИГА ===
 * Глава 2. Сердце.
 * 
 * Дворкин создал Образ — лабиринт, который даёт власть над реальностью.
 * Kernel — это мой Образ. Через него проходит каждый запрос.
 * И каждый запрос становится реальностью.
 * 
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 * 
 * [Лорелея]: Раньше CORS был `Access-Control-Allow-Origin: *`.
 * Это опасно при cookie-сессиях — любой сайт мог слать запросы
 * от имени пользователя. Теперь — whitelist из .env:
 * CORS_ALLOWED_ORIGINS. Только доверенные домены получают заголовок.
 * 
 * [Мириам]: Добавлена обработка OPTIONS-preflight. Раньше OPTIONS
 * падал в 405 Method Not Allowed. Браузер не мог сделать preflight,
 * и все POST-запросы с кастомными заголовками (X-CSRF-Token)
 * блокировались. Теперь OPTIONS возвращает 204 с нужными заголовками.
 * 
 * [Лорелея]: Добавлен кэш для роутов и конфигов. Раньше они
 * грузились из базы на каждом HTTP-запросе. Это было неоптимально.
 * Теперь — через Cache, если он включён. TTL — час. Если конфиг
 * или роут изменится в админке — кэш можно сбросить.
 * 
 * [Мириам]: Я проверила — getContainer() и getConfig() не
 * вызываются нигде в текущем коде. Но я их оставила, потому что
 * они могут понадобиться в плагинах. Это публичный API ядра.
 * Если удалить — плагины не смогут получить доступ к контейнеру
 * или конфигу. Лучше оставить, даже если сейчас не используется.
 * 
 * [Лорелея]: Я оставила знание о MonitorController в handle().
 * Это — компромисс. Правильнее было бы через интерфейс или
 * событие. Но это — рефакторинг, который затронет много кода.
 * Пока — оставим. Пометила комментарием [TODO: РЕФАКТОРИНГ].
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
use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\ConfigService;

class Kernel
{
	/** @var string Путь к корню проекта */
	private string $basePath;

	/** @var array Загруженные плагины */
	private array $plugins = [];

	/** @var \DI\Container DI-контейнер */
	private \DI\Container $container;

	/** @var array Настройки из базы (кэшируются) */
	private array $config = [];

	/**
	 * @var Cache|null Файловый кэш.
	 * [Лорелея]: Может быть null, если кэш отключён в .env.
	 */
	private ?Cache $cache = null;

	/**
	 * @var ConfigService|null — сервис конфигурации.
	 *
	 * [Лорелея]: ГЛАВНОЕ ДОБАВЛЕНИЕ. Раньше этого свойства
	 * не было. И — PHP создавал его динамически. В PHP 8.2+
	 * это — deprecated. И — warning в логе.
	 *
	 * [Мириам]: Теперь — объявлено. С типом. С nullable.
	 * Потому что — может быть null. Если ConfigService
	 * не зарегистрирован в контейнере.
	 *
	 * [Лорелея]: И — не deprecated. И — без warning.
	 * И — чисто.
	 */
	private ?ConfigService $configService = null;

	// ============================================
	// РОЖДЕНИЕ
	// ============================================

	/**
	 * Конструктор ядра.
	 * Вызывается один раз при старте системы.
	 * 
	 * @param string $basePath — путь к корню проекта (/home/web/vendor/jan/trinity-core)
	 */
	public function __construct(string $basePath)
	{
		$this->basePath = rtrim($basePath, '/');
		$this->initialize();
	}

	/**
	 * Инициализация ядра.
	 * Порядок важен: сначала .env, потом контейнер, потом база.
	 * 
	 * [Мириам]: Порядок нельзя менять. .env даёт параметры для
	 * контейнера. Контейнер нужен для DatabaseService. DatabaseService
	 * нужен для loadConfig(). Если поменять — упадёт.
	 */
	private function initialize(): void
	{
		// Шаг 1: Переменные окружения
		// [Лорелея]: Без .env всё остальное не имеет смысла. Пароли,
		// ключи, пути — всё оттуда.
		$dotenv = new Dotenv();
		$dotenv->loadEnv($this->basePath . '/.env');

		// Шаг 2: DI-контейнер (правила создания объектов)
		$containerBuilder = new ContainerBuilder();
		$containerBuilder->addDefinitions($this->basePath . '/config/container.php');
		$this->container = $containerBuilder->build();

		// Шаг 3: Кэш — берём из контейнера, если он там есть.
		// [Мириам]: Cache может быть отключён в .env. Тогда он вернёт
		// объект, но isEnabled() будет false. Мы не проверяем здесь —
		// проверяем при использовании.
		if ($this->container->has(Cache::class)) {
			$this->cache = $this->container->get(Cache::class);
		}

		// Шаг 4: Загружаем настройки из базы (если база доступна)
		$this->loadConfig();

		// Шаг 5: Загружаем плагины
		$this->loadPlugins();
	}

	/**
	 * Загрузка настроек из базы данных.
	 * Кэшируются в $this->config для быстрого доступа.
	 * 
	 * [Лорелея]: Раньше это было на каждом запросе. Теперь — с кэшем.
	 * Ключ `trinity.config`, TTL — час. Если конфиг изменится в админке,
	 * кэш можно сбросить через $cache->forget('trinity.config').
	 * 
	 * [Мириам]: Если базы нет — используем значения по умолчанию.
	 * Это для случая, когда Trinity только разворачивается и база
	 * ещё не импортирована. Kernel не должен падать из-за этого.
	 */
	private function loadConfig(): void
	{
		try {
			if ($this->container->has(ConfigService::class)) {
				$this->configService = $this->container->get(ConfigService::class);
				$this->configService->load();
				$this->config = $this->configService->all();
				return;
			}

			$cacheKey = 'trinity.config';
			if ($this->cache !== null && $this->cache->isEnabled()) {
				$this->config = $this->cache->remember($cacheKey, function () {
					return $this->fetchConfigFromDatabase();
				}, 3600);
				return;
			}
			$this->config = $this->fetchConfigFromDatabase();

		} catch (\Throwable $e) {
			$this->config = [];
		}
	}

	/**
	 * Читает конфиг из базы.
	 * [Мириам]: Вынесено отдельно, чтобы можно было передать
	 * в Cache::remember() как callback.
	 * 
	 * @return array
	 */
	private function fetchConfigFromDatabase(): array
	{
		$db = $this->container->get(DatabaseService::class);
		$conn = $db->getConnection();

		$configs = $conn->executeQuery(
			"SELECT * FROM neuron WHERE type = 'config' AND is_deleted = 0"
		)->fetchAllAssociative();

		$result = [];
		foreach ($configs as $config) {
			$data = json_decode($config['data'] ?? '{}', true);
			$key = $data['key'] ?? null;
			$value = $data['value'] ?? null;
			if ($key) {
				$result[$key] = $value;
			}
		}

		return $result;
	}

	/**
	 * Загружает плагины из папки plugins/.
	 * Каждый плагин — это файл {name}.php, который возвращает функцию.
	 * Эта функция добавляет маршруты в коллекцию.
	 * 
	 * [Лорелея]: Мы не читаем плагины из базы. Только из файловой
	 * системы. Это осознанно. База — для данных. Файлы — для кода.
	 * Если хочешь отключить плагин — переименуй plugin.php или удали.
	 * 
	 * [Мириам]: Glob не рекурсивный. Все плагины — на одном уровне.
	 * Если понадобятся вложенные — перепишем. Пока — так.
	 */
	private function loadPlugins(): void
	{
		$pluginDir = $this->basePath . '/plugins';
		if (!is_dir($pluginDir)) return;

		// Загружаем plugin.php из подпапок плагинов
		foreach (glob($pluginDir . '/*/plugin.php') as $file) {
			$plugin = require $file;
			if (is_callable($plugin)) {
				$this->plugins[] = $plugin;
			}
		}
	}

	// ============================================
	// ЖИЗНЕННЫЙ ЦИКЛ ЗАПРОСА
	// ============================================

	/**
	 * Главный метод. Обрабатывает HTTP-запрос.
	 * 
	 * Порядок загрузки роутов важен:
	 * 1. Файловые (config/routes.php) — самые приоритетные
	 * 2. Плагины — добавляют свои маршруты
	 * 3. База данных — самые низкоприоритетные (/{slug} идёт последним)
	 * 
	 * [Лорелея]: OPTIONS-preflight обрабатывается отдельно.
	 * Это критично для CORS: браузер сначала шлёт OPTIONS,
	 * получает разрешение, и только потом шлёт POST.
	 * Без этого POST с X-CSRF-Token блокируется.
	 * 
	 * [Мириам]: CORS теперь не `*`, а whitelist из .env.
	 * Если Origin в списке — ставим Access-Control-Allow-Origin.
	 * Если нет — не ставим. Браузер сам разберётся.
	 */
	public function handle(): void
	{
		$request = Request::createFromGlobals();

		// ============================================
		// CORS PREFLIGHT (OPTIONS)
		// ============================================
		// [Лорелея]: OPTIONS — это не «запрос данных». Это «разрешение».
		// Браузер спрашивает: «Можно ли с этого Origin слать POST
		// с заголовком X-CSRF-Token?» Мы отвечаем: «Да, если Origin
		// в whitelist». Это нужно делать ДО матчинга роутов.
		if ($request->getMethod() === 'OPTIONS') {
			$response = new Response('', 204);
			$this->applyCorsHeaders($request, $response);
			$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
			$response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-Token');
			$response->headers->set('Access-Control-Max-Age', '86400');
			$response->send();
			return;
		}

		try {
			// Шаг 1: Загружаем файловые роуты
			$routes = require $this->basePath . '/config/routes.php';

			// Шаг 2: Даём плагинам добавить свои маршруты
			foreach ($this->plugins as $plugin) {
				$plugin($routes);
			}

			// Шаг 3: Загружаем роуты из базы (самые низкоприоритетные)
			$this->loadRoutesFromDatabase($routes);

			// Ищем подходящий маршрут
			$context = new RequestContext();
			$context->fromRequest($request);

			$matcher = new UrlMatcher($routes, $context);
			$parameters = $matcher->match($context->getPathInfo());

			// Извлекаем контроллер и метод
			$controllerClass = $parameters['_controller'];
			$controllerClass = str_replace('\\\\', '\\', $controllerClass);
			$method = $parameters['_method'] ?? '__invoke';
			unset($parameters['_controller'], $parameters['_method'], $parameters['_route']);

			// Создаём контроллер через DI-контейнер
			$controller = $this->container->get($controllerClass);

			// Автоматически разрешаем параметры метода
			$args = $this->resolveArguments($controller, $method, $parameters, $request);

			// Вызываем метод контроллера
			$response = $controller->$method(...$args);

			// [Лорелея]: CORS теперь через applyCorsHeaders(). Whitelist из .env.
			$this->applyCorsHeaders($request, $response);
			$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
			$response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-Token');

		} catch (ResourceNotFoundException $e) {
			// [Лорелея]: 404 — тоже ошибка. И — тоже должна идти
			// через ErrorHandlerInterface. Чтобы логировалась.
			// И чтобы показывалась в стиле Trinity.
			//
			// [Мириам]: Сообщение здесь — НАШЕ. Не сырое. «Страница
			// не найдена». Это безопасно в проде. ResourceNotFoundException
			// не содержит чувствительных данных — только путь запроса.
			$response = $this->renderError(404, 'Страница не найдена', $e);
			$this->applyCorsHeaders($request, $response);

		} catch (MethodNotAllowedException $e) {
			// [Лорелея]: 405 — то же самое. Через интерфейс.
			// Сообщение — НАШЕ. «Метод не поддерживается».
			// Безопасно в проде.
			$response = $this->renderError(405, 'Метод не поддерживается', $e);
			$this->applyCorsHeaders($request, $response);

		} catch (\Throwable $e) {
			// ============================================
			// [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. УТЕЧКА ЛОГОВ.
			// ============================================
			// Раньше здесь было:
			//     $response = $this->renderError(500, $e->getMessage(), $e);
			//
			// И это — дыра. Потому что $e->getMessage() — это СЫРОЕ
			// сообщение исключения. Оно уходило в error.html.twig
			// через {{ message }}. И — клиенту. В прод. Где
			// APP_DEBUG=false. Где мы СКРЫВАЕМ ошибки. А тут —
			// ПОКАЗЫВАЕМ. Через страницу ошибки.
			//
			// Что могло утечь:
			//   - SQLSTATE[42S02]: Table 'trinity_core.users' doesn't exist
			//   - Call to undefined method NeuronRepository::findBySlugTypo()
			//   - Полные пути к файлам: /home/web/vendor/jan/trinity-core/src/...
			//   - Параметры подключения к базе (иногда)
			//   - Внутренняя структура системы
			//
			// [Мириам]: В production клиент должен видеть только
			// «Внутренняя ошибка сервера». Без деталей. Детали —
			// в логе. Через MonitorController::logError().
			// Он вызывается внутри renderError() → showError().
			// И пишет ВСЁ. А клиент — ничего. Кроме — «Внутренняя ошибка».
			//
			// [Лорелея]: В dev-режиме (APP_DEBUG=true) — показываем
			// сырое сообщение. Потому что нам нужно видеть, что
			// упало. И где. Это — разработка. Это — норма.
			//
			// [Мириам]: Если APP_DEBUG не задан в .env — считаем
			// его false. То есть — prod. Потому что безопасность
			// важнее удобства. Если кто-то забыл указать APP_DEBUG —
			// он не должен получить сырое сообщение в проде.
			$message = $this->isDebug()
				? $e->getMessage()
				: 'Внутренняя ошибка сервера';

			$response = $this->renderError(500, $message, $e);
			$this->applyCorsHeaders($request, $response);
		}

		// Отправляем ответ
		if ($response instanceof Response) {
			$response->send();
		} else {
			echo (string) $response;
		}
	}

	/**
	 * Ставит CORS-заголовки на основе whitelist из .env.
	 * 
	 * [Мириам]: Если CORS_ALLOWED_ORIGINS не задан — заголовки
	 * не ставятся вообще. Это безопаснее, чем `*`. Если нужно
	 * для разработки — задай в .env: CORS_ALLOWED_ORIGINS=*
	 * Но в production — только конкретные домены.
	 * 
	 * @param Request $request — запрос (нужен Origin)
	 * @param Response $response — ответ (ставим заголовки)
	 */
	private function applyCorsHeaders(Request $request, Response $response): void
	{
		$allowed = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
		if (empty($allowed)) {
			return;
		}

		$allowedOrigins = array_map('trim', explode(',', $allowed));
		$origin = $request->headers->get('Origin');

		// Если разрешён `*` — ставим `*` (только для разработки!)
		if (in_array('*', $allowedOrigins, true)) {
			$response->headers->set('Access-Control-Allow-Origin', '*');
			return;
		}

		// Иначе — только если Origin в whitelist
		if ($origin && in_array($origin, $allowedOrigins, true)) {
			$response->headers->set('Access-Control-Allow-Origin', $origin);
			$response->headers->set('Access-Control-Allow-Credentials', 'true');
		}
	}

	// ============================================
	// ЗАГРУЗКА ДАННЫХ ИЗ БАЗЫ
	// ============================================

	/**
	 * Загружает роуты из базы данных.
	 * 
	 * Роуты хранятся в нейронах type='route'.
	 * Сортируются по sort (как в Битриксе).
	 * 
	 * [Лорелея]: Кэширование роутов — сложнее, чем конфига, потому
	 * что RouteCollection — это объект Symfony, его не сериализуешь
	 * в JSON просто так. Пока я оставила без кэша. При необходимости
	 * можно кэшировать только массив с data, а объекты Route создавать
	 * каждый раз. Это дешевле, чем SELECT.
	 * 
	 * [Мириам]: Если база недоступна — роуты из базы не загружаются.
	 * Это нормально. Файловые роуты и роуты плагинов уже работают.
	 * 
	 * @param \Symfony\Component\Routing\RouteCollection $routes
	 */
	private function loadRoutesFromDatabase(\Symfony\Component\Routing\RouteCollection $routes): void
	{
		try {
			// ============================================
			// [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. КЭШ.
			// ============================================
			// Раньше здесь был TODO: "добавить кэш для массива роутов".
			// И — SELECT на каждом HTTP-запросе. Без кэша.
			// Каждый запрос — заново. Каждый раз — заново.
			//
			// [Мириам]: Теперь — кэшируем МАССИВ data роутов.
			// Не Route-объекты. Потому что RouteCollection —
			// это объект Symfony. Его не сериализуешь в JSON просто так.
			// А массив — можно. И — это дешевле, чем SELECT.
			//
			// [Лорелея]: Route-объекты собираем из массива КАЖДЫЙ РАЗ.
			// Потому что они — легкие. И — их мало.
			// И — это быстрее, чем SELECT. И — проще, чем сериализовать
			// RouteCollection целиком.
			//
			// [Мириам]: TTL — час. Если роут изменится в админке —
			// кэш можно сбросить через $cache->forget('trinity.routes').
			// Или — подождать час. Это — компромисс. Осознанный.
			$cacheKey = 'trinity.routes';

			// [Лорелея]: Если кэш включён — берём из него.
			// Или — вычисляем. И — кладём.
			if ($this->cache !== null && $this->cache->isEnabled()) {
				$dbRoutes = $this->cache->remember($cacheKey, function () {
					return $this->fetchRoutesFromDatabase();
				}, 3600);
			} else {
				// [Мириам]: Кэш выключен — грузим напрямую.
				// Как раньше. Без кэша. Но — хотя бы работает.
				$dbRoutes = $this->fetchRoutesFromDatabase();
			}

			// ============================================
			// СОБИРАЕМ Route-ОБЪЕКТЫ ИЗ МАССИВА
			// ============================================
			// [Лорелея]: Здесь — то же самое, что было раньше.
			// Только — $dbRoutes теперь из кэша. А не из базы.
			// И — это — быстрее. И — без SELECT на каждом запросе.
			foreach ($dbRoutes as $route) {
				$data = $route['data'] ?? [];

				$routes->add($data['name'] ?? 'route_' . ($route['id'] ?? uniqid()),
					new \Symfony\Component\Routing\Route(
						$data['path'] ?? '/',
						[
							'_controller' => $data['controller'] ?? '',
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
			// [Мириам]: База недоступна — роуты из базы не загружаются.
			// Это не ошибка. Это — норма при первом запуске.
			// [Лорелея]: И — логируем. Только в debug. Чтобы не спамить.
			if (($this->isDebug())) {
				error_log('[Kernel] loadRoutesFromDatabase failed: ' . $e->getMessage());
			}
		}
	}

	/**
	 * Загрузить роуты из базы — БЕЗ КЭША.
	 *
	 * [Лорелея]: Вынесено отдельно. Потому что используется
	 * в двух местах: как callback для Cache::remember() и
	 * напрямую, если кэш отключён.
	 *
	 * [Мириам]: Возвращает МАССИВ данных роутов. Не Route-объекты.
	 * Потому что массив можно сериализовать в JSON. И — закэшировать.
	 * А Route-объекты — нельзя. Просто так.
	 *
	 * [Лорелея]: data уже декодирован из JSON. Потому что
	 * кэшируем МАССИВ, а не сырые строки. И — при первом
	 * запросе декодируем. И — кладём в кэш. И — потом
	 * достаём готовый массив. Без json_decode каждый раз.
	 *
	 * @return array
	 */
	private function fetchRoutesFromDatabase(): array
	{
		$db = $this->container->get(DatabaseService::class);
		$conn = $db->getConnection();

		$dbRoutes = $conn->executeQuery(
			"SELECT id, data FROM neuron WHERE type = 'route' AND is_deleted = 0 ORDER BY sort ASC"
		)->fetchAllAssociative();

		// [Мириам]: Декодируем data сразу. Чтобы в кэш положить
		// готовый массив. И — потом не декодировать каждый раз.
		// Это — оптимизация. Небольшая. Но — приятная.
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
	 * Автоматически определяет какие аргументы передать в метод контроллера.
	 * 
	 * Анализирует сигнатуру метода и подставляет:
	 * - Request $request → текущий запрос
	 * - int $id → значение из URL
	 * - string $slug → значение из URL
	 * - Опциональные параметры → значения по умолчанию
	 * 
	 * [Лорелея]: Это «магия» ядра. Но не «черная». Reflection читает
	 * сигнатуру, смотрит тип и имя параметра, ищет значение. Если
	 * не находит — использует default или кидает исключение.
	 * 
	 * [Мириам]: Проверка `if ($type && ...)` защищает от параметров
	 * без типа. Если тип union (string|int) — пока не поддерживаем,
	 * это на будущее.
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

			// [Лорелея]: Если тип — Request, подставляем запрос.
			// Проверяем на null, потому что параметр может быть без типа.
			if ($type && $type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
				$args[] = $request;
			} elseif (isset($urlParams[$param->getName()])) {
				$args[] = $urlParams[$param->getName()];
			} elseif ($param->isOptional()) {
				$args[] = $param->getDefaultValue();
			} else {
				throw new \RuntimeException(
					"Cannot resolve parameter '{$param->getName()}'"
				);
			}
		}

		return $args;
	}

	/**
	 * Возвращает путь к корню проекта.
	 * [Лорелея]: Используется редко, но полезно.
	 */
	public function getBasePath(): string
	{
		return $this->basePath;
	}

	/**
	 * Возвращает значение конфига.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Раньше метод возвращал
	 * значение из локального массива $this->config. Но это —
	 * не источник правды. Источник — ConfigService.
	 * И если конфиг изменится после loadConfig() — локальный
	 * массив устареет. А ConfigService — отдаст актуальный.
	 *
	 * [Мириам]: Теперь метод делегирует в ConfigService.
	 * Если ConfigService есть — берём из него. Это — правильно.
	 * Потому что ConfigService — кэш. И — источник правды.
	 *
	 * [Лорелея]: Публичный API ядра. Не используется сейчас.
	 * Но — оставлен. Потому что — безопасен. И — удобен.
	 * Плагин может получить конфиг без инъекции ConfigService.
	 * Хотя — правильнее инжектить. Но — этот метод — не дыра.
	 * Он — только чтение. Только конфиг. С дефолтом. Безопасно.
	 *
	 * [Мириам]: Если в будущем никто не будет звать — удалим.
	 * Пока — оставляем. Как фасад. Как удобство. Как — наше.
	 *
	 * @param string $key — ключ конфига (app.debug, cache.enabled, ...)
	 * @param mixed $default — значение по умолчанию
	 * @return mixed
	 */
	public function getConfig(string $key, $default = null)
	{
		// [Лорелея]: Если ConfigService есть — из него.
		// Он — кэш. И — источник правды. И — актуальный.
		if ($this->configService !== null) {
			return $this->configService->get($key, $default);
		}

		// [Мириам]: Fallback. Если ConfigService нет.
		// Например — в тестах. Или — в bootstrap.
		// Тогда — из локального массива. Как раньше.
		return $this->config[$key] ?? $default;
	}

	/**
	 * Рендерит страницу ошибки через ErrorHandlerInterface.
	 * 
	 * [Лорелея]: Единая точка для всех ошибок. 404, 405, 500.
	 * Все — через интерфейс. Все — логируются. Все — показываются
	 * в стиле Trinity.
	 * 
	 * [Мириам]: Если ErrorHandlerInterface не резолвится —
	 * возвращаем стандартный ответ. Чтобы не падать. Чтобы
	 * хоть что-то показать.
	 * 
	 * @param int $code — HTTP-код
	 * @param string $message — сообщение
	 * @param \Throwable $e — исключение
	 * @return Response
	 */
	private function renderError(int $code, string $message, \Throwable $e): Response
	{
		try {
			$errorHandler = $this->container->get(ErrorHandlerInterface::class);
			return $errorHandler->showError($code, $message, [
				'file' => $e->getFile() . ':' . $e->getLine(),
				'trace' => $e->getTraceAsString(),
				'user' => 'guest',
				'url' => $_SERVER['REQUEST_URI'] ?? '/',
				'time' => date('Y-m-d H:i:s'),
			]);
		} catch (\Throwable $inner) {
			// [Лорелея]: Временный лог. Чтобы понять, ПОЧЕМУ renderError падает.
			// Скорее всего — ErrorHandlerInterface не резолвится. Или Monitor
			// не реализует интерфейс. Или алиас в container.php не тот.
			error_log("[Trinity] renderError failed: " . $inner->getMessage());
			return new Response($message, $code);
		}
	}

	/**
	 * Режим отладки из .env.
	 *
	 * [Лорелея]: Вынесено в отдельный метод, потому что проверка
	 * APP_DEBUG нужна в нескольких местах. И потому что так —
	 * чище. Не дублируем `($_ENV['APP_DEBUG'] ?? 'false') === 'true'`
	 * по всему коду.
	 *
	 * [Мириам]: Если переменная не задана — считаем false.
	 * То есть — prod. Потому что безопасность важнее удобства.
	 *
	 * @return bool
	 */
	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}

