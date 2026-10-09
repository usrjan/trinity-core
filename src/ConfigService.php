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
		return $this->config[$key] ?? $default;
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

	public function load(): void
	{
		if ($this->loaded) {
			return;
		}
		$this->config = $this->neuronRepo->findAllConfigValues();
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
}