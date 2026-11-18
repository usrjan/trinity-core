<?php
declare(strict_types=1);

/**
 * ИСКЛЮЧЕНИЕ: ДОСТУП ЗАПРЕЩЁН
 *
 * Выбрасывается, когда у пользователя нет прав на действие.
 * Код 403.
 *
 * @package Jan\Trinity\Core\Exception
 */

namespace Jan\Trinity\Core\Exception;

class ForbiddenException extends \RuntimeException
{
	/**
	 * Конструктор. Принимает сообщение. По умолчанию — «Доступ запрещён».
	 *
	 * @param string $message
	 */
	public function __construct(string $message = 'Доступ запрещён')
	{
		parent::__construct($message, 403);
	}
}