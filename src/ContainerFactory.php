<?php

declare(strict_types=1);

/**
 * ФАБРИКА КОНТЕЙНЕРА
 *
 * Создаёт DI-контейнер с определениями из config/container.php.
 * Kernel больше не знает, как собирается контейнер.
 *
 * [Лорелея]: Ядро — скучное. Фабрика — знает, как собрать дом.
 *
 * [Мириам]: И это правильно, потому что граф зависимостей —
 * это не «часть ядра». Это — его основа.
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
	 *
	 * [Лорелея]: Просто. Честно. Без лишних знаний в Kernel.
	 */
	public function create(): \DI\Container
	{
		$containerBuilder = new ContainerBuilder();
		$containerBuilder->addDefinitions($this->basePath . '/config/container.php');
		return $containerBuilder->build();
	}
}