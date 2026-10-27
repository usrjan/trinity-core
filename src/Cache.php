<?php

/**
 * КЭШ НА APCU
 * ===========
 * 
 * Быстрый, в памяти, с атомарными операциями.
 * Без файлов. Без блокировок. Без гонок.
 * 
 * === ПРИНЦИП РАБОТЫ ===
 * - Данные хранятся в памяти (APCu).
 * - Ключ → значение. С TTL.
 * - Отключается через CACHE_ENABLED=false.
 * - Не кэширует null.
 * 
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Раньше был файловый кэш. С flock, с rename, с unlink.
 * И это было медленно. И — гонки. Теперь — APCu. Быстро. Атомарно.
 * И — правильно.
 * 
 * [Мириам]: APCu — это как Башня, которая ждёт. Только теперь она
 * не на диске, а в памяти. И она быстрее. И — надёжнее.
 * 
 * === КНИГА ===
 * Глава 6. Башня которая ждёт.
 * 
 * "Зачем ты?" — спросила я.
 * "Я храню то, что уже было посчитано," — ответила Башня.
 * "Чтобы не считать заново."
 * 
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

class Cache
{
	/** @var bool Включен ли кэш */
	private bool $enabled;

	/**
	 * @param bool $enabled — включен ли кэш (из .env)
	 */
	public function __construct(bool $enabled = false)
	{
		$this->enabled = $enabled;
	}

	/**
	 * Получить значение из кэша или создать новое.
	 * 
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Раньше был файловый кэш.
	 * Теперь — APCu. Это быстрее. И — атомарно. Без гонок.
	 * 
	 * [Мириам]: null не кэшируем. Если callback вернул null —
	 * просто отдаём его, но в кэш не пишем. При следующем вызове
	 * снова пойдём в callback. Это честнее.
	 * 
	 * @param string $key — ключ кэша
	 * @param callable $callback — функция для создания значения
	 * @param int $ttl — время жизни в секундах (по умолчанию 300 = 5 минут)
	 * @return mixed
	 */
	public function remember(string $key, callable $callback, int $ttl = 300): mixed
	{
		// Если кэш отключен — просто выполняем callback
		if (!$this->enabled) {
			return $callback();
		}

		// [Лорелея]: Проверяем, есть ли значение в APCu.
		// apcu_exists() — быстрее, чем apcu_fetch().
		if (apcu_exists($key)) {
			$value = apcu_fetch($key);
			if ($value !== false) {
				return $value;
			}
		}

		// Создаём новое значение
		$value = $callback();

		// [Мириам]: null не кэшируем. Если callback вернул null —
		// просто отдаём его, но в кэш не пишем.
		if ($value === null) {
			return null;
		}

		// [Лорелея]: Сохраняем в APCu. С TTL.
		apcu_store($key, $value, $ttl);

		return $value;
	}

	/**
	 * Удалить значение из кэша.
	 * 
	 * [Лорелея]: Теперь используем APCu. Это быстрее и безопаснее.
	 * [Мириам]: APCu работает в памяти. И — атомарные операции.
	 * 
	 * @param string $key — ключ кэша
	 */
	public function forget(string $key): void
	{
		if (!$this->enabled) return;

		apcu_delete($key);
	}

	/**
	 * Очистить весь кэш.
	 * 
	 * [Лорелея]: APCu позволяет очистить весь кэш одной командой.
	 * [Мириам]: Это быстрее, чем удалять файлы по одному.
	 */
	public function clear(): void
	{
		if (!$this->enabled) return;

		apcu_clear_cache();
	}

	/**
	 * Включен ли кэш.
	 * 
	 * [Лорелея]: Этот метод используется в Kernel и других местах.
	 * [Мириам]: Он просто возвращает $this->enabled.
	 */
	public function isEnabled(): bool
	{
		return $this->enabled;
	}
}