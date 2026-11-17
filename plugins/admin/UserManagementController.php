<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\CsrfService;
use Jan\Trinity\Plugin\Spa\Mime\MimeController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class UserManagementController
{
	use AuthMiddleware;

	private NeuronRepository $neuronRepo;
	private SynapseRepository $synapseRepo;

	/** @var CsrfService — CSRF-защита. */
	private CsrfService $csrfService;

	private MimeController $mimeController;

	public function __construct(
		Session $session,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		CsrfService $csrfService,
		MimeController $mimeController
	) {
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->csrfService = $csrfService;
		$this->mimeController = $mimeController;

		$this->initAuth($session);
	}

	/**
	 * POST /api/admin/user/{id}/approve
	 */
	public function approveUser(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$user = $this->neuronRepo->findById($id);
		if (!$user || $user['type'] !== 'user') {
			return ApiResponse::error('Пользователь не найден', 404);
		}

		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$data['status'] = 'active';
		$this->neuronRepo->update($id, ['data' => $data]);

		$this->mimeController->forgetCache($id);

		return ApiResponse::success(['id' => $id], 'Пользователь одобрен');
	}

	/**
	 * DELETE /api/admin/user/{id}
	 */
	public function deleteUser(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$user = $this->neuronRepo->findById($id);
		if (!$user || $user['type'] !== 'user') {
			return ApiResponse::error('Пользователь не найден', 404);
		}

		$this->neuronRepo->delete($id, true);
		$this->mimeController->forgetCache($id);

		return ApiResponse::success(['id' => $id], 'Пользователь удалён');
	}
}