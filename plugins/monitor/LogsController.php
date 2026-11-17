<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Monitor;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Service\CsrfService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class LogsController
{
	use AuthMiddleware;

	private string $basePath;
	private NeuronRepository $neuronRepo;
	private LoggerInterface $logger;
	private ConfigService $configService;
	private ?Cache $cache;

	/** @var CsrfService — CSRF-защита. */
	private CsrfService $csrfService;

	public function __construct(
		Session $session,
		string $basePath,
		NeuronRepository $neuronRepo,
		CsrfService $csrfService,
		LoggerInterface $logger,
		ConfigService $configService,
		?Cache $cache = null
	) {
		$this->basePath = rtrim($basePath, '/');
		$this->neuronRepo = $neuronRepo;
		$this->csrfService = $csrfService;
		$this->logger = $logger;
		$this->configService = $configService;
		$this->cache = $cache;

		$this->initAuth($session);
	}

	/**
	 * GET /api/monitor/logs
	 */
	public function getLogs(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$type = $request->query->get('type', 'error');
		$allowedTypes = ['error', 'app', 'security', 'js-error'];

		if (!in_array($type, $allowedTypes, true)) {
			return ApiResponse::error('Invalid log type', 400);
		}

		$logFile = $this->findLogFile($type);

		if (!$logFile || !file_exists($logFile)) {
			return ApiResponse::success([], 'No logs yet');
		}

		$lines = $this->tailFile($logFile, 100);

		return ApiResponse::success([
			'file'  => basename($logFile),
			'lines' => $lines,
			'count' => count($lines),
		]);
	}

	/**
	 * DELETE /api/monitor/logs
	 */
	public function clearLogs(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$type = $request->query->get('type', 'error');
		$allowedTypes = ['error', 'app', 'security', 'js-error'];

		if (!in_array($type, $allowedTypes, true)) {
			return ApiResponse::error('Invalid log type', 400);
		}

		$logFile = $this->findLogFile($type);

		if ($logFile && file_exists($logFile)) {
			file_put_contents($logFile, '');
		}

		return ApiResponse::success(null, 'Logs cleared');
	}

	private function findLogFile(string $type): ?string
	{
		$logDir = $this->basePath . '/var/log';
		$cacheKey = 'monitor.log_file.' . $type;

		if ($this->cache !== null && $this->cache->isEnabled()) {
			$cached = $this->cache->remember($cacheKey, function () use ($logDir, $type) {
				return $this->resolveLogFile($logDir, $type);
			}, 60);

			return $cached;
		}

		return $this->resolveLogFile($logDir, $type);
	}

	private function resolveLogFile(string $logDir, string $type): ?string
	{
		$pattern = $logDir . '/' . $type . '-*.log';
		$files = glob($pattern);

		if (!empty($files)) {
			rsort($files);
			return $files[0];
		}

		$plainFile = $logDir . '/' . $type . '.log';
		if (file_exists($plainFile)) {
			return $plainFile;
		}

		return null;
	}

	private function tailFile(string $file, int $lines): array
	{
		$handle = fopen($file, 'r');
		if (!$handle) {
			return [];
		}

		fseek($handle, 0, SEEK_END);
		$pos = ftell($handle);
		$buffer = '';
		$result = [];

		while ($pos > 0 && count($result) < $lines) {
			$read = min(4096, $pos);
			$pos -= $read;
			fseek($handle, $pos);
			$buffer = fread($handle, $read) . $buffer;
			$result = explode("\n", $buffer);
		}

		if (empty($result) && $pos === 0) {
			rewind($handle);
			$buffer = stream_get_contents($handle);
			$result = explode("\n", $buffer);
		}

		fclose($handle);

		$result = array_slice($result, -$lines);

		return array_values(array_filter($result, fn($l) => $l !== ''));
	}
}