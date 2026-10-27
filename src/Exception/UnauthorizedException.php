<?php

namespace Jan\Trinity\Core\Exception;

class UnauthorizedException extends \RuntimeException
{
	public function __construct(string $message = 'Требуется авторизация')
	{
		parent::__construct($message, 401);
	}
}