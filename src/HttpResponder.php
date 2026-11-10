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
	private const CORS_ALLOW_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';
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