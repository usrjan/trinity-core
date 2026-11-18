<?php
declare(strict_types=1);

/**
 * ИСКЛЮЧЕНИЕ: НЕ АВТОРИЗОВАН
 *
 * Выбрасывается, когда пользователь не вошёл в систему.
 * Код 401.
 *
 * @package Jan\Trinity\Core\Exception
 */

namespace Jan\Trinity\Core\Exception;

class UnauthorizedException extends \RuntimeException
{
	/**
	 * Конструктор. Принимает сообщение. По умолчанию — «Требуется авторизация».
	 *
	 * @param string $message
	 */
	public function __construct(string $message = 'Требуется авторизация')
	{
		parent::__construct($message, 401);
	}
}