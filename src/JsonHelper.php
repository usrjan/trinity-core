<?php
declare(strict_types=1);

/**
 * ХЕЛПЕР ДЛЯ РАБОТЫ С JSON
 *
 * Единая точка для парсинга JSON-тела запроса.
 * Убирает дублирование из контроллеров.
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