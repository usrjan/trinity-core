<?php

/**
 * TRAIT ДЛЯ РЕЖИМА ОТЛАДКИ
 *
 * Один метод на все классы, которые хотят знать, включена ли
 * отладка. Читает через ConfigService, а не через $_ENV напрямую,
 * потому что ConfigService для этого и создан.
 *
 * @package Jan\Trinity\Core\Trait
 */

namespace Jan\Trinity\Core\Trait;

trait DebugTrait
{
	/**
	 * Режим отладки из .env, через ConfigService.
	 *
	 * @return bool
	 */
	protected function isDebug(): bool
	{
		if (isset($this->configService) && $this->configService instanceof \Jan\Trinity\Core\ConfigService) {
			return ($this->configService->getEnv('APP_DEBUG', 'false') === 'true');
		}

		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}