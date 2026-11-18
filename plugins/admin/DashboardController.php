<?php

declare(strict_types=1);

/**
 * КОНТРОЛЛЕР ДАШБОРДА
 *
 * Отдаёт HTML для дашборда. Только для админа.
 *
 * @package Jan\Trinity\Plugin\Admin
 */

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class DashboardController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var DashboardService — сервис дашборда. */
	private DashboardService $dashboardService;

	public function __construct(
		Environment $twig,
		Session $session,
		DashboardService $dashboardService
	) {
		$this->twig = $twig;
		$this->dashboardService = $dashboardService;

		$this->initAuth($session);
	}

	/**
	 * GET /api/admin/dashboard
	 */
	public function dashboard(): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$data = $this->dashboardService->collect();

		$html = $this->twig->render('dashboard.html.twig', [
			'counts'        => $data['counts'],
			'by_type'       => $data['by_type'],
			'latest'        => $data['latest'],
			'admin_log'     => $data['admin_log'],
			'pending_users' => $data['pending_users'],
		]);

		return ApiResponse::success(['html' => $html]);
	}
}