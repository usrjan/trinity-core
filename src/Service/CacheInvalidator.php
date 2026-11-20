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

	/** @var RouteCompiler — компилятор маршрутов. */
	private RouteCompiler $routeCompiler;

	/** @var string — путь к корню проекта. */
	private string $basePath;

	/**
	 * Конструктор.
	 *
	 * @param Cache $cache
	 * @param RouteCompiler $routeCompiler
	 * @param string $basePath
	 */
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
	 *
	 * @param int $id — id нейрона
	 */
	public function invalidateMimeCache(int $id): void
	{
		$this->cache->forgetByPrefix("mime_{$id}_");
	}
}