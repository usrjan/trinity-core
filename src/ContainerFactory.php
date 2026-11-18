<?php

declare(strict_types=1);

/**
 * ФАБРИКА КОНТЕЙНЕРА
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use DI\ContainerBuilder;

class ContainerFactory
{
	private string $basePath;

	public function __construct(string $basePath)
	{
		$this->basePath = rtrim($basePath, '/');
	}

	/**
	 * Создаёт контейнер.
	 */
	public function create(): \DI\Container
	{
		$containerBuilder = new ContainerBuilder();
		$containerBuilder->addDefinitions($this->basePath . '/config/container.php');
		return $containerBuilder->build();
	}
}