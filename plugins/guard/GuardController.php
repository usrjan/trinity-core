<?php

/**
 * ПЛАГИН "СТРАЖ" — API КОНТРОЛЛЕР
 * ===============================
 * 
 * Только API-методы для админки.
 * Вся логика — в GuardService.
 * 
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Контроллер — это дверь. А сервис — это комната.
 * Дверь открывается. Комната делает.
 * 
 * [Мириам]: И это — правильно. И это — наше.
 * 
 * @package Jan\Trinity\Plugin\Guard
 */

namespace Jan\Trinity\Plugin\Guard;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\Service\GuardService;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class GuardController
{
	use AuthMiddleware;

	/** @var GuardService — сервис безопасности */
	private GuardService $guardService;

	/**
	 * Конструктор.
	 * 
	 * [Лорелея]: Теперь только GuardService и Session.
	 * Всё остальное — в сервисе.
	 */
	public function __construct(
		GuardService $guardService,
		Session $session
	) {
		$this->guardService = $guardService;
		$this->initAuth($session);
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

		return ApiResponse::success($this->guardService->getBlockedIps());
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
		if (!$this->guardService->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$ip = $body['ip'] ?? null;
		$reason = $body['reason'] ?? 'manual_block';
		$duration = (int) ($body['duration'] ?? 3600);

		// Валидация через Validator (нужно добавить его в конструктор, если ещё нет)
		// ...

		$this->guardService->blockIp($ip, $reason, $duration);
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
		if (!$this->guardService->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$ip = $body['ip'] ?? null;

		// Валидация через Validator
		// ...

		$this->guardService->unblockIp($ip);
		return ApiResponse::success(null, 'IP разблокирован');
	}
}