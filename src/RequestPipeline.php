<?php

declare(strict_types=1);

/**
 * КОНВЕЙЕР ЗАПРОСА
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Jan\Trinity\Core\Service\CsrfService;
use Jan\Trinity\Core\Service\IpBlocker;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

class RequestPipeline
{
	private SynapseRepository $synapseRepo;
	private Session $session;
	private CsrfService $csrfService;
	private ?LoggerInterface $logger;

	/** @var IpBlocker — блокировка IP. */
	private IpBlocker $ipBlocker;

	public function __construct(
		SynapseRepository $synapseRepo,
		Session $session,
		CsrfService $csrfService,
		IpBlocker $ipBlocker,
		?LoggerInterface $logger = null
	) {
		$this->synapseRepo = $synapseRepo;
		$this->session = $session;
		$this->csrfService = $csrfService;
		$this->ipBlocker = $ipBlocker;
		$this->logger = $logger;
	}

	/**
	 * HEAD → GET.
	 */
	public function normalizeMethod(Request $request): void
	{
		if ($request->getMethod() === 'HEAD') {
			$request->setMethod('GET');
		}
	}

	/**
	 * Guard early-check. Заблокированный IP — не гость.
	 */
	public function checkIpBlocked(Request $request): ?Response
	{
		$ip = $request->getClientIp();
		if ($ip && $this->ipBlocker->isBlocked($ip)) {
			return new Response('Доступ запрещён. Ваш IP заблокирован.', 403);
		}
		return null;
	}

	/**
	 * CSRF-проверка через метаданные маршрута.
	 */
	public function checkCsrf(Request $request, bool $requiresCsrf): ?Response
	{
		$httpMethod = $request->getMethod();
		if (!in_array($httpMethod, ['POST', 'PUT', 'DELETE'], true) || !$requiresCsrf) {
			return null;
		}

		$csrfToken = $request->headers->get('X-CSRF-Token', '')
			?: $request->request->get('_csrf_token', '');

		if (!$this->csrfService->validateToken($csrfToken)) {
			return new Response('Недействительный CSRF-токен', 419);
		}

		return null;
	}

	/**
	 * Авторизация через метаданные маршрута.
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
	 */
	private function refreshUserRoles(): void
	{
		$userId = $this->session->get('user_id');
		if (!$userId) {
			return;
		}

		$refreshedForUserId = $this->session->get('roles_refreshed_for_user_id');
		if ((int) $refreshedForUserId !== (int) $userId) {
			$this->reloadUserRoles($userId);
			return;
		}

		$lastRefresh = $this->session->get('roles_refreshed_at', 0);
		if (time() - $lastRefresh < 300) {
			return;
		}

		$this->reloadUserRoles($userId);
	}

	/**
	 * Перезагружает роли пользователя из базы.
	 *
	 * @param int $userId
	 */
	private function reloadUserRoles(int $userId): void
	{
		try {
			$roles = $this->synapseRepo->findRolesByUser($userId);

			$this->session->set('user_roles', $roles);
			$this->session->set('roles_refreshed_at', time());
			$this->session->set('roles_refreshed_for_user_id', $userId);
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