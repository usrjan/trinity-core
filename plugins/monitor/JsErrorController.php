<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Monitor;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Service\RateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class JsErrorController
{
	/** @var RateLimiter — rate limiting. */
	private RateLimiter $rateLimiter;

	private Validator $validator;
	private LoggerInterface $logger;
	private ConfigService $configService;
	private ?bool $enabled = null;

	public function __construct(
		RateLimiter $rateLimiter,
		Validator $validator,
		LoggerInterface $logger,
		ConfigService $configService
	) {
		$this->rateLimiter = $rateLimiter;
		$this->validator = $validator;
		$this->logger = $logger;
		$this->configService = $configService;
	}

	/**
	 * POST /api/monitor/js-error
	 */
	public function collect(Request $request): JsonResponse
	{
		if (!$this->isEnabled()) {
			return ApiResponse::success(null, 'Plugin disabled');
		}

		$ip = $request->getClientIp() ?? 'unknown';
		$rateLimitKey = 'js_error_' . $ip;

		if ($this->rateLimiter->tooManyAttempts($rateLimitKey, 20, 60)) {
			return ApiResponse::error('Слишком много ошибок. Попробуйте позже.', 429);
		}

		$this->rateLimiter->hit($rateLimitKey);

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		if (!$this->validator->validate([
			'message' => $body['message'] ?? null,
			'type'    => $body['type'] ?? 'error',
			'line'    => $body['line'] ?? 0,
			'url'     => $body['url'] ?? null,
			'file'    => $body['file'] ?? null,
			'user'    => $body['user'] ?? null,
		], [
			'message' => ['required', 'string', 'min:1', 'max:2048'],
			'type'    => ['nullable', 'string', 'max:50'],
			'line'    => ['nullable', 'int', 'min:0'],
			'url'     => ['nullable', 'string', 'max:2048', 'url'],
			'file'    => ['nullable', 'string', 'max:1024'],
			'user'    => ['nullable', 'string', 'max:255'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$sanitize = static function (?string $value, int $max): string {
			if ($value === null) return '';
			$value = str_replace(["\r", "\n", "\t"], ' ', $value);
			return mb_substr($value, 0, $max);
		};

		$this->logger->warning($sanitize($body['message'], 2048), [
			'type' => $sanitize($body['type'] ?? 'error', 50),
			'file' => $sanitize($body['file'] ?? 'unknown', 1024),
			'line' => (int) ($body['line'] ?? 0),
			'url'  => $sanitize($body['url'] ?? 'unknown', 2048),
			'user' => $sanitize($body['user'] ?? 'guest', 255),
			'ip'   => $ip,
		]);

		return ApiResponse::success(null, 'Error logged');
	}

	private function isEnabled(): bool
	{
		if ($this->enabled === null) {
			$value = $this->configService->get('monitor.enabled', true);
			$this->enabled = (bool) $value;
		}

		return $this->enabled;
	}
}