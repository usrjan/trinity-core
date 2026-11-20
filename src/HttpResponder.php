<?php

declare(strict_types=1);

/**
 * HTTP-ОТВЕТЧИК
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

	/** @var array|null Разобранные allowed_origins. Ленивый кэш. */
	private ?array $allowedOriginsCache = null;

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

		$response->send();
	}

	/**
	 * Preflight-ответ для OPTIONS.
	 *
	 * @param Request $request
	 * @param Response $response
	 */
	public function preflight(Request $request, Response $response): void
	{
		$this->applyCorsHeaders($request, $response);
		$response->headers->set('Access-Control-Max-Age', '86400');
	}

	/**
	 * Ставит CORS-заголовки на основе whitelist из .env.
	 */
	private function applyCorsHeaders(Request $request, Response $response): void
	{
		$origin = $request->headers->get('Origin');
		if (!$origin) {
			return;
		}

		$response->headers->set('Access-Control-Allow-Methods', self::CORS_ALLOW_METHODS);
		$response->headers->set('Access-Control-Allow-Headers', self::CORS_ALLOW_HEADERS);

		$allowedOrigins = $this->getAllowedOrigins();
		if (empty($allowedOrigins)) {
			return;
		}

		$response->headers->set('Vary', 'Origin');

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
	 * Возвращает разобранный список allowed_origins.
	 *
	 * @return array
	 */
	private function getAllowedOrigins(): array
	{
		if ($this->allowedOriginsCache !== null) {
			return $this->allowedOriginsCache;
		}

		$allowed = $this->configService->get('cors.allowed_origins', '');
		if (empty($allowed)) {
			$this->allowedOriginsCache = [];
			return $this->allowedOriginsCache;
		}

		$this->allowedOriginsCache = array_map('trim', explode(',', $allowed));

		return $this->allowedOriginsCache;
	}

}