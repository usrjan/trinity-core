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
	 * === ПОЧЕМУ НЕ ТОЛЬКО instanceof ===
	 * Если в классе, использующем trait, нет свойства
	 * $configService — $this->configService вызовет
	 * Error: Undefined property. А isset — вернёт false.
	 * Без isset — Error. С isset — false. И — безопасно.
	 *
	 * === ПОЧЕМУ НЕ ТОЛЬКО isset ===
	 * Если $configService объявлено, но равно null —
	 * isset вернёт false. И мы уйдём в fallback на $_ENV.
	 * Это — правильно. Но если $configService — не
	 * ConfigService (например, строка) — isset вернёт true.
	 * И getEnv() на строке — Error. А instanceof — false.
	 * Без instanceof — Error. С instanceof — false. И — безопасно.
	 *
	 * @return bool
	 */
	protected function isDebug(): bool
	{
		if (isset($this->configService) && $this->configService instanceof \Jan\Trinity\Core\ConfigService) {
			return $this->configService->getEnv('APP_DEBUG', 'false') === 'true';
		}

		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}