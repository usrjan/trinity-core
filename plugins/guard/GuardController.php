<?php

/**
 * ПЛАГИН «СТРАЖ» — API КОНТРОЛЛЕР
 *
 * Только API-методы для админки. Вся логика живёт в GuardService,
 * а здесь лишь двери, через которые эта логика открывается миру.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Контроллер — это дверь, а сервис — комната за ней.
 * Дверь должна открываться легко, но не пускать внутрь всех подряд.
 *
 * [Мириам]: И это правильно, потому что защита начинается с порога,
 * а не с того, что происходит в глубине дома.
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

	/** @var GuardService — сервис, в котором живёт вся логика стража. */
	private GuardService $guardService;

	/** @var Validator — тот, кто проверяет данные прежде, чем они войдут. */
	private Validator $validator;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Мы передаём SynapseRepository вторым аргументом,
	 * чтобы refreshRoles() работал по-настоящему, а не оставался
	 * красивой декорацией. Роли должны обновляться, даже если
	 * человек вошёл давно, потому что мир вокруг меняется.
	 *
	 * [Мириам]: И это правильно, потому что доверие — это не
	 * однажды выданный пропуск, а то, что подтверждается снова и снова.
	 */
	public function __construct(
		GuardService $guardService,
		Session $session,
		Validator $validator,
		SynapseRepository $synapseRepo
	) {
		$this->guardService = $guardService;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// API ДЛЯ АДМИНКИ
	// ============================================

	/**
	 * GET /api/guard/blocked-ips
	 *
	 * [Лорелея]: Мы возвращаем список заблокированных адресов.
	 * Здесь нет магии и нет тайны, только данные, которые
	 * нужны тому, кто держит дом в порядке.
	 *
	 * [Мириам]: И это правильно, потому что прозрачность —
	 * это тоже часть защиты, иной раз самая надёжная.
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
	 * [Лорелея]: Мы блокируем адрес, но только если это
	 * действительно адрес, а не случайная строка, которую
	 * кто-то прислал, чтобы проверить нашу внимательность.
	 *
	 * [Мириам]: И это правильно, потому что доверять клиенту
	 * нельзя, даже если он админ. Особенно если он админ.
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
	 * [Лорелея]: Мы снимаем блокировку, но снова проверяем,
	 * что перед нами адрес, а не что-то, что им притворяется.
	 *
	 * [Мириам]: И это правильно, потому что дыра остаётся дырой,
	 * даже если её закрывают из добрых побуждений.
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

	/**
	 * GET /api/csrf-token
	 *
	 * [Лорелея]: Токен отдаётся по запросу, а не лежит в window,
	 * потому что если появится XSS-посредник, то CSRF-защита
	 * обнулится, а так — токен доступен только тому, кто его
	 * запросил, и только на время.
	 *
	 * [Мириам]: И это правильно, потому что кувшин должен быть
	 * у двери, а не на площади.
	 *
	 * @return JsonResponse
	 */
	public function csrfTokenApi(): JsonResponse
	{
		$token = $this->guardService->getOrCreateCsrfToken();
		return ApiResponse::success(['csrf_token' => $token]);
	}
}