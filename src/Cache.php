<?php
declare(strict_types=1);

/**
 * КЭШ НА APCU
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

interface CacheInterface
{
	public function remember(string $key, callable $callback, int $ttl = 300): mixed;
	public function forget(string $key): void;
	public function forgetByPrefix(string $prefix): void;
	public function isEnabled(): bool;
}

class Cache implements CacheInterface
{
	/** @var bool Включен ли кэш. Из .env. */
	private bool $enabled;

	/** @var bool Доступен ли APCu. Если нет — кэш не работает. */
	private bool $apcuAvailable;

	/**
	 * Конструктор. Принимает флаг из .env.
	 *
	 * @param bool $enabled — включен ли кэш (из .env)
	 */
	public function __construct(bool $enabled = false)
	{
		$this->enabled = $enabled;
		$this->apcuAvailable = extension_loaded('apcu') && function_exists('apcu_fetch');

			if (!$this->apcuAvailable) {
			$this->enabled = false;
		}
	}

	private function isActive(): bool
	{
		return $this->enabled && $this->apcuAvailable;
	}

	/**
	 * Получить значение из кэша или создать новое.
	 *
	 * @param string $key — ключ кэша
	 * @param callable $callback — функция для создания значения
	 * @param int $ttl — время жизни в секундах
	 * @return mixed
	 */
	public function remember(string $key, callable $callback, int $ttl = 300): mixed
	{
		if (!$this->isActive()) {
			return $callback();
		}

		$wrapped = apcu_entry($key, function () use ($callback) {
			return ['v' => $callback()];
		}, $ttl);

		return $wrapped['v'] ?? null;
	}


	/**
	 * Удалить значение из кэша.
	 *
	 * @param string $key — ключ кэша
	 */
	public function forget(string $key): void
	{
		if (!$this->isActive()) {
			return;
		}

		apcu_delete($key);
	}

	/**
	 * Включен ли кэш.
	 */
	public function isEnabled(): bool
	{
		return $this->isActive();
	}

	/**
	 * Удаляет все ключи кэша с заданным префиксом.
	 *
	 * @param string $prefix
	 */
	public function forgetByPrefix(string $prefix): void
	{
		if (!$this->isActive()) return;

		$iterator = new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');
		foreach ($iterator as $item) {
			apcu_delete($item['key']);
		}
	}
}