<?php

/**
 * MIDDLEWARE АВТОРИЗАЦИИ
 *
 * Предоставляет методы для проверки прав доступа.
 * Подключается через trait в любом контроллере.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «трейт». Это — рука. Та, что проверяет.
 * Та, что пускает. Та, что не пускает. Без «магии».
 * Без «сюрпризов».
 *
 * [Мириам]: И — это правильно. Потому что рука должна быть
 * твёрдой, но не грубой.
 *
 * === КНИГА ===
 * Глава 16. Страж ворот.
 *
 * Прежде чем войти в Амбер, нужно пройти через Образ.
 * Прежде чем попасть в админку, нужно пройти через AuthMiddleware.
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

	/** @var SynapseRepository|null — репозиторий для обновления ролей. */
	protected ?SynapseRepository $authSynapseRepo = null;

	/**
	 * @var bool — флаг: обновляли ли роли в этом запросе.
	 *
	 * [Лорелея]: ГЛАВНОЕ. Раньше refreshRoles() вызывался на каждый
	 * hasRole() и isAdmin(). И если бы он работал — это был бы N+1.
	 * Теперь — один раз за запрос.
	 *
	 * [Мириам]: И — это правильно. Потому что роли не меняются
	 * в рамках одного запроса. Если меняются — это другой запрос.
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
	 *
	 * [Мириам]: И — это правильно. Потому что роли должны обновляться.
	 * Всегда. Даже если пользователь давно вошёл.
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
	 *
	 * [Лорелея]: Если не вошёл — значит, не вошёл.
	 * И — не пускаем. Без «потом».
	 *
	 * [Мириам]: И — это правильно. Потому что гость должен
	 * представиться.
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
	 *
	 * [Лорелея]: Если не админ — значит, не админ.
	 * И — не пускаем. Без «потом».
	 *
	 * [Мириам]: И — это правильно. Потому что админка — это
	 * не «место для всех». Это — место для тех, кому можно.
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
	 * [Лорелея]: Если нет роли — выбрасываем.
	 *
	 * [Мириам]: И — это правильно. Потому что роль — это
	 * не «пожелание». Это — требование.
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
	 * [Лорелея]: Возвращаем JsonResponse с ошибкой. Или null,
	 * если всё хорошо.
	 *
	 * [Мириам]: И — это правильно. Потому что API не должно
	 * падать. Оно должно отвечать.
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
	 * [Лорелея]: Возвращаем JsonResponse с ошибкой. Или null.
	 *
	 * [Мириам]: И — это правильно. Потому что админка — это
	 * не «место для всех».
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
	 * [Лорелея]: Возвращаем JsonResponse с ошибкой. Или null.
	 *
	 * [Мириам]: И — это правильно. Потому что роль — это
	 * не «пожелание». Это — требование.
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
	 * [Лорелея]: Просто. Честно.
	 *
	 * [Мириам]: И — это правильно. Потому что иногда нужно
	 * просто узнать. Не пустить. А — узнать.
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
	 * [Лорелея]: Вызывает refreshRoles() перед проверкой.
	 * Чтобы роли были актуальны.
	 *
	 * [Мириам]: И — это правильно. Потому что роль может
	 * измениться.
	 *
	 * @return bool
	 */
	protected function isAdmin(): bool
	{
		$this->refreshRoles();
		return $this->hasRole('role_admin');
	}

	/**
	 * Проверяет наличие конкретной роли у пользователя.
	 *
	 * [Лорелея]: Тоже вызывает refreshRoles(). Чтобы все проверки
	 * были актуальны.
	 *
	 * [Мириам]: И — это правильно. Потому что роль — это
	 * не «вчерашняя». Это — сегодняшняя.
	 *
	 * @param string $role — slug роли
	 * @return bool
	 */
	protected function hasRole(string $role): bool
	{
		$this->refreshRoles();
		return in_array($role, $this->getUserRoles());
	}

	/**
	 * Возвращает данные текущего пользователя.
	 *
	 * [Лорелея]: id, login, roles. Всё, что нужно.
	 *
	 * [Мириам]: И — это правильно. Потому что иногда нужно
	 * знать, кто пришёл.
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
	 * [Лорелея]: Из сессии.
	 *
	 * [Мириам]: И — это правильно. Потому что роли — это
	 * не «догадка». Это — знание.
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
	 * Обновляет роли пользователя из базы.
	 *
	 * [Лорелея]: ГЛАВНОЕ. Раньше использовался $this->synapseRepo,
	 * которого в трейте не было. И свойство было null. И refreshRoles()
	 * выходил сразу. Теперь — $this->authSynapseRepo. И — работает.
	 *
	 * [Мириам]: И — статичный флаг. Один раз за запрос. Без N+1.
	 */
	protected function refreshRoles(): void
	{
		// [Лорелея]: Если уже обновляли в этом запросе — не трогаем.
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
		if (time() - $lastRefresh < 300) {
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
		// снова в этом запросе. Без N+1.
		$this->rolesRefreshed = true;
	}
}