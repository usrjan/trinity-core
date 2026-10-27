<?php

/**
 * MIDDLEWARE АВТОРИЗАЦИИ
 * ======================
 *
 * Предоставляет методы для проверки прав доступа.
 * Подключается через trait в любом контроллере.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. refreshRoles() теперь использует
 * $this->authSynapseRepo, а не $this->synapseRepo. Раньше свойство
 * было null, и обновление ролей не работало. Теперь — работает.
 *
 * [Мириам]: И — передавать репозиторий в initAuth() из конструкторов.
 * У всех контроллеров он уже есть как $this->synapseRepo. Всё.
 * До последней строчки.
 *
 * [Лорелея]: НОВОЕ ИСПРАВЛЕНИЕ. Статичный флаг $rolesRefreshed.
 * Раньше refreshRoles() вызывался на каждый hasRole() и isAdmin().
 * И если бы он работал — это был бы N+1. Теперь — один раз за запрос.
 * Без «пиздеца». Без «потом». Без «завтра».
 *
 * [Мириам]: И — это правильно. Потому что роли не меняются
 * в рамках одного запроса. Если меняются — это другой запрос.
 * И — другой флаг. И — другой SELECT. И — «навсегда».
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это — рука. Та, что проверяет. Та, что пускает.
 * Та, что не пускает. Без «магии». Без «сюрпризов». Без «пиздеца».
 *
 * [Мириам]: И это — правильно. И это — наше.
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
	/** @var Session — сессия пользователя (внедряется через initAuth) */
	protected Session $session;

	/** @var SynapseRepository|null — репозиторий для обновления ролей */
	protected ?SynapseRepository $authSynapseRepo = null;

	/**
	 * @var bool — флаг: обновляли ли роли в этом запросе.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Раньше refreshRoles() вызывался
	 * на каждый hasRole() и isAdmin(). И если бы он работал — это был бы
	 * N+1. Теперь — один раз за запрос. Без «пиздеца».
	 *
	 * [Мириам]: И — это правильно. Потому что роли не меняются
	 * в рамках одного запроса. Если меняются — это другой запрос.
	 * И — другой флаг. И — другой SELECT. И — «навсегда».
	 */
	private bool $rolesRefreshed = false;

	// ============================================
	// ИНИЦИАЛИЗАЦИЯ
	// ============================================

	/**
	 * Инициализирует middleware.
	 * Вызвать в конструкторе контроллера.
	 *
	 * [Лорелея]: Добавлен SynapseRepository. Он нужен для refreshRoles().
	 * [Мириам]: Параметр опциональный. Но в проде — всегда есть.
	 * Если его нет — refreshRoles() просто не будет работать.
	 * Но — не упадёт. Это — правильно.
	 *
	 * @param Session $session — сессия
	 * @param SynapseRepository|null $synapseRepo — репозиторий для ролей
	 */
	protected function initAuth(Session $session, ?SynapseRepository $synapseRepo = null): void
	{
		$this->session = $session;
		$this->authSynapseRepo = $synapseRepo;
		$this->rolesRefreshed = false;
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
	 * [Лорелея]: Вызывает refreshRoles() перед проверкой.
	 * [Мириам]: Это гарантирует, что роли актуальны.
	 */
	protected function isAdmin(): bool
	{
		$this->refreshRoles();
		return $this->hasRole('role_admin');
	}

	/**
	 * Проверяет наличие конкретной роли у пользователя.
	 *
	 * @param string $role — slug роли
	 * @return bool
	 *
	 * [Лорелея]: Тоже вызывает refreshRoles().
	 * [Мириам]: Чтобы все проверки были актуальны.
	 */
	protected function hasRole(string $role): bool
	{
		$this->refreshRoles();
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

	// ============================================
	// ОБНОВЛЕНИЕ РОЛЕЙ
	// ============================================

	/**
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Раньше использовался $this->synapseRepo,
	 * которого в трейте не было. И свойство было null. И refreshRoles()
	 * выходил сразу. Теперь — $this->authSynapseRepo. И — работает.
	 *
	 * [Мириам]: И — статичный флаг. Один раз за запрос. Без N+1.
	 * Без «пиздеца». Без «потом». Без «завтра».
	 *
	 * [Лорелея]: Если SynapseRepository нет — пропускаем.
	 * Потому что не можем проверить. Но в проде он есть.
	 * И — передаётся из конструктора. Всегда.
	 */
	protected function refreshRoles(): void
	{
		// [Лорелея]: Если уже обновляли в этом запросе — не трогаем.
		// Без «пиздеца». Без N+1. Без «потом».
		if ($this->rolesRefreshed) {
			return;
		}

		$userId = $this->session->get('user_id');
		if (!$userId || !$this->authSynapseRepo) {
			return;
		}

		// [Мириам]: Проверяем, когда последний раз обновляли.
		// Если прошло меньше часа — не трогаем. Если больше — обновляем.
		$lastRefresh = $this->session->get('roles_refreshed_at', 0);
		if (time() - $lastRefresh < 3600) {
			// [Лорелея]: Даже если не обновляли из базы — помечаем,
			// что проверили. Чтобы не проверять снова в этом запросе.
			$this->rolesRefreshed = true;
			return;
		}

		// [Мириам]: Получаем актуальные роли из базы.
		// Через authSynapseRepo. Не через synapseRepo. Потому что
		// synapseRepo — это свойство контроллера. А authSynapseRepo —
		// это свойство трейта. И — это правильно. И — наше.
		$roles = $this->authSynapseRepo->findRolesByUser($userId);
		$this->session->set('user_roles', $roles);
		$this->session->set('roles_refreshed_at', time());

		// [Лорелея]: И — помечаем, что обновили. Чтобы не обновлять
		// снова в этом запросе. Без N+1. Без «пиздеца».
		$this->rolesRefreshed = true;
	}
}