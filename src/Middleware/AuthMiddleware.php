<?php

/**
 * MIDDLEWARE АВТОРИЗАЦИИ
 * ======================
 * 
 * Предоставляет методы для проверки прав доступа.
 * Подключается через trait в любом контроллере.
 * 
 * Методы проверки (выбрасывают исключение):
 * - requireAuth()          — требует авторизацию
 * - requireAdmin()         — требует роль администратора
 * - requireRole($role)     — требует определённую роль
 * 
 * Методы для API (возвращают JsonResponse или null):
 * - requireAuthForApi()    — проверка авторизации для API
 * - requireAdminForApi()   — проверка админа для API
 * - requireRoleForApi($r)  — проверка роли для API
 * 
 * Информационные методы:
 * - isAuthenticated()      — проверка авторизации
 * - isAdmin()              — проверка роли админа
 * - hasRole($role)         — проверка конкретной роли
 * - getUser()              — данные текущего пользователя
 * - getUserRoles()         — роли текущего пользователя
 * 
 * Инициализация в конструкторе контроллера:
 * $this->initAuth($session);
 * 
 * Использование в API-методах:
 * public function protectedMethod(): JsonResponse
 * {
 *     if ($error = $this->requireAdminForApi()) return $error;
 *     // ... код метода
 * }
 * 
 * Использование в страничных методах (HTML):
 * public function adminPage(): Response
 * {
 *     $this->requireAdmin();
 *     // ... исключение будет обработано Kernel
 * }
 */

namespace Jan\Trinity\Core\Middleware;

use Jan\Trinity\Core\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

trait AuthMiddleware
{
    /** @var Session — сессия пользователя (внедряется через initAuth) */
    protected Session $session;

    // ============================================
    // ИНИЦИАЛИЗАЦИЯ
    // ============================================

    /**
     * Инициализирует middleware.
     * Вызвать в конструкторе контроллера.
     * 
     * @param Session $session — сессия из DI-контейнера
     */
    protected function initAuth(Session $session): void
    {
        $this->session = $session;
    }

    // ============================================
    // МЕТОДЫ ПРОВЕРКИ (ВЫБРАСЫВАЮТ ИСКЛЮЧЕНИЕ)
    // Используются в страничных методах (HTML).
    // Исключение обрабатывается Kernel → страница ошибки.
    // ============================================

    /**
     * Требует авторизацию.
     * Если пользователь не вошёл — выбрасывает исключение.
     * 
     * @throws \RuntimeException с кодом 401
     */
    protected function requireAuth(): void
    {
        if (!$this->isAuthenticated()) {
            throw new \RuntimeException('Требуется авторизация', 401);
        }
    }

    /**
     * Требует роль администратора.
     * Если нет роли role_admin — выбрасывает исключение.
     * 
     * @throws \RuntimeException с кодом 403
     */
    protected function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new \RuntimeException('Доступ запрещён', 403);
        }
    }

    /**
     * Требует определённую роль.
     * 
     * @param string $role — slug роли (например, 'role_admin')
     * @throws \RuntimeException с кодом 403
     */
    protected function requireRole(string $role): void
    {
        if (!$this->hasRole($role)) {
            throw new \RuntimeException('Доступ запрещён. Требуется роль: ' . $role, 403);
        }
    }

    // ============================================
    // МЕТОДЫ ДЛЯ API (ВОЗВРАЩАЮТ JSON ИЛИ NULL)
    // Удобны в одну строку:
    // if ($error = $this->requireAdminForApi()) return $error;
    // ============================================

    /**
     * Проверяет авторизацию для API-метода.
     * 
     * @return JsonResponse|null — ответ с ошибкой, или null если доступ разрешён
     */
    protected function requireAuthForApi(): ?JsonResponse
    {
        if (!$this->isAuthenticated()) {
            return ApiResponse::error('Требуется авторизация', 401);
        }
        return null;
    }

    /**
     * Проверяет права администратора для API-метода.
     * 
     * @return JsonResponse|null — ответ с ошибкой, или null если доступ разрешён
     */
    protected function requireAdminForApi(): ?JsonResponse
    {
        if (!$this->isAdmin()) {
            return ApiResponse::error('Доступ запрещён', 403);
        }
        return null;
    }

    /**
     * Проверяет конкретную роль для API-метода.
     * 
     * @param string $role — slug роли
     * @return JsonResponse|null — ответ с ошибкой, или null если доступ разрешён
     */
    protected function requireRoleForApi(string $role): ?JsonResponse
    {
        if (!$this->hasRole($role)) {
            return ApiResponse::error('Доступ запрещён. Требуется роль: ' . $role, 403);
        }
        return null;
    }

    // ============================================
    // ИНФОРМАЦИОННЫЕ МЕТОДЫ
    // ============================================

    /**
     * Проверяет, авторизован ли пользователь.
     * 
     * @return bool
     */
    protected function isAuthenticated(): bool
    {
        return $this->session->get('user_id') !== null;
    }

    /**
     * Проверяет, является ли пользователь администратором.
     * 
     * @return bool
     */
    protected function isAdmin(): bool
    {
        return $this->hasRole('role_admin');
    }

    /**
     * Проверяет наличие конкретной роли у пользователя.
     * 
     * @param string $role — slug роли
     * @return bool
     */
    protected function hasRole(string $role): bool
    {
        return in_array($role, $this->getUserRoles());
    }

    /**
     * Возвращает данные текущего пользователя.
     * 
     * @return array{
     *     id: int|null,
     *     login: string|null,
     *     roles: array
     * }
     */
    protected function getUser(): array
    {
        return [
            'id'    => $this->session->get('user_id'),
            'login' => $this->session->get('user_login'),
            'roles' => $this->getUserRoles(),
        ];
    }

    /**
     * Возвращает роли текущего пользователя.
     * 
     * @return array — ['role_admin', 'role_user', ...]
     */
    protected function getUserRoles(): array
    {
        return $this->session->get('user_roles', []);
    }
}
