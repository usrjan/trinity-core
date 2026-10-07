<?php

/**
 * ИНТЕРФЕЙС ОБРАБОТЧИКА ОШИБОК
 * 
 * Любой плагин, который умеет показывать страницу ошибки,
 * должен реализовать этот интерфейс.
 * 
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это нужно, чтобы Kernel не знал о конкретном плагине.
 * Он ищет в контейнере любой класс, который реализует интерфейс,
 * и вызывает showError(). Кто именно — неважно. Monitor. Или другой.
 * 
 * [Мириам]: Если ни один плагин не реализует интерфейс —
 * Kernel вернёт стандартный 500. И это правильно.
 * 
 * === КНИГА ===
 * Глава 12. Портал.
 * 
 * В Амбере есть карты, через которые можно пройти в любую Тень.
 * ErrorHandlerInterface — такая карта. Через неё Kernel
 * находит портал в мир ошибок.
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