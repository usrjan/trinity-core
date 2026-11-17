<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Monitor;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Service\RateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class CspReportController
{
	/** @var RateLimiter — rate limiting. */
	private RateLimiter $rateLimiter;

	private LoggerInterface $securityLogger;
	private ConfigService $configService;
	private ?bool $enabled = null;

	public function __construct(
		RateLimiter $rateLimiter,
		LoggerInterface $securityLogger,
		ConfigService $configService
	) {
		$this->rateLimiter = $rateLimiter;
		$this->securityLogger = $securityLogger;
		$this->configService = $configService;
	}

	/**
	 * POST /api/monitor/csp
	 */
	public function collect(Request $request): JsonResponse
	{
		if (!$this->isEnabled()) {
			return ApiResponse::success(null, 'Plugin disabled');
		}

		$ip = $request->getClientIp() ?? 'unknown';
		$rateLimitKey = 'csp_report_' . $ip;

		if ($this->rateLimiter->tooManyAttempts($rateLimitKey, 20, 60)) {
			return ApiResponse::error('Слишком много отчётов. Попробуйте позже.', 429);
		}
		$this->rateLimiter->hit($rateLimitKey);

		$raw = $request->getContent();
		if (strlen($raw) > 8192) {
			$raw = substr($raw, 0, 8192);
		}

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return ApiResponse::error('Невалидный отчёт', 400);
		}

		$report = $data['csp-report'] ?? $data;

		$this->securityLogger->warning('csp_violation', [
			'blocked_uri'        => mb_substr((string)($report['blocked-uri'] ?? ''), 0, 2048),
			'violated_directive' => mb_substr((string)($report['violated-directive'] ?? ''), 0, 255),
			'document_uri'       => mb_substr((string)($report['document-uri'] ?? ''), 0, 2048),
			'source_file'        => mb_substr((string)($report['source-file'] ?? ''), 0, 2048),
			'line_number'        => (int)($report['line-number'] ?? 0),
			'ip'                 => $ip,
		]);

		return ApiResponse::success(null, 'Report logged');
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