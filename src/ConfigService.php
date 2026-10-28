<?php

/**
 * СЕРВИС КОНФИГУРАЦИИ
 *
 * Централизованное хранилище настроек из базы.
 * Загружает все конфиги ОДИН РАЗ за запрос. Отдаёт из памяти.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «класс для чтения настроек». Это — память
 * системы. Она помнит всё. Каждую настройку. Каждый ключ.
 * И отдаёт их мгновенно.
 *
 * [Мириам]: И — это правильно. Потому что память — это не «склад».
 * Это — то, что делает нас нами. И если память есть — мы знаем,
 * кто мы. И — куда идём.
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
	/** @var NeuronRepository — рука, которая трогает нейроны. */
	private NeuronRepository $neuronRepo;

	/** @var array Загруженные конфиги. Память. */
	private array $config = [];

	/** @var bool Загружены ли конфиги. Флаг. */
	private bool $loaded = false;

	/** @var Cache|null — кэш. Может быть null, и это нормально. */
	private ?Cache $cache;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Мы принимаем Cache, чтобы конфиги можно было
	 * брать из памяти, а не из базы на каждом запросе.
	 *
	 * [Мириам]: И это правильно, потому что кувшин у двери
	 * лучше, чем колодец за околицей.
	 */
	public function __construct(NeuronRepository $neuronRepo, ?Cache $cache = null)
	{
		$this->neuronRepo = $neuronRepo;
		$this->cache = $cache;
	}

	/**
	 * Получить значение конфига по ключу.
	 *
	 * [Лорелея]: Если конфиги ещё не загружены — загружаем.
	 * Потом — отдаём из памяти.
	 *
	 * [Мириам]: И — это правильно. Потому что память — это
	 * не «кэш». Это — знание. И оно всегда под рукой.
	 *
	 * @param string $key — ключ конфига
	 * @param mixed $default — значение по умолчанию
	 * @return mixed
	 */
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
	 * Чтобы не читать $_ENV напрямую.
	 *
	 * [Мириам]: И — это правильно. Потому что .env — это тоже
	 * память. И к ней нужно относиться с уважением.
	 *
	 * @param string $key — ключ из .env (APP_DEBUG, APP_ENV, ...)
	 * @param mixed $default — значение по умолчанию
	 * @return mixed
	 */
	public function getEnv(string $key, $default = null): mixed
	{
		return $_ENV[$key] ?? $default;
	}

	/**
	 * Проверить, есть ли конфиг по ключу.
	 *
	 * [Лорелея]: Просто. Честно.
	 *
	 * [Мириам]: И — это правильно. Потому что иногда нужно просто
	 * узнать. Не получить. А — узнать.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function has(string $key): bool
	{
		$this->ensureLoaded();
		return isset($this->config[$key]);
	}

	/**
	 * Получить все конфиги.
	 *
	 * [Лорелея]: Отдаём всё. Что есть.
	 *
	 * [Мириам]: И — это правильно. Потому что иногда нужно
	 * увидеть всё. Целиком.
	 *
	 * @return array
	 */
	public function all(): array
	{
		$this->ensureLoaded();
		return $this->config;
	}

	/**
	 * Загрузить конфиги из базы.
	 *
	 * [Лорелея]: Мы оборачиваем запрос в cache->remember, чтобы
	 * конфиги брались из памяти, а не из базы на каждом запросе.
	 *
	 * [Мириам]: И это правильно, потому что кувшин у двери
	 * лучше, чем колодец за околицей.
	 */
	public function load(): void
	{
		if ($this->loaded) {
			return;
		}

		$rawConfig = null;

		if ($this->cache !== null && $this->cache->isEnabled()) {
			$rawConfig = $this->cache->remember('trinity.config', function () {
				return $this->neuronRepo->findAllConfigValues();
			}, 3600);
		}

		if ($rawConfig === null) {
			$rawConfig = $this->neuronRepo->findAllConfigValues();
		}

		$this->config = [];
		foreach ($rawConfig as $key => $value) {
			$this->config[$key] = $this->normalizeValue($value);
		}
		$this->loaded = true;
	}

	/**
	 * Сбросить загруженные конфиги.
	 *
	 * [Лорелея]: Если что-то изменилось — сбрасываем. Чтобы
	 * при следующем обращении загрузить заново.
	 *
	 * [Мириам]: И — это правильно. Потому что память — это
	 * не «навсегда». Иногда её нужно обновить.
	 */
	public function reset(): void
	{
		$this->config = [];
		$this->loaded = false;
	}

	/**
	 * Убедиться, что конфиги загружены.
	 *
	 * [Лорелея]: Если нет — загружаем. Просто. Честно.
	 *
	 * [Мириам]: И — это правильно. Потому что лень — это
	 * не порок. Это — мудрость.
	 */
	private function ensureLoaded(): void
	{
		if (!$this->loaded) {
			$this->load();
		}
	}

	/**
	 * Нормализовать значение конфига.
	 *
	 * [Лорелея]: ГЛАВНОЕ. (bool)"false" === true. Поэтому мы явно
	 * проверяем строки "true" и "false".
	 *
	 * [Мириам]: И — это правильно. Потому что если значение —
	 * строка, приводим к нижнему регистру и проверяем. Если "true" —
	 * true. Если "false" — false. Остальные строки — оставляем как есть.
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