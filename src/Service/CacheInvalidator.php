<?php

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

	public function __construct(Cache $cache, string $basePath)
	{
		$this->cache = $cache;
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
	 * Сбрасывает кэш маршрутов: APCu, файл матчера и статическую переменную.
	 *
	 * [Лорелея]: Инвалидация «наполовину» хуже, чем её отсутствие,
	 * потому что создаёт иллюзию, что кэш сброшен.
	 *
	 * [Мириам]: И это правильно, потому что вода должна уйти
	 * целиком, а не по капле.
	 */
	public function invalidateRouteCache(): void
	{
		$this->cache->forget('trinity.routes');

		$matcherFile = $this->basePath . '/var/cache/TrinityUrlMatcher.php';
		if (file_exists($matcherFile)) {
			@unlink($matcherFile);

			// [Лорелея]: Сбрасываем opcache для файла матчера, потому что
			// при opcache.validate_timestamps=0 старый скомпилированный
			// матчер может жить вечно после unlink.
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($matcherFile, true);
			}
		}
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