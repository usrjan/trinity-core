<?php

/**
 * TRAIT ДЛЯ РЕЖИМА ОТЛАДКИ
 *
 * Один метод на все классы, которые хотят знать, включена ли
 * отладка. Читает через ConfigService, а не через $_ENV напрямую,
 * потому что ConfigService для этого и создан.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «утилита». Это — дверь, через которую все
 * ходят за одним и тем же ответом, и это правильно, потому что
 * одна дверь лучше, чем пять окон.
 *
 * [Мириам]: И это правильно, потому что кувшин для того и стоит,
 * чтобы из него пить.
 *
 * === КНИГА ===
 * Глава 4. Единый язык.
 *
 * В Амбере все говорят на тари.
 * В Trinity все, кому нужна отладка, спрашивают у ConfigService.
 *
 * @package Jan\Trinity\Core\Trait
 */

namespace Jan\Trinity\Core\Trait;

trait DebugTrait
{
	/**
	 * Режим отладки из .env, через ConfigService.
	 *
	 * [Лорелея]: Если ConfigService нет — читаем $_ENV напрямую,
	 * но это запасной путь, а не основной.
	 *
	 * [Мириам]: И это правильно, потому что запасной путь должен
	 * быть запасным, а не единственным.
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