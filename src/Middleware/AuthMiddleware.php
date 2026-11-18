<?php

declare(strict_types=1);

/**
 * MIDDLEWARE АВТОРИЗАЦИИ
 *
 * Предоставляет методы для проверки прав доступа.
 * Подключается через trait в любом контроллере.
 *
 * @package Jan\Trinity\Core\Middleware
 */

namespace Jan\Trinity\Core\Middleware;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Exception\UnauthorizedException;
use Jan\Trinity\Core\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

trait AuthMiddleware
{
	/** @var Session — сессия пользователя (внедряется через initAuth). */
	protected Session $session;

	// ============================================
	// ИНИЦИАЛИЗАЦИЯ
	// ============================================

	/**
	 * Инициализирует middleware.
	 * Вызвать в конструкторе контроллера.
	 *
	 * @param Session $session — сессия
	 * @param SynapseRepository|null $synapseRepo — репозиторий для ролей
	 */
	protected function initAuth(Session $session, ?SynapseRepository $synapseRepo = null): void
	{
		$this->session = $session;
	}

	// ============================================
	// МЕТОДЫ ПРОВЕРКИ (ВЫБРАСЫВАЮТ ИСКЛЮЧЕНИЕ)
	// ============================================

	/**
	 * Требует авторизацию.
	 * Если пользователь не вошёл — выбрасывает исключение.
	 */
	protected function requireAuth(): void
	{
		if (!$this->isAuthenticated()) {
			throw new UnauthorizedException();
		}
	}

	/**
	 * Требует роль администратора.
	 * Если нет роли role_admin — выбрасывает исключение.
	 */
	protected function requireAdmin(): void
	{
		if (!$this->isAdmin()) {
			throw new ForbiddenException();
		}
	}

	/**
	 * Требует определённую роль.
	 *
	 * @param string $role — slug роли (например, 'role_admin')
	 */
	protected function requireRole(string $role): void
	{
		if (!$this->hasRole($role)) {
			throw new ForbiddenException('Доступ запрещён. Требуется роль: ' . $role);
		}
	}

	// ============================================
	// МЕТОДЫ ДЛЯ API (ВОЗВРАЩАЮТ JSON ИЛИ NULL)
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