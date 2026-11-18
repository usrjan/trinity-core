<?php

declare(strict_types=1);

/**
 * ПЛАГИН «СТРАЖ» — API КОНТРОЛЛЕР
 *
 * Только API-методы для админки. Вся логика живёт в GuardService,
 * а здесь лишь двери, через которые эта логика открывается миру.
 *
 * @package Jan\Trinity\Plugin\Guard
 */

namespace Jan\Trinity\Plugin\Guard;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Service\CsrfService;
use Jan\Trinity\Core\Service\IpBlocker;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class GuardController
{
	use AuthMiddleware;

	/** @var IpBlocker — блокировка IP. */
	private IpBlocker $ipBlocker;

	/** @var CsrfService — CSRF-защита. */
	private CsrfService $csrfService;

	/** @var Validator — тот, кто проверяет данные прежде, чем они войдут. */
	private Validator $validator;

	/**
	 * Конструктор.
	 */
	public function __construct(
		IpBlocker $ipBlocker,
		CsrfService $csrfService,
		Session $session,
		Validator $validator,
		SynapseRepository $synapseRepo
	) {
		$this->ipBlocker = $ipBlocker;
		$this->csrfService = $csrfService;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// API ДЛЯ АДМИНКИ
	// ============================================

	/**
	 * GET /api/guard/blocked-ips
	 */
	public function getBlockedIpsApi(): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		return ApiResponse::success($this->ipBlocker->getBlockedIps());
	}

	/**
	 * POST /api/guard/block-ip
	 */
	public function blockIpApi(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$ip = $body['ip'] ?? null;
		$reason = $body['reason'] ?? 'manual_block';
		$duration = (int) ($body['duration'] ?? 3600);

		if (!$this->validator->validate([
			'ip'       => $ip,
			'duration' => $duration,
		], [
			'ip'       => ['required', 'string'],
			'duration' => ['int', 'min:0'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return ApiResponse::error('Некорректный IP-адрес', 400);
		}

		$this->ipBlocker->block($ip, $reason, $duration);
		return ApiResponse::success(null, 'IP заблокирован');
	}

	/**
	 * POST /api/guard/unblock-ip
	 */
	public function unblockIpApi(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$ip = $body['ip'] ?? null;

		if (!$this->validator->validate([
			'ip' => $ip,
		], [
			'ip' => ['required', 'string'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return ApiResponse::error('Некорректный IP-адрес', 400);
		}

		$this->ipBlocker->unblock($ip);
		return ApiResponse::success(null, 'IP разблокирован');
	}

	/**
	 * GET /api/csrf-token
	 *
	 * @return JsonResponse
	 */
	public function csrfTokenApi(): JsonResponse
	{
		$token = $this->csrfService->getOrCreateToken();
		return ApiResponse::success(['csrf_token' => $token]);
	}
}