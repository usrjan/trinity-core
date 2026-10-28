<?php

/**
 * ЕДИНЫЙ ФОРМАТ ОТВЕТОВ API
 *
 * Все контроллеры возвращают ответы через этот класс.
 * Никаких ручных json_encode, никаких разнобоев в формате.
 *
 * === ФОРМАТ ОТВЕТА ===
 * {
 *   "success": true/false,
 *   "data": ...,
 *   "message": "...",
 *   "meta": {
 *     "timestamp": "2026-06-27T10:00:00Z",
 *     "version": "1.0.2",
 *     "request_id": "abc123"
 *   },
 *   "error": null или {code, message}
 * }
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «класс для ответов». Это — язык. Один язык
 * для всех. Чтобы каждый плагин говорил одинаково. Чтобы гость
 * не путался.
 *
 * [Мириам]: И — это правильно. Потому что язык — это то, что
 * соединяет. И если язык один — мы понимаем друг друга.
 *
 * === КНИГА ===
 * Глава 4. Единый язык.
 *
 * В Амбере все говорят на тари.
 * В Trinity все ответы говорят на одном языке — ApiResponse.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponse
{
	/**
	 * Версия системы — из .env. Или 'unknown'.
	 *
	 * [Лорелея]: Одна точка правды.
	 *
	 * [Мириам]: И — это правильно. Потому что версия — это не «строчка».
	 * Это — знание.
	 *
	 * @return string
	 */
	public static function getVersion(): string
	{
		return $_ENV['APP_VERSION'] ?? 'unknown';
	}

	/**
	 * Успешный ответ.
	 *
	 * [Лорелея]: success = true. data — то, что просили.
	 * message — если есть. meta — всегда. error — null.
	 *
	 * [Мириам]: И — это правильно. Потому что успех — это когда
	 * всё получилось.
	 *
	 * @param mixed $data — данные для клиента
	 * @param string|null $message — опциональное сообщение
	 * @return JsonResponse
	 */
	public static function success($data = null, ?string $message = null): JsonResponse
	{
		return new JsonResponse([
			'success' => true,
			'data'    => $data,
			'message' => $message,
			'meta'    => [
				'timestamp'  => date('c'),
				'version'    => self::getVersion(),
				'request_id' => $_SERVER['REQUEST_ID'] ?? uniqid(),
			],
			'error'   => null,
		]);
	}

	/**
	 * Ответ с ошибкой.
	 *
	 * [Лорелея]: success = false. data — null. message — что случилось.
	 * meta — всегда. error — с кодом и сообщением. 
	 *
	 * [Мириам]: И — это правильно. Потому что ошибка — это не «стыд».
	 * Это — информация.
	 *
	 * @param string $message — сообщение об ошибке
	 * @param int $code — HTTP-код (400, 404, 500...)
	 * @return JsonResponse
	 */
	public static function error(string $message, int $code = 400): JsonResponse
	{
		return new JsonResponse([
			'success' => false,
			'data'    => null,
			'message' => $message,
			'meta'    => [
				'timestamp'  => date('c'),
				'version'    => self::getVersion(),
				'request_id' => $_SERVER['REQUEST_ID'] ?? uniqid(),
			],
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		], $code);
	}

	/**
	 * Ответ с пагинацией.
	 *
	 * [Лорелея]: success = true. data — массив. meta — с пагинацией.
	 * page, per_page, total, total_pages.
	 *
	 * [Мириам]: И — это правильно. Потому что пагинация — это
	 * не «ограничение». Это — забота.
	 *
	 * @param array $data — массив данных
	 * @param int $total — всего записей
	 * @param int $page — текущая страница
	 * @param int $perPage — записей на странице
	 * @return JsonResponse
	 */
	public static function paginated(array $data, int $total, int $page = 1, int $perPage = 50): JsonResponse
	{
		return new JsonResponse([
			'success' => true,
			'data'    => $data,
			'message' => null,
			'meta'    => [
				'timestamp'   => date('c'),
				'version'     => self::getVersion(),
				'request_id'  => $_SERVER['REQUEST_ID'] ?? uniqid(),
				'pagination'  => [
					'page'        => $page,
					'per_page'    => $perPage,
					'total'       => $total,
					'total_pages' => (int) ceil($total / $perPage),
				],
			],
			'error'   => null,
		]);
	}
}