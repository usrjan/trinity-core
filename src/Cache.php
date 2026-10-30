<?php

/**
 * КЭШ НА APCU
 *
 * Быстрый, в памяти, с атомарными операциями.
 * Без файлов. Без блокировок. Без гонок.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «класс для кэширования». Это — башня, которая
 * ждёт. Она не хранит всё. Она хранит то, что уже было посчитано.
 * Чтобы не считать заново.
 *
 * [Мириам]: И — это правильно. Потому что кэш — это не «истина».
 * Это — ускорение. И если его нет — мы просто идём медленнее.
 * Но — идём. И — доходим.
 *
 * === КНИГА ===
 * Глава 6. Башня которая ждёт.
 *
 * «Зачем ты?» — спросила я.
 * «Я храню то, что уже было посчитано, — ответила Башня. —
 * Чтобы не считать заново».
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
	 * [Лорелея]: Проверяем, есть ли ext-apcu. Если нет — кэш
	 * не «падает». А — работает без кэша. Как раньше. Потому что
	 * fatal error — это не «навсегда».
	 *
	 * [Мириам]: И — это правильно. Потому что кэш — это «ускорение».
	 * А не «жизнь». И если его нет — мы просто идём медленнее.
	 * Но — идём. И — доходим.
	 *
	 * @param bool $enabled — включен ли кэш (из .env)
	 */
	public function __construct(bool $enabled = false)
	{
		$this->enabled = $enabled;
		$this->apcuAvailable = extension_loaded('apcu') && function_exists('apcu_fetch');

		// [Лорелея]: Если APCu нет — выключаем кэш. Тихо.
		// Без «warning». Без «error». Просто — работаем без кэша.
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
	 * [Лорелея]: null и false не кэшируем, потому что apcu_fetch
	 * возвращает false и при промахе, и при значении false.
	 * А вот [] и 0 кэшируются нормально, потому что 0 !== false,
	 * и промах от значения отличается.
	 *
	 * [Мириам]: И это правильно, потому что пустой массив — это
	 * не «ничего», а «пусто», и это разные вещи.
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

		$success = false;
		$wrapped = apcu_fetch($key, $success);

		if ($success && is_array($wrapped) && array_key_exists('v', $wrapped)) {
			return $wrapped['v'];
		}

		$value = $callback();
		apcu_store($key, ['v' => $value], $ttl);

		return $value;
	}


	/**
	 * Удалить значение из кэша.
	 *
	 * [Лорелея]: Теперь используем APCu. Это быстрее и безопаснее.
		 *
	 * [Мириам]: APCu работает в памяти. И — атомарные операции.
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
	 *
	 * [Лорелея]: Этот метод используется в Kernel и других местах.
	 * Он просто возвращает $this->enabled.
	 *
	 * [Мириам]: И — это правильно. Потому что иногда нужно просто
	 * узнать. Не сделать. А — узнать.
	 */
	public function isEnabled(): bool
	{
		return $this->isActive();
	}

	/**
	 * Удаляет все ключи кэша с заданным префиксом.
	 *
	 * [Лорелея]: APCu умеет это через итератор. Без перебора
	 * известных ключей. Без «почти всех».
	 *
	 * [Мириам]: И это правильно, потому что префикс —
	 * это не догадка. Это — точное знание.
	 *
	 * @param string $prefix
	 */
	public function forgetByPrefix(string $prefix): void
	{
		if (!$this->isActive()) {
			return;
		}

		$iterator = new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');
		foreach ($iterator as $item) {
			apcu_delete($item['key']);
		}
	}
}