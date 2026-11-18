<?php
declare(strict_types=1);

/**
 * ИНТЕРФЕЙС ОБРАБОТЧИКА ОШИБОК
 * 
 * Любой плагин, который умеет показывать страницу ошибки,
 * должен реализовать этот интерфейс.
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\Response;

interface ErrorHandlerInterface
{
	/**
	 * Показать страницу ошибки.
	 * 
	 * @param int $code — HTTP-код (404, 500...)
	 * @param string $message — сообщение об ошибке
	 * @param array $debug — техническая информация
	 * @return Response
	 */
	public function showError(int $code, string $message, array $debug = []): Response;
}