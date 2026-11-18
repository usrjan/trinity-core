<?php

declare(strict_types=1);

/**
 * СЕРВИС КОНФИГУРАЦИИ
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;

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
	 */
	public function get(string $key, $default = null): mixed
	{
		$this->ensureLoaded();
		$value = $this->config[$key] ?? $default;
		return $this->normalizeValue($value);
	}

	/**
	 * Получить значение из .env.
	 */
	public function getEnv(string $key, $default = null): mixed
	{
		return $_ENV[$key] ?? $default;
	}

	/**
	 * Загрузить конфиги из базы.
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
	 */
	public function reset(): void
	{
		$this->config = [];
		$this->loaded = false;
	}

	/**
	 * Убедиться, что конфиги загружены.
	 */
	private function ensureLoaded(): void
	{
		if (!$this->loaded) {
			$this->load();
		}
	}

	/**
	 * Нормализовать значение конфига.
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
	 */
	private function loadFromDatabase(): array
	{
		return $this->neuronRepo->findAllConfigValues();
	}

	/**
	 * Загружены ли конфиги.
	 */
	public function isLoaded(): bool
	{
		return $this->loaded;
	}

	/**
	 * Загружает конфиги с обработкой ошибок.
	 */
	public function loadWithFailSafe(?LoggerInterface $logger = null): void
	{
		try {
			$this->load();
		} catch (\Throwable $e) {
			if ($logger !== null) {
				$logger->error('ConfigService load failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				]);
			} else {
				error_log('[Trinity] ConfigService load failed: ' . $e->getMessage());
			}

			if (($_ENV['APP_ENV'] ?? 'prod') === 'prod') {
				throw new \RuntimeException('Config load failed. See error.log.');
			}

			$this->config = [];
			$this->loaded = false;
		}
	}
}