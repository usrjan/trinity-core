<?php

declare(strict_types=1);

/**
 * КОМПИЛЯТОР МАРШРУТОВ
 *
 * Собирает маршруты из трёх источников: база, файл, плагины.
 * Компилирует их в матчер и кэширует на диск.
 *
 * === ПОЧЕМУ ИМЯ КЛАССА = ХЭШ СОДЕРЖИМОГО ===
 * Потому что require_once кэширует класс в OPcache. И после
 * invalidate() старый TrinityUrlMatcher остаётся в памяти
 * процесса до перезапуска. А имя класса, зависящее от
 * содержимого, решает это: новый файл = новый класс = новый
 * require. Старый класс доживёт до перезапуска FPM — и это
 * правильно. Он соответствует старому набору маршрутов,
 * который был валиден на момент запроса.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;

class RouteCompiler
{
	/** @var Cache — кэш. */
	private Cache $cache;

	/** @var DatabaseService — база. */
	private DatabaseService $db;

	/** @var ConfigService — конфиги. */
	private ConfigService $configService;

	/** @var string — путь к корню проекта. */
	private string $basePath;

	/** @var \Psr\Log\LoggerInterface|null — логгер. */
	private ?\Psr\Log\LoggerInterface $logger;

	/** @var array — плагины-замыкатели. */
	private array $plugins = [];

	/**
	 * Конструктор.
	 *
	 * @param Cache $cache
	 * @param DatabaseService $db
	 * @param ConfigService $configService
	 * @param string $basePath
	 * @param \Psr\Log\LoggerInterface|null $logger
	 */
	public function __construct(
		Cache $cache,
		DatabaseService $db,
		ConfigService $configService,
		string $basePath,
		?\Psr\Log\LoggerInterface $logger = null
	) {
		$this->cache = $cache;
		$this->db = $db;
		$this->configService = $configService;
		$this->basePath = rtrim($basePath, '/');
		$this->logger = $logger;
	}

	/**
	 * Принимает плагины из Kernel после loadPlugins().
	 *
	 * @param array $plugins
	 */
	public function init(array $plugins): void
	{
		$this->plugins = $plugins;
	}

	/**
	 * Возвращает скомпилированный матчер.
	 *
	 * [Лорелея]: Имя класса = хэш содержимого. Поэтому старый
	 * класс в памяти процесса не мешает: мы всегда создаём новый.
	 * И поэтому OPcache не нужен: новый файл он ещё не знает.
	 *
	 * @param RouteCollection $routes
	 * @param RequestContext $context
	 * @return CompiledUrlMatcher
	 */
	public function getMatcher(RouteCollection $routes, RequestContext $context): CompiledUrlMatcher
	{
		$this->loadRoutesFromDatabase($routes);

		$cacheDir = $this->basePath . '/var/cache';
		if (!is_dir($cacheDir)) {
			mkdir($cacheDir, 0775, true);
		}

		$dumper = new CompiledUrlMatcherDumper($routes);
		$compiled = $dumper->getCompiledRoutes();

		$codeTemplate = '<?php' . "\n\n"
			. 'class %CLASS% extends \\Symfony\\Component\\Routing\\Matcher\\CompiledUrlMatcher' . "\n"
			. '{' . "\n"
			. "\t" . 'public function __construct($context)' . "\n"
			. "\t" . '{' . "\n"
			. "\t\t" . 'parent::__construct(' . var_export($compiled, true) . ', $context);' . "\n"
			. "\t" . '}' . "\n"
			. '}' . "\n";

		$hash = substr(md5($codeTemplate), 0, 16);
		$className = 'TrinityUrlMatcher_' . $hash;
		$matcherFile = $cacheDir . '/' . $className . '.php';

		$code = str_replace('%CLASS%', $className, $codeTemplate);

		if (!file_exists($matcherFile)) {
			$this->writeMatcherFile($matcherFile, $code);
		}

		require_once $matcherFile;

		return new $className($context);
	}

	/**
	 * Записывает файл матчера атомарно.
	 *
	 * @param string $matcherFile
	 * @param string $code
	 */
	private function writeMatcherFile(string $matcherFile, string $code): void
	{
		$tmp = $matcherFile . '.' . uniqid('', true) . '.tmp';
		file_put_contents($tmp, $code, LOCK_EX);
		rename($tmp, $matcherFile);

		if ($this->logger !== null) {
			$this->logger->debug('routes matcher compiled', [
				'file' => basename($matcherFile),
			]);
		}

		$this->cleanupOldMatchers(dirname($matcherFile));
	}

	/**
	 * Удаляет старые файлы матчеров (старше суток).
	 *
	 * @param string $cacheDir
	 */
	private function cleanupOldMatchers(string $cacheDir): void
	{
		$threshold = time() - 86400;

		foreach (glob($cacheDir . '/TrinityUrlMatcher_*.php') ?: [] as $file) {
			$mtime = filemtime($file);
			if ($mtime !== false && $mtime < $threshold) {
				@unlink($file);

				if (function_exists('opcache_invalidate')) {
					@opcache_invalidate($file, true);
				}
			}
		}
	}

	/**
	 * Строит RouteCollection из «сырой» коллекции в APCu.
	 *
	 * @param RouteCollection $routes
	 */
	private function loadRoutesFromDatabase(RouteCollection $routes): void
	{
		try {
			$pluginsMtime = $this->getPluginsMtime();
			$cacheKey = 'trinity.routes.raw.' . $pluginsMtime;
			$wasCalled = false;

			if ($this->cache->isEnabled()) {
				$raw = $this->cache->remember($cacheKey, function () use (&$wasCalled) {
					$wasCalled = true;
					return $this->buildRawRoutes();
				}, 3600);

				if ($this->logger !== null) {
					$this->logger->debug($wasCalled ? 'routes raw built' : 'routes raw from cache', [
						'count' => count($raw),
						'mtime' => $pluginsMtime,
					]);
				}
			} else {
				if ($this->logger !== null) {
					$this->logger->debug('routes raw built (no cache)');
				}
				$raw = $this->buildRawRoutes();
			}

			foreach ($raw as $name => $def) {
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
	 * Собирает «сырую» коллекцию: база, файл, плагины.
	 *
	 * @return array
	 */
	private function buildRawRoutes(): array
	{
		$raw = [];

		$dbRoutes = $this->db->getConnection()->executeQuery(
			"SELECT id, data FROM neuron WHERE type = 'route' AND is_deleted = 0 ORDER BY sort ASC"
		)->fetchAllAssociative();

		foreach ($dbRoutes as &$route) {
			$route['data'] = json_decode($route['data'] ?? '{}', true) ?: [];
		}
		unset($route);

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

		return $raw;
	}

	/**
	 * mtime папки plugins/, чтобы понять, менялись ли плагины.
	 *
	 * @return int
	 */
	private function getPluginsMtime(): int
	{
		$cacheKey = 'trinity.plugins.mtime';

		if ($this->cache->isEnabled()) {
			$cached = $this->cache->remember($cacheKey, function () {
				return $this->computePluginsMtime();
			}, 60);

			return (int) $cached;
		}

		return $this->computePluginsMtime();
	}

	/**
	 * Вычисляет mtime плагинов.
	 *
	 * @return int
	 */
	private function computePluginsMtime(): int
	{
		$dir = $this->basePath . '/plugins';
		if (!is_dir($dir)) {
			return 0;
		}

		$max = 0;
		foreach (glob($dir . '/*/plugin.php') ?: [] as $file) {
			$m = @filemtime($file);
			if ($m !== false && $m > $max) {
				$max = $m;
			}
		}

		return $max;
	}

	/**
	 * Сбрасывает кэш маршрутов: APCu, файлы матчеров, opcache.
	 */
	public function invalidate(): void
	{
		$this->cache->forget('trinity.routes');
		$this->cache->forgetByPrefix('trinity.routes.raw.');
		$this->cache->forget('trinity.plugins.mtime');   // ← добавить

		$cacheDir = $this->basePath . '/var/cache';

		foreach (glob($cacheDir . '/TrinityUrlMatcher_*.php') ?: [] as $file) {
			@unlink($file);

			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($file, true);
			}
		}

		if ($this->logger !== null) {
			$this->logger->debug('routes cache invalidated');
		}
	}
}