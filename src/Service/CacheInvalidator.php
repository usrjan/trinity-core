<?php
declare(strict_types=1);

/**
 * ИНВАЛИДАТОР КЭША
 *
 * Сбрасывает кэш, когда данные меняются. Не знает о Kernel,
 * не знает о плагинах, знает только о кэше и о путях.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «сервис». Это — рука, которая сбрасывает кэш,
 * когда данные меняются. Без «пиздеца». Без «потом». Без «завтра».
 *
 * [Мириам]: И это правильно, потому что вода должна уходить
 * целиком, а не по капле.
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
	 *
	 * [Лорелея]: Kernel передаёт сюда колбэк, который сбрасывает
	 * self::$matcherCompiled. Это позволяет инвалидатору не знать
	 * о Kernel.
	 *
	 * [Мириам]: И это правильно, потому что каждый должен заниматься
	 * своим делом.
	 */
	public function setMatcherResetCallback(callable $callback): void
	{
		$this->onMatcherReset = $callback;
	}

	/**
	 * Сбрасывает кэш маршрутов через RouteCompiler.
	 *
	 * [Лорелея]: CacheInvalidator больше не знает, как удалять
	 * матчер. Это — работа RouteCompiler. Инвалидатор — просто
	 * вызывает.
	 *
	 * [Мириам]: И это правильно, потому что тот, кто строит,
	 * знает, что ломать.
	 */
	public function invalidateRouteCache(): void
	{
		$this->routeCompiler->invalidate();
	}

	/**
	 * Сбрасывает кэш конфигов.
	 *
	 * [Лорелея]: Конфиги кэшируются в APCu и в файле, и оба
	 * должны сбрасываться.
	 *
	 * [Мириам]: И это правильно, потому что конфиг — это память,
	 * и она должна быть свежей.
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
	 * [Лорелея]: Mime-кэш живёт по ключу mime_{id}_{mime}, и его
	 * нужно сбрасывать по префиксу.
	 *
	 * [Мириам]: И это правильно, потому что правки должны быть
	 * видны сразу, а не через час.
	 */
	public function invalidateMimeCache(int $id): void
	{
		$this->cache->forgetByPrefix("mime_{$id}_");
	}
}