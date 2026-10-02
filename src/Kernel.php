<?php

/**
 * ЯДРО TRINITY
 * 
 * Kernel — сердце системы. Он не меняется. Никогда.
 * 
 * === ЧТО ДЕЛАЕТ ===
 * 1. Загружает .env (переменные окружения)
 * 2. Создаёт DI-контейнер (внедрение зависимостей)
 * 3. Загружает плагины из базы (type='plugin')
 * 4. Загружает роуты из базы (type='route')
 * 5. Загружает шаблоны из базы (type='template')
 * 6. Загружает настройки из базы (type='config')
 * 7. Принимает HTTP-запрос
 * 8. Находит подходящий маршрут
 * 9. Вызывает контроллер с автоматическим разрешением параметров
 * 10. Возвращает HTTP-ответ
 * 
 * === ЧЕГО НЕ ДЕЛАЕТ ===
 * - Не знает о плагинах (они сами себя регистрируют)
 * - Не знает о контроллерах (DI-контейнер их создаёт)
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
	 */
	private function initialize(): void
	{
		// Шаг 1: Переменные окружения
		$dotenv = new Dotenv();
		$dotenv->loadEnv($this->basePath . '/.env');

		// Шаг 2: DI-контейнер (правила создания объектов)
		$containerBuilder = new ContainerBuilder();
		$containerBuilder->addDefinitions($this->basePath . '/config/container.php');
		$this->container = $containerBuilder->build();

		// Шаг 3: Загружаем настройки из базы (если база доступна)
		$this->loadConfig();

		// Шаг 4: Загружаем плагины
		$this->loadPlugins();
	}

	/**
	 * Загрузка настроек из базы данных.
	 * Кэшируются в $this->config для быстрого доступа.
	 */
	private function loadConfig(): void
	{
		try {
			$db = $this->container->get(DatabaseService::class);
			$conn = $db->getConnection();

			$configs = $conn->executeQuery(
				"SELECT * FROM neuron WHERE type = 'config' AND is_deleted = 0"
			)->fetchAllAssociative();

			foreach ($configs as $config) {
				$data = json_decode($config['data'] ?? '{}', true);
				$key = $data['key'] ?? null;
				$value = $data['value'] ?? null;
				if ($key) {
					$this->config[$key] = $value;
				}
			}
		} catch (\Throwable $e) {
			// База ещё не готова — используем значения по умолчанию
		}
	}

	/**
	 * Загружает плагины из папки plugins/.
	 * Каждый плагин — это файл {name}.php, который возвращает функцию.
	 * Эта функция добавляет маршруты в коллекцию.
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
	 */
	public function handle(): void
	{
		try {
			$request = Request::createFromGlobals();

			// Шаг 1: Загружаем файловые роуты
			$routes = require $this->basePath . '/config/routes.php';

			// Шаг 2: Даём плагинам добавить свои маршруты
			foreach ($this->plugins as $plugin) {
				$plugin($routes);
			}

			// Ищем подходящий маршрут среди файловых и плагиновых роутов.
			// Шаг 3: роуты из базы — самые низкоприоритетные, поэтому
			// проверяются только если файловые маршруты не совпали.
			$context = new RequestContext();
			$context->fromRequest($request);

			try {
				$matcher = new UrlMatcher($routes, $context);
				$parameters = $matcher->match($context->getPathInfo());
			} catch (ResourceNotFoundException $e) {
				$parameters = $this->matchDatabaseRoute($request);

				if ($parameters === null) {
					throw $e;
				}
			}

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

			// Добавляем CORS-заголовки
			$response->headers->set('Access-Control-Allow-Origin', '*');
			$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
			$response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');

		} catch (ResourceNotFoundException $e) {
			$response = new Response('Not Found', 404);
		} catch (MethodNotAllowedException $e) {
			$response = new Response('Method Not Allowed', 405);
		} catch (\Throwable $e) {
			error_log("[Trinity] " . $e->getMessage() . "\n" . $e->getTraceAsString());
			
			// Пробуем показать красивую страницу ошибки через Монитор
			try {
				if ($this->container->has(\Jan\Trinity\Plugin\Monitor\MonitorController::class)) {
					$monitor = $this->container->get(\Jan\Trinity\Plugin\Monitor\MonitorController::class);
					$response = $monitor->showError(500, $e->getMessage(), [
						'file' => $e->getFile() . ':' . $e->getLine(),
						'trace' => $e->getTraceAsString(),
						'user' => 'guest',
						'url' => $_SERVER['REQUEST_URI'] ?? '/',
						'time' => date('Y-m-d H:i:s'),
					]);
				} else {
					$response = new Response('Internal Server Error', 500);
				}
			} catch (\Throwable $inner) {
				$response = new Response('Internal Server Error', 500);
			}
		}

		// Отправляем ответ
		if ($response instanceof Response) {
			$response->send();
		} else {
			echo (string) $response;
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
	 * Нейрон-роут — обычный JSON-нейрон, поэтому матчинг симулируется
	 * над его данными так же, как Symfony делает это над Route:
	 * - path компилируется в регулярку (placeholder {id} → именованная группа);
	 * - проверяются methods / host / schemes;
	 * - совпадение даёт _controller/_method и параметры URL.
	 *
	 * Если база недоступна — роуты из базы не участвуют в матчинге.
	 */
	private function matchDatabaseRoute(Request $request): ?array
	{
		try {
			$db = $this->container->get(DatabaseService::class);
			$conn = $db->getConnection();

			$dbRoutes = $conn->executeQuery(
				"SELECT * FROM neuron WHERE type = 'route' AND is_deleted = 0 ORDER BY sort ASC"
			)->fetchAllAssociative();
		} catch (\Throwable $e) {
			// База недоступна — роутов из базы нет
			return null;
		}

		$pathInfo = rawurldecode($request->getPathInfo());
		$method = $request->getMethod();

		foreach ($dbRoutes as $route) {
			$data = json_decode($route['data'] ?? '{}', true);
			if (!is_array($data) || empty($data['controller'])) {
				continue;
			}

			$pattern = $data['path'] ?? '/';
			$requirements = $data['requirements'] ?? [];
			$defaults = $data['defaults'] ?? [];

			// Методы: пустой список = любые
			$methods = array_map('strtoupper', $data['methods'] ?? []);
			if ($methods && !in_array($method, $methods, true)) {
				continue;
			}

			// Host / schemes — если заданы
			if (!empty($data['host']) && strtolower($request->getHost()) !== strtolower((string)$data['host'])) {
				continue;
			}
			$schemes = $data['schemes'] ?? [];
			if ($schemes && !in_array($request->getScheme(), $schemes, true)) {
				continue;
			}

			// Компиляция пути: {name} → (?P<name>regex), дефолт [^/]+
			$regex = preg_replace_callback('#\{(\w+)(?::([^}]+))?\}#', function ($m) use ($requirements) {
				$name = $m[1];
				$regex = $requirements[$name] ?? ($m[2] ?? '[^/]+');
				return '(?P<' . $name . '>' . $regex . ')';
			}, $pattern);

			if (@preg_match('#^' . $regex . '$#u', $pathInfo, $matches) !== 1) {
				continue;
			}

			$params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
			unset($params['_route'], $params['_regexp']);

			return array_merge($params, $defaults, [
				'_controller' => $data['controller'],
				'_method'     => $data['method'] ?? '__invoke',
			]);
		}

		return null;
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
			
			if ($type && $type->getName() === Request::class) {
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

	// ============================================
	// ДОСТУП К СЕРВИСАМ
	// ============================================

	public function getContainer(): \DI\Container
	{
		return $this->container;
	}

	public function getBasePath(): string
	{
		return $this->basePath;
	}

	public function getConfig(string $key, $default = null)
	{
		return $this->config[$key] ?? $default;
	}
}