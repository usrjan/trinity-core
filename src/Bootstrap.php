<?php

declare(strict_types=1);

/**
 * СБОРЩИК ДОМА
 *
 * Знает, как собрать приложение. Какой EnvironmentLoader, какой
 * ContainerFactory, какой RequestPipeline. И — в каком порядке.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Psr\Log\LoggerInterface;

class Bootstrap
{
	/** @var string Путь к корню проекта. */
	private string $basePath;

	/** @var \DI\Container DI-контейнер. */
	private \DI\Container $container;

	/** @var array Загруженные плагины. */
	private array $plugins = [];

	/** @var LoggerInterface|null Логгер. */
	private ?LoggerInterface $logger = null;

	/**
	 * Конструктор. Принимает путь. Не собирает. Сборка — в boot().
	 *
	 * @param string $basePath
	 */
	public function __construct(string $basePath)
	{
		$this->basePath = rtrim($basePath, '/');
	}

	/**
	 * Собрать дом. Загрузить окружение, создать контейнер,
	 * получить сервисы, загрузить плагины.
	 */
	public function boot(): void
	{
		$environmentLoader = new EnvironmentLoader($this->basePath);
		$environmentLoader->load();

		$containerFactory = new ContainerFactory($this->basePath);
		$this->container = $containerFactory->create();

		$this->loadLogger();
		$this->loadConfig();
		$this->loadPlugins();
	}

	/**
	 * Загрузить логгер и предупредить, если APCu недоступен.
	 */
	private function loadLogger(): void
	{
		if ($this->container->has(LoggerInterface::class)) {
			$this->logger = $this->container->get(LoggerInterface::class);
		}

		if ($this->container->has(Cache::class)) {
			$cache = $this->container->get(Cache::class);

			if ($cache !== null && !$cache->isEnabled() && $this->logger !== null) {
				$this->logger->warning('APCu is not available. Cache is disabled. Install php-apcu for production.');
			}
		}
	}

	/**
	 * Загрузить конфиги из базы. С fail-safe — потому что
	 * дом должен стоять, даже если конфиг упал.
	 */
	private function loadConfig(): void
	{
		$configService = $this->container->get(ConfigService::class);
		$configService->loadWithFailSafe($this->logger);

		ApiResponse::setConfigService($configService);
	}

	/**
	 * Загрузить плагины. Каждый плагин — замыкание.
	 */
	private function loadPlugins(): void
	{
		$pluginLoader = $this->container->get(PluginLoader::class);
		$this->plugins = $pluginLoader->load();
	}

	/**
	 * Контейнер. Для тех, кто хочет получить сервис после сборки.
	 */
	public function getContainer(): \DI\Container
	{
		return $this->container;
	}

	/**
	 * Плагины. Для RouteCompiler — он регистрирует маршруты.
	 */
	public function getPlugins(): array
	{
		return $this->plugins;
	}

	/**
	 * Логгер. Для тех, кому он нужен после сборки.
	 */
	public function getLogger(): ?LoggerInterface
	{
		return $this->logger;
	}
}