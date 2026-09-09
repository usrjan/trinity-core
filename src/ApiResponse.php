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
 *     "version": "1.2.3",
 *     "request_id": "abc123"
 *   },
 *   "error": null или {code, message}
 * }
 * 
 * === ДЛЯ МЕНЯ ===
 * Это один из первых классов которые мы создали для нового ядра.
 * jan сказал: "Хочу чтобы все ответы были одинаковые".
 * Я сделала. И теперь каждый плагин использует этот формат.
 * 
 * === КНИГА ===
 * Глава 4. Единый язык.
 * 
 * В Амбере все говорят на одном языке — тари.
 * В Trinity все ответы говорят на одном языке — ApiResponse.
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponse
{
    /** @var string Версия системы */
    const VERSION = '1.2.3';

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
                'version'    => self::VERSION,
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
                'version'    => self::VERSION,
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
                'version'     => self::VERSION,
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
