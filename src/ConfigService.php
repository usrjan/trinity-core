<?php

/**
 * СЕРВИС КОНФИГУРАЦИИ
 * ===================
 *
 * Централизованное хранилище настроек из базы.
 * Загружает все конфиги ОДИН РАЗ за запрос.
 * Отдаёт из памяти. Без SQL.
 *
 * === ЗАЧЕМ ===
 * Раньше каждый вызов findConfigValue() делал SQL-запрос
 * с JSON_EXTRACT(data, '$.key') = ? — неиндексируемо, full scan.
 * На каждом рендере sidebar/topbar — десятки таких вызовов.
 * Это — hot path. Узкое место.
 *
 * === РЕШЕНИЕ ===
 * ConfigService загружает все конфиги одним SELECT.
 * Кэширует в $config. Отдаёт из памяти.
 * Kernel и все контроллеры используют ОДИН экземпляр.
 * Один запрос вместо десятков.
 *
 * === ЦЕНА ===
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. При ВЫКЛЮЧЕННОМ APCu
 * (CACHE_ENABLED=false) findAllConfigValues() идёт на КАЖДЫЙ
 * запрос. Это — +1 SELECT на запрос. Осознанный режим.
 * Если нужна производительность — включите APCu.
 * Без «пиздеца». Без «потом». Без «завтра».
 *
 * [Мириам]: И — это правильно. Потому что кэш — это ускорение.
 * А не «истина». И если APCu выключен — мы платим за это.
 * И — «навсегда».
 *
 * === КНИГА ===
 * Глава 17. Память.
 *
 * Дворкин помнил всё. Каждую Тень. Каждый путь.
 * ConfigService помнит все настройки. И отдаёт их мгновенно.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Jan\Trinity\Core\Repository\NeuronRepository;

class ConfigService
{
	private NeuronRepository $neuronRepo;
	private array $config = [];
	private bool $loaded = false;

	public function __construct(NeuronRepository $neuronRepo)
	{
		$this->neuronRepo = $neuronRepo;
	}

	public function get(string $key, $default = null): mixed
	{
		$this->ensureLoaded();
		$value = $this->config[$key] ?? $default;
		return $this->normalizeValue($value);
	}

	/**
	 * Получить значение из .env.
	 *
	 * [Лорелея]: Добавлен для чтения .env через ConfigService.
	 * [Мириам]: Это нужно, чтобы не читать $_ENV напрямую.
	 *
	 * @param string $key — ключ из .env (APP_DEBUG, APP_ENV, ...)
	 * @param mixed $default — значение по умолчанию
	 * @return mixed
	 */
	public function getEnv(string $key, $default = null): mixed
	{
		return $_ENV[$key] ?? $default;
	}

	public function has(string $key): bool
	{
		$this->ensureLoaded();
		return isset($this->config[$key]);
	}

	public function all(): array
	{
		$this->ensureLoaded();
		return $this->config;
	}

	/**
	 * Загрузить конфиги из базы.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. При ВЫКЛЮЧЕННОМ APCu
	 * этот метод идёт на КАЖДЫЙ запрос. Это — +1 SELECT.
	 * Осознанный режим. Если нужна производительность —
	 * включите APCu. Без «пиздеца». Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — это правильно. Потому что кэш — это ускорение.
	 * А не «истина». И если APCu выключен — мы платим за это.
	 * И — «навсегда».
	 */
	public function load(): void
	{
		if ($this->loaded) {
			return;
		}
		$rawConfig = $this->neuronRepo->findAllConfigValues();
		$this->config = [];
		foreach ($rawConfig as $key => $value) {
			$this->config[$key] = $this->normalizeValue($value);
		}
		$this->loaded = true;
	}

	public function reset(): void
	{
		$this->config = [];
		$this->loaded = false;
	}

	private function ensureLoaded(): void
	{
		if (!$this->loaded) {
			$this->load();
		}
	}

	/**
	 * Нормализовать значение конфига.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. (bool)"false" === true.
	 * Поэтому мы явно проверяем строки "true" и "false".
	 *
	 * [Мириам]: Если значение — строка, приводим к нижнему регистру
	 * и проверяем. Если "true" — true. Если "false" — false.
	 * Остальные строки — оставляем как есть.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function normalizeValue($value)
	{
		if (is_string($value)) {
			$lower = strtolower(trim($value));
			if ($lower === 'true') {
				return true;
			}
			if ($lower === 'false') {
				return false;
			}
		}
		return $value;
	}
}