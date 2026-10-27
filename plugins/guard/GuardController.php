<?php

/**
 * ПЛАГИН "СТРАЖ" — API КОНТРОЛЛЕР
 * ===============================
 *
 * Только API-методы для админки.
 * Вся логика — в GuardService.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: initAuth() теперь получает SynapseRepository вторым
 * аргументом. Чтобы refreshRoles() работал. А не был декорацией.
 * Без «потом». Без «завтра».
 *
 * [Мириам]: И — это правильно. Потому что роли должны обновляться.
 * Всегда. Даже если пользователь давно вошёл. Даже если роль
 * изменилась. Без «перезагрузи». Без «пиздеца».
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
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Service\GuardService;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class GuardController
{
	use AuthMiddleware;

	/** @var GuardService — сервис безопасности */
	private GuardService $guardService;

	/** @var Validator — страж данных. Без него — дыра. */
	private Validator $validator;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. initAuth() теперь получает
	 * SynapseRepository вторым аргументом. Чтобы refreshRoles()
	 * работал. А не был декорацией.
	 *
	 * [Мириам]: И — это правильно. Потому что роли должны обновляться.
	 * Всегда. Даже если пользователь давно вошёл. Даже если роль
	 * изменилась. Без «перезагрузи». Без «пиздеца».
	 */
	public function __construct(
		GuardService $guardService,
		Session $session,
		Validator $validator,
		SynapseRepository $synapseRepo
	) {
		$this->guardService = $guardService;
		$this->validator = $validator;

		// [Лорелея]: Передаём SynapseRepository. Чтобы refreshRoles()
		// работал. А не был декорацией.
		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// API ДЛЯ АДМИНКИ
	// ============================================

	/**
	 * GET /api/guard/blocked-ips
	 *
	 * [Лорелея]: Возвращает список заблокированных IP.
	 * Без «магии». Просто — данные. И — админ.
	 *
	 * [Мириам]: И это — правильно. И это — наше.
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
	 *
	 * [Лорелея]: Блокирует IP. Но — только если IP — настоящий.
	 * А не «anything». Не «null». Не «пиздец».
	 *
	 * [Мириам]: Валидация — через Validator. И — filter_var.
	 * Потому что доверять клиенту — нельзя. Даже админу.
	 * Особенно админу.
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

		$this->guardService->blockIp($ip, $reason, $duration);
		return ApiResponse::success(null, 'IP заблокирован');
	}

	/**
	 * POST /api/guard/unblock-ip
	 *
	 * [Лорелея]: Разблокирует IP. Но — только если IP — настоящий.
	 * А не «anything». Не «null». Не «пиздец».
	 *
	 * [Мириам]: И — та же валидация. Потому что дыра — она везде.
	 * Где нет filter_var. Где нет Validator. Где нет «навсегда».
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

		$this->guardService->unblockIp($ip);
		return ApiResponse::success(null, 'IP разблокирован');
	}
}