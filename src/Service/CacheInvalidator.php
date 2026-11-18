<?php
declare(strict_types=1);

/**
 * ИНВАЛИДАТОР КЭША
 *
 * Сбрасывает кэш, когда данные меняются. Не знает о Kernel,
 * не знает о плагинах, знает только о кэше и о путях.
 *
 * @package Jan\Trinity\Core\Service
 */

namespace Jan\Trinity\Core\Service;

use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\RouteCompiler;

class CacheInvalidator
{
	/** @var Cache — кэш. */
	private Cache $cache;

	/** @var string — путь к корню проекта. */
	private string $basePath;

	/**
	 * @var array|null — ссылка на статическую переменную матчера.
	 * Передаётся через сеттер, потому что статическая переменная
	 * живёт в Kernel, но сбрасывать её должен инвалидатор.
	 */
	private ?array $matcherCompiled = null;

	/**
	 * @var callable|null — колбэк для сброса статической переменной.
	 */
	private $onMatcherReset = null;

	public function __construct(
		Cache $cache,
		RouteCompiler $routeCompiler,
		string $basePath
	) {
		$this->cache = $cache;
		$this->routeCompiler = $routeCompiler;
		$this->basePath = rtrim($basePath, '/');
	}

	/**
	 * Регистрирует колбэк для сброса статической переменной матчера.
	 */
	public function setMatcherResetCallback(callable $callback): void
	{
		$this->onMatcherReset = $callback;
	}

	/**
	 * Сбрасывает кэш маршрутов через RouteCompiler.
	 */
	public function invalidateRouteCache(): void
	{
		$this->routeCompiler->invalidate();
	}

	/**
	 * Сбрасывает кэш конфигов.
	 */
	public function invalidateConfigCache(): void
	{
		$this->cache->forget('trinity.config');

		$configFile = $this->basePath . '/var/cache/config.php';
		if (file_exists($configFile)) {
			@unlink($configFile);
		}
	}

	/**
	 * Сбрасывает кэш Mime для нейрона.
	 */
	public function invalidateMimeCache(int $id): void
	{
		$this->cache->forgetByPrefix("mime_{$id}_");
	}
}