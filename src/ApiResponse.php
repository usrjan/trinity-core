<?php

declare(strict_types=1);

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
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponse
{
	/**
	 * @var ConfigService|null — сервис конфигурации.
	 * Устанавливается один раз в Bootstrap::boot().
	 * Если не установлен — getVersion() использует $_ENV.
	 */
	private static ?ConfigService $configService = null;

	/**
	 * Устанавливает ConfigService.
	 *
	 * @param ConfigService $configService
	 */
	public static function setConfigService(ConfigService $configService): void
	{
		self::$configService = $configService;
	}

	/**
	 * Версия системы.
	 *
	 * @return string
	 */
	public static function getVersion(): string
	{
		if (self::$configService !== null) {
			$version = self::$configService->get('app.version');
			if ($version !== null && $version !== '') {
				return (string) $version;
			}
		}

		return $_ENV['APP_VERSION'] ?? 'unknown';
	}

	/**
	 * Успешный ответ.
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