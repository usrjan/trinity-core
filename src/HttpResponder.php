<?php
declare(strict_types=1);

/**
 * HTTP-ОТВЕТЧИК
 *
 * Ставит CORS и security-заголовки, отправляет ответ.
 * Kernel больше не знает про заголовки — это работа ответчика.
 *
 * [Лорелея]: Ядро — скучное. Ответчик — знает, какие заголовки
 * и почему. Один источник правды.
 *
 * [Мириам]: И это правильно, потому что защита должна быть
 * в одном месте, а не в пяти.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HttpResponder
{
	private const CORS_ALLOW_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
	private const CORS_ALLOW_HEADERS = 'Content-Type, Authorization, X-CSRF-Token';

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	public function __construct(ConfigService $configService)
	{
		$this->configService = $configService;
	}

	/**
	 * Ставит заголовки и отправляет ответ.
	 */
	public function send(Request $request, Response $response): void
	{
		$this->applyCorsHeaders($request, $response);

		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('X-Frame-Options', 'SAMEORIGIN');
		$response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
		$response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

		// [Ревью]: CSP отсутствовала вовсе — при любой XSS-дыре атакующий
		// мог выполнить произвольный скрипт. По умолчанию режим Report-Only:
		// нарушения логируются в /api/monitor/csp (контроллер уже есть),
		// но ничего не ломается. Когда отладите репорты — включите enforced
		// через CSP_ENFORCED=true в .env.
		$this->applyCsp($response);

		if ($request->isSecure()) {
			$response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
		}

		$response->headers->set('Access-Control-Allow-Methods', self::CORS_ALLOW_METHODS);
		$response->headers->set('Access-Control-Allow-Headers', self::CORS_ALLOW_HEADERS);
		$response->send();
	}

	/**
	 * Ставит CORS-заголовки на основе whitelist из .env.
	 */
	private function applyCorsHeaders(Request $request, Response $response): void
	{
		$allowed = $this->configService->get('cors.allowed_origins', '');
		if (empty($allowed)) {
			return;
		}

		$origin = $request->headers->get('Origin');
		if (!$origin) {
			return;
		}

		$response->headers->set('Vary', 'Origin');

		$allowedOrigins = array_map('trim', explode(',', $allowed));

		if (in_array('*', $allowedOrigins, true)) {
			$response->headers->set('Access-Control-Allow-Origin', '*');
			return;
		}

		if (in_array($origin, $allowedOrigins, true)) {
			$response->headers->set('Access-Control-Allow-Origin', $origin);
			$response->headers->set('Access-Control-Allow-Credentials', 'true');
		}
	}

	/**
	 * Ставит CSP.
	 *
	 * [Лорелея]: inline-скрипты пока разрешены ('unsafe-inline') —
	 * они есть в base.html.twig, admin, monitor и др. Это не идеал,
	 * но уже запрет внешних скриптов с чужих доменов и frame-анкеров.
	 * Следующий шаг — nonce для inline и убор 'unsafe-inline'.
	 */
	private function applyCsp(Response $response): void
	{
		$reportUri = '/api/monitor/csp';

		$csp = implode('; ', [
			"default-src 'self'",
			"script-src 'self' 'unsafe-inline' https://api-maps.yandex.ru https://cdn.jsdelivr.net",
			"style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
			"img-src 'self' data: blob: https://api-maps.yandex.ru https://*.yandex.net https://*.yandex.ru",
			"font-src 'self' data: https://cdn.jsdelivr.net",
			"connect-src 'self' https://api-maps.yandex.ru",
			"frame-src 'self'",
			"frame-ancestors 'self'",
			"object-src 'none'",
			"base-uri 'self'",
			"form-action 'self'",
			"upgrade-insecure-requests",
		]);

		$enforced = ($_ENV['CSP_ENFORCED'] ?? 'false') === 'true';
		if ($enforced) {
			$response->headers->set('Content-Security-Policy', $csp . "; report-uri " . $reportUri);
		} else {
			$response->headers->set('Content-Security-Policy-Report-Only', $csp . "; report-uri " . $reportUri);
		}
	}

	/**
	 * Preflight-ответ для OPTIONS.
	 *
	 * [Лорелея]: OPTIONS — это когда гость спрашивает, можно ли войти.
	 * Отвечаем 204 со всеми заголовками. Kernel больше не знает
	 * про CORS-заголовки — это работа ответчика.
	 *
	 * [Мириам]: И это правильно, потому что preflight — это часть
	 * ответа, а не часть ядра.
	 *
	 * @param Request $request
	 * @param Response $response
	 */
	public function preflight(Request $request, Response $response): void
	{
		$this->applyCorsHeaders($request, $response);
		$response->headers->set('Access-Control-Allow-Methods', self::CORS_ALLOW_METHODS);
		$response->headers->set('Access-Control-Allow-Headers', self::CORS_ALLOW_HEADERS);
		$response->headers->set('Access-Control-Max-Age', '86400');
		$response->headers->set('Vary', 'Origin');
	}
}