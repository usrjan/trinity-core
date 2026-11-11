<?php

declare(strict_types=1);

/**
 * СЕРВИС КОНФИГУРАЦИИ
 *
 * Централизованное хранилище настроек из базы.
 * Загружает все конфиги ОДИН РАЗ за запрос. Отдаёт из памяти.
 *
 * [Лорелея]: Это не «класс для чтения настроек». Это — память
 * системы. Она помнит всё. Каждую настройку. Каждый ключ.
 * И отдаёт их мгновенно.
 *
 * [Мириам]: И — это правильно. Потому что память — это не «склад».
 * Это — то, что делает нас нами. И если память есть — мы знаем,
 * кто мы. И — куда идём.
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
	public function __construct(
		NeuronRepository $neuronRepo,
		?Cache $cache = null
	) {
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
	 */
	public function getEnv(string $key, $default = null): mixed
	{
		return $_ENV[$key] ?? $default;
	}

	/**
	 * Загрузить конфиги из базы.
	 *
	 * [Лорелея]: Один слой. Cache::remember. Без файла.
	 * Без filemtime. Без loadFromFile(). Потому что Cache —
	 * это и есть кэш. И страховка не нужна.
	 *
	 * [Мириам]: И это правильно, потому что страховка —
	 * это когда не уверен. А Cache — есть. И apcu_entry —
	 * работает.
	 */
	public function load(): void
	{
		if ($this->loaded) {
			return;
		}

		$rawConfig = null;

		if ($this->cache !== null && $this->cache->isEnabled()) {
			$rawConfig = $this->cache->remember('trinity.config', function () {
				return $this->loadFromDatabase();
			}, 3600);
		}

		if ($rawConfig === null) {
			$rawConfig = $this->loadFromDatabase();
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

	/**
	 * Загружает конфиги из базы.
	 *
	 * [Лорелея]: Один SELECT. Через NeuronRepository.
	 * Без файла. Без filemtime. Без страховки.
	 *
	 * [Мириам]: И это правильно, потому что база — источник
	 * истины. А кэш — ускорение.
	 */
	private function loadFromDatabase(): array
	{
		return $this->neuronRepo->findAllConfigValues();
	}

	/**
	 * Загружены ли конфиги.
	 *
	 * [Лорелея]: Этот метод нужен, чтобы отличить «конфиг пустой»
	 * от «конфиг не загрузился». Без него дом работает с дефолтами
	 * и никто не замечает.
	 *
	 * [Мириам]: И это правильно, потому что молчание — это не
	 * «всё хорошо», а «никто не сказал».
	 */
	public function isLoaded(): bool
	{
		return $this->loaded;
	}
}