<?php
declare(strict_types=1);

/**
 * КОМПИЛЯТОР МАРШРУТОВ
 *
 * Собирает роуты из файла, плагинов и базы. Строит скомпилированный
 * TrinityUrlMatcher. Kernel больше не знает, откуда роуты — это работа
 * компилятора.
 *
 * [Лорелея]: Ядро — скучное. Компилятор — знает, как собрать карту.
 *
 * [Мириам]: И это правильно, потому что карта — это не «список»,
 * это знание.
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
	private Cache $cache;
	private DatabaseService $db;
	private ConfigService $configService;
	private string $basePath;
	private ?\Psr\Log\LoggerInterface $logger;

	/** @var array Плагины-замыкатели. */
	private array $plugins = [];

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
	 */
	public function init(array $plugins): void
	{
		$this->plugins = $plugins;
	}

	/**
	 * Возвращает скомпилированный матчер.
	 */
	public function getMatcher(RouteCollection $routes, RequestContext $context): CompiledUrlMatcher
	{
		$this->loadRoutesFromDatabase($routes);

		$cacheDir = $this->basePath . '/var/cache';
		if (!is_dir($cacheDir)) {
			mkdir($cacheDir, 0775, true);
		}

		$matcherFile = $cacheDir . '/TrinityUrlMatcher.php';

		if (file_exists($matcherFile)) {
			require_once $matcherFile;

			if (class_exists('TrinityUrlMatcher', false)) {
				return new \TrinityUrlMatcher($context);
			}

			@unlink($matcherFile);
		}

		$dumper = new CompiledUrlMatcherDumper($routes);
		$compiled = $dumper->getCompiledRoutes();

		$code = '<?php' . "\n\n"
			. 'class TrinityUrlMatcher extends \\Symfony\\Component\\Routing\\Matcher\\CompiledUrlMatcher' . "\n"
			. '{' . "\n"
			. "\t" . 'public function __construct($context)' . "\n"
			. "\t" . '{' . "\n"
			. "\t\t" . 'parent::__construct(' . var_export($compiled, true) . ', $context);' . "\n"
			. "\t" . '}' . "\n"
			. '}' . "\n";

		$tmp = $matcherFile . '.' . uniqid('', true) . '.tmp';
		file_put_contents($tmp, $code, LOCK_EX);
		rename($tmp, $matcherFile);

		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($matcherFile, true);
		}

		require_once $matcherFile;

		return new \TrinityUrlMatcher($context);
	}

	/**
	 * Строит RouteCollection из «сырой» коллекции в APCu.
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
	 */
	private function getPluginsMtime(): int
	{
		$dir = $this->basePath . '/plugins';
		if (!is_dir($dir)) return 0;

		$max = 0;
		foreach (glob($dir . '/*/plugin.php') ?: [] as $file) {
			$m = filemtime($file);
			if ($m !== false && $m > $max) {
				$max = $m;
			}
		}
		return $max;
	}

	/**
	 * Сбрасывает кэш маршрутов: APCu, файл матчера, opcache.
	 *
	 * [Лорелея]: RouteCompiler знает, что кэшировать, потому что
	 * он это строит. CacheInvalidator — просто вызывает.
	 *
	 * [Мириам]: И это правильно, потому что тот, кто строит,
	 * знает, что ломать.
	 */
	public function invalidate(): void
	{
		$this->cache->forget('trinity.routes');
		$this->cache->forgetByPrefix('trinity.routes.raw.');

		$matcherFile = $this->basePath . '/var/cache/TrinityUrlMatcher.php';
		if (file_exists($matcherFile)) {
			@unlink($matcherFile);

			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($matcherFile, true);
			}
		}
	}
}