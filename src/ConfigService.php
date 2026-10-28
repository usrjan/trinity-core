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

	/** @var string — путь к корню проекта. */
	private string $basePath;

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
		string $basePath,
		?Cache $cache = null
	) {
		$this->neuronRepo = $neuronRepo;
		$this->basePath = rtrim($basePath, '/');
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
	 * Загрузить конфиги из базы.
	 *
	 * [Лорелея]: Мы используем три уровня кэша: APCu, файл и базу.
	 * APCu быстрый, файл — надёжный, база — источник истины.
	 *
	 * [Мириам]: И это правильно, потому что кувшин должен быть
	 * у двери, а не за околицей.
	 */
	public function load(): void
	{
		if ($this->loaded) {
			return;
		}

		$rawConfig = null;

		// [Лорелея]: Первый уровень — APCu.
		if ($this->cache !== null && $this->cache->isEnabled()) {
			$rawConfig = $this->cache->remember('trinity.config', function () {
				return $this->loadFromFile();
			}, 3600);
		}

		// [Мириам]: Второй уровень — файл. Если APCu нет или он
		// выключен, файл всё равно быстрый, потому что это require.
		if ($rawConfig === null) {
			$rawConfig = $this->loadFromFile();
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

	/**
	 * Загружает конфиги из файлового кэша или из базы.
	 *
	 * [Лорелея]: Если файл кэша есть и не устарел — берём из него.
	 * Если нет или устарел — идём в базу и записываем файл.
	 *
	 * [Мириам]: И это правильно, потому что файл — это надёжный
	 * запасной путь, который работает даже без APCu.
	 *
	 * @return array|null
	 */
	private function loadFromFile(): ?array
	{
		$cacheFile = $this->basePath . '/var/cache/config.php';

		// [Лорелея]: Если файл кэша есть и не устарел — берём из него.
		if (file_exists($cacheFile) && !$this->isConfigCacheStale($cacheFile)) {
			$cached = require $cacheFile;
			if (is_array($cached)) {
				return $cached;
			}

			// [Мириам]: Если файл вернул не массив — значит, он битый.
			// Удаляем его и идём в базу.
			@unlink($cacheFile);
		}

		// [Лорелея]: Идём в базу и записываем файл, чтобы следующий
		// запрос был быстрым.
		$rawConfig = $this->neuronRepo->findAllConfigValues();

		$code = '<?php' . "\n\n" . 'return ' . var_export($rawConfig, true) . ';' . "\n";
		file_put_contents($cacheFile, $code, LOCK_EX);

		return $rawConfig;
	}

	/**
	 * Проверяет, устарел ли файловый кэш конфигов.
	 *
	 * [Лорелея]: Кэш устаревает, когда меняются нейроны type='config',
	 * и это правильно, потому что конфиги должны отражать реальность.
	 *
	 * [Мириам]: И это правильно, потому что устаревший конфиг
	 * хуже, чем его отсутствие.
	 *
	 * @param string $cacheFile
	 * @return bool
	 */
	private function isConfigCacheStale(string $cacheFile): bool
	{
		$cacheTime = filemtime($cacheFile);
		if ($cacheTime === false) {
			return true;
		}

		$conn = $this->neuronRepo->getConnection();
		$lastConfigChange = $conn->executeQuery(
			"SELECT MAX(date) FROM neuron WHERE type = 'config' AND is_deleted = 0"
		)->fetchOne();

		if (!$lastConfigChange) {
			return false;
		}

		return strtotime($lastConfigChange) > $cacheTime;
	}
}