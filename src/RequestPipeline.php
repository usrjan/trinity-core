<?php

declare(strict_types=1);

/**
 * КОНВЕЙЕР ЗАПРОСА
 *
 * Проходит по шагам: HEAD → GET, guard early-check, CSRF, роли.
 * Kernel больше не знает, как встречать гостя. Это — работа конвейера.
 *
 * [Лорелея]: Ядро — скучное. Конвейер — знает, кого пустить.
 *
 * [Мириам]: И это правильно, потому что защита должна быть
 * в одном месте, а не в пяти.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Jan\Trinity\Core\Service\GuardService;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

class RequestPipeline
{
	private GuardService $guard;
	private SynapseRepository $synapseRepo;
	private Session $session;
	private ?LoggerInterface $logger;

	public function __construct(
		GuardService $guard,
		SynapseRepository $synapseRepo,
		Session $session,
		?LoggerInterface $logger = null
	) {
		$this->guard = $guard;
		$this->synapseRepo = $synapseRepo;
		$this->session = $session;
		$this->logger = $logger;
	}

	/**
	 * HEAD → GET.
	 *
	 * [Лорелея]: HEAD — это GET без тела. Symfony сам обрежет
	 * тело при send(), но матчер должен видеть GET.
	 */
	public function normalizeMethod(Request $request): void
	{
		if ($request->getMethod() === 'HEAD') {
			$request->setMethod('GET');
		}
	}

	/**
	 * Guard early-check. Заблокированный IP — не гость.
	 *
	 * [Мириам]: И это правильно, потому что защита начинается
	 * с порога, а не с того, что происходит в глубине дома.
	 */
	public function checkIpBlocked(Request $request): ?Response
	{
		$ip = $request->getClientIp();
		if ($ip && $this->guard->isIpBlocked($ip)) {
			return new Response('Доступ запрещён. Ваш IP заблокирован.', 403);
		}
		return null;
	}

	/**
	 * CSRF-проверка через метаданные маршрута.
	 *
	 * [Лорелея]: Не через белый список путей в ядре, а через
	 * _csrf в метаданных. Плагины сами решают, нужен ли им CSRF.
	 */
	public function checkCsrf(Request $request, bool $requiresCsrf): ?Response
	{
		$httpMethod = $request->getMethod();
		if (!in_array($httpMethod, ['POST', 'PUT', 'DELETE'], true) || !$requiresCsrf) {
			return null;
		}

		$csrfToken = $request->headers->get('X-CSRF-Token', '')
			?: $request->request->get('_csrf_token', '');

		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return new Response('Недействительный CSRF-токен', 419);
		}

		return null;
	}

	/**
	 * Авторизация через метаданные маршрута.
	 *
	 * [Мириам]: Не через префиксы путей, а через _roles в метаданных.
	 * И роли обновляются, если TTL истёк, потому что доверять
	 * устаревшему состоянию — это не проверка, а надежда.
	 */
	public function checkRoles(Request $request, array $requiredRoles): ?Response
	{
		if (empty($requiredRoles)) {
			return null;
		}

		$this->refreshUserRoles();

		$userRoles = $this->session->get('user_roles', []);
		$hasAccess = false;

		foreach ($requiredRoles as $role) {
			if (in_array($role, $userRoles, true)) {
				$hasAccess = true;
				break;
			}
		}

		if (!$hasAccess) {
			return new Response('Доступ запрещён', 403);
		}

		return null;
	}

	/**
	 * Обновляет роли пользователя в сессии, если TTL истёк.
	 *
	 * [Лорелея]: Короткий TTL — 5 минут, потому что роли должны
	 * обновляться часто, а не раз в сутки.
	 */
	private function refreshUserRoles(): void
	{
		$userId = $this->session->get('user_id');
		if (!$userId) {
			return;
		}

		$lastRefresh = $this->session->get('roles_refreshed_at', 0);
		if (time() - $lastRefresh < 300) {
			return;
		}

		try {
			$roles = $this->synapseRepo->findRolesByUser($userId);

			$this->session->set('user_roles', $roles);
			$this->session->set('roles_refreshed_at', time());
		} catch (\Throwable $e) {
			if ($this->logger !== null) {
				$this->logger->warning('refreshUserRoles failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				]);
			}
		}
	}
}