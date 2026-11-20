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

	/**
	 * Активен ли кэш.
	 *
	 * @return bool
	 */
	private function isActive(): bool
	{
		return $this->enabled && $this->apcuAvailable;
	}

	/**
	 * Получить значение из кэша или создать новое.
	 *
	 * === ПОВЕДЕНИЕ ===
	 * - Если кэш выключен — вызывается $callback() напрямую.
	 * - Если $callback() вернул значение — оно кэшируется.
	 * - Если $callback() вернул null — null кэшируется (array_key_exists
	 *   отличает «ключ есть, значение null» от «ключа нет»).
	 * - Если $callback() бросил исключение — исключение пробросится,
	 *   кэш не запишется. Следующий вызов снова пойдёт в $callback().
	 * - Если APCu вернул аномалию (не массив, нет ключа 'v') —
	 *   это логируется, и вызывается $callback() напрямую.
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

		// [Лорелея]: apcu_entry вызывает callback только при промахе.
		// Если callback бросит — apcu_entry пробросит, APCu не закэширует.
		// Это правильно: ошибку не кэшируем. Следующий запрос попробует снова.
		$wrapped = apcu_entry($key, function () use ($callback) {
			return ['v' => $callback()];
		}, $ttl);

		// [Мириам]: Не ?? — а array_key_exists. Потому что ?? не отличает
		// «ключ есть, значение null» от «ключа нет». А нам нужно отличать.
		if (!is_array($wrapped) || !array_key_exists('v', $wrapped)) {
			// [Лорелея]: Это не «нормальный промах». Это — аномалия.
			// APCu вернул что-то не то. Логируем и идём в callback.
			error_log('[Trinity] Cache: apcu_entry returned unexpected value for key "' . $key . '"');
			return $callback();
		}

		return $wrapped['v'];
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