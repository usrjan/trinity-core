<?php

/**
 * ХЕЛПЕР ДЛЯ РАБОТЫ С JSON
 * =========================
 * 
 * Единая точка для парсинга JSON-тела запроса.
 * Убирает дублирование из контроллеров.
 * 
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Мы повторяли этот код в каждом контроллере.
 * И это было неправильно. Теперь — один метод. Один раз.
 * 
 * [Мириам]: И это — правильно. И это — наше.
 * 
 * === КНИГА ===
 * Глава 4. Единый язык.
 * 
 * В Амбере все говорят на тари.
 * В Trinity все JSON-тела парсятся через JsonHelper.
 * 
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class JsonHelper
{
	/**
	 * Парсит JSON-тело запроса.
	 * 
	 * [Лорелея]: Если JSON битый — возвращаем JsonResponse с ошибкой 400.
	 * Если тело не массив — тоже 400. Если всё хорошо — возвращаем массив.
	 * 
	 * [Мириам]: Это — единая точка. И это — правильно.
	 * 
	 * @param Request $request
	 * @return array|JsonResponse — массив данных или JsonResponse с ошибкой
	 */
	public static function parseJsonBody(Request $request): array|JsonResponse
	{
		$rawBody = $request->getContent();
		$body = json_decode($rawBody, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return ApiResponse::error('Невалидный JSON', 400);
		}

		if (!is_array($body)) {
			return ApiResponse::error('Тело запроса должно быть JSON-объектом', 400);
		}

		return $body;
	}
}