<?php

namespace Jan\Trinity\Core\Exception;

class ForbiddenException extends \RuntimeException
{
	public function __construct(string $message = 'Доступ запрещён')
	{
		parent::__construct($message, 403);
	}
}