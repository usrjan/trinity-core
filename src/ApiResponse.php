<?php

/**
 * ЕДИНЫЙ ФОРМАТ ОТВЕТОВ API
 * 
 * Этот класс обеспечивает стандартизацию всех HTTP-ответов в системе Trinity.
 * Все контроллеры обязаны возвращать ответы через методы этого класса.
 * 
 * === ПРЕИМУЩЕСТВА ===
 * - Единая структура ответов для всего API
 * - Автоматическое добавление метаданных (timestamp, version, request_id)
 * - Упрощение обработки ответов на стороне клиента
 * - Централизованное управление форматом ошибок
 * 
 * === ФОРМАТ ОТВЕТА ===
 * {
 *   "success": true/false,          // Статус выполнения запроса
 *   "data": ...,                    // Полезные данные (null при ошибке)
 *   "message": "...",               // Сообщение для пользователя (опционально)
 *   "meta": {                       // Метаданные ответа
 *     "timestamp": "2026-06-27T10:00:00Z",  // Время формирования ответа (ISO 8601)
 *     "version": "1.2.3",                   // Версия API
 *     "request_id": "abc123"                // Уникальный ID запроса для логирования
 *   },
 *   "error": null или {             // Информация об ошибке (null при успехе)
 *     "code": 400,                  // HTTP-код ошибки
 *     "message": "..."              // Текст ошибки
 *   }
 * }
 * 
 * === ДЛЯ РАЗРАБОТЧИКА ===
 * Это один из первых классов, созданных для нового ядра Trinity.
 * По требованию jan: "Хочу чтобы все ответы были одинаковые".
 * Теперь каждый плагин использует этот формат, что упрощает поддержку.
 * 
 * === АНАЛОГИЯ ИЗ КНИГИ ===
 * Глава 4. Единый язык.
 * 
 * В Амбере все говорят на одном языке — тари.
 * В Trinity все ответы говорят на одном языке — ApiResponse.
 * Это создаёт предсказуемость и упрощает коммуникацию между компонентами.
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponse
{
    /** 
     * @var string Версия системы API
     * Используется в метаданных каждого ответа для отслеживания совместимости
     */
    const VERSION = '1.2.3';

    /**
     * Формирует успешный JSON-ответ.
     * 
     * Этот метод следует использовать для всех успешных запросов.
     * Автоматически добавляет метаданные: timestamp, version, request_id.
     * 
     * @param mixed $data — Данные для передачи клиенту.
     *                      Может быть: массив, объект, скалярное значение или null.
     *                      Пример: ['user' => ['id' => 1, 'name' => 'John']]
     * 
     * @param string|null $message — Опциональное сообщение для пользователя.
     *                               Пример: "Данные успешно сохранены"
     *                               Если null, поле message не включается в ответ.
     * 
     * @return JsonResponse Объект ответа Symfony с HTTP-кодом 200.
     *                      Заголовки: Content-Type: application/json
     * 
     * @example
     * return ApiResponse::success(['users' => $users], "Найдено 5 пользователей");
     * 
     * @example
     * return ApiResponse::success(null, "Операция выполнена");
     */
    public static function success($data = null, ?string $message = null): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'meta'    => [
                'timestamp'  => date('c'),              // Дата в формате ISO 8601
                'version'    => self::VERSION,          // Версия API из константы
                'request_id' => $_SERVER['REQUEST_ID'] ?? uniqid(), // Уникальный ID запроса
            ],
            'error'   => null,                          // Ошибки нет
        ]);
    }

    /**
     * Формирует JSON-ответ с ошибкой.
     * 
     * Этот метод следует использовать для всех неудачных запросов.
     * Автоматически устанавливает соответствующий HTTP-код ответа.
     * 
     * @param string $message — Сообщение об ошибке для пользователя.
     *                          Должно быть понятным и информативным.
     *                          Пример: "Пользователь не найден"
     * 
     * @param int $code — HTTP-код ошибки.
     *                    Стандартные коды:
     *                    - 400: Bad Request (неверные данные)
     *                    - 401: Unauthorized (требуется авторизация)
     *                    - 403: Forbidden (нет прав доступа)
     *                    - 404: Not Found (ресурс не найден)
     *                    - 422: Unprocessable Entity (ошибка валидации)
     *                    - 500: Internal Server Error (ошибка сервера)
     * 
     * @return JsonResponse Объект ответа Symfony с указанным HTTP-кодом.
     *                      Заголовки: Content-Type: application/json
     * 
     * @example
     * return ApiResponse::error("Пользователь не найден", 404);
     * 
     * @example
     * return ApiResponse::error("Неверный формат email", 400);
     */
    public static function error(string $message, int $code = 400): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'data'    => null,                          // Данные отсутствуют
            'message' => $message,
            'meta'    => [
                'timestamp'  => date('c'),
                'version'    => self::VERSION,
                'request_id' => $_SERVER['REQUEST_ID'] ?? uniqid(),
            ],
            'error'   => [
                'code'    => $code,                     // HTTP-код ошибки
                'message' => $message,                  // Текст ошибки
            ],
        ], $code);                                      // Передаём код в конструктор JsonResponse
    }

    /**
     * Формирует JSON-ответ с пагинацией.
     * 
     * Специализированный метод для ответов, содержащих списки данных.
     * Добавляет информацию о страницах в мета-данные.
     * 
     * @param array $data — Массив данных для текущей страницы.
     *                      Пример: [['id' => 1, 'name' => 'John'], ...]
     * 
     * @param int $total — Общее количество записей в базе данных.
     *                     Используется для расчёта количества страниц.
     *                     Пример: 150 (всего пользователей в БД)
     * 
     * @param int $page — Номер текущей страницы (начиная с 1).
     *                    Пример: 3 (третья страница)
     * 
     * @param int $perPage — Количество записей на одной странице.
     *                       Значение по умолчанию: 50.
     *                       Пример: 20 (по 20 записей на странице)
     * 
     * @return JsonResponse Объект ответа Symfony с HTTP-кодом 200.
     *                      В meta.pagination содержится информация о пагинации.
     * 
     * @example
     * return ApiResponse::paginated($users, 150, 3, 20);
     * 
     * Ответ будет содержать:
     * {
     *   "success": true,
     *   "data": [...],
     *   "meta": {
     *     "pagination": {
     *       "page": 3,
     *       "per_page": 20,
     *       "total": 150,
     *       "total_pages": 8
     *     }
     *   }
     * }
     */
    public static function paginated(array $data, int $total, int $page = 1, int $perPage = 50): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data'    => $data,
            'message' => null,                          // Для пагинации сообщение обычно не нужно
            'meta'    => [
                'timestamp'   => date('c'),
                'version'     => self::VERSION,
                'request_id'  => $_SERVER['REQUEST_ID'] ?? uniqid(),
                'pagination'  => [
                    'page'        => $page,             // Текущая страница
                    'per_page'    => $perPage,          // Записей на странице
                    'total'       => $total,            // Всего записей
                    'total_pages' => (int) ceil($total / $perPage), // Всего страниц (округление вверх)
                ],
            ],
            'error'   => null,
        ]);
    }
}
