<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Service;

use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\ConfigService;
use Psr\Log\LoggerInterface;

class RateLimiter
{
	private Cache $cache;
	private ConfigService $configService;
	private LoggerInterface $logger;
	private string $basePath;
	private bool $apcuAvailable;

	public function __construct(
		Cache $cache,
		ConfigService $configService,
		LoggerInterface $logger,
		string $basePath
	) {
		$this->cache = $cache;
		$this->configService = $configService;
		$this->logger = $logger;
		$this->basePath = rtrim($basePath, '/');
		$this->apcuAvailable = extension_loaded('apcu') && (bool) ini_get('apc.enabled');
	}

	public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 120): bool
	{
		$attempts = $this->getAttempts($key);
		return $attempts >= $maxAttempts;
	}

	public function hit(string $key, ?int $decaySeconds = null): int
	{
		if ($this->apcuAvailable) {
			$attemptsKey = 'guard.attempts.' . $key;
			$attempts = apcu_fetch($attemptsKey);
			$attempts = $attempts === false ? 1 : (int) $attempts + 1;
			apcu_store($attemptsKey, $attempts, $decaySeconds ?? 300);
			return $attempts;
		}

		$file = $this->getAttemptsFile($key);
		$handle = fopen($file, 'c+');
		if ($handle === false) {
			return PHP_INT_MAX;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			return PHP_INT_MAX;
		}

		$content = stream_get_contents($handle);
		$data = empty($content) ? [] : array_map('intval', explode("\n", $content));
		$data[] = time();

		if ($decaySeconds === null) {
			$decaySeconds = $this->getDecaySeconds();
		}

		$data = array_filter($data, fn($timestamp) => $timestamp > (time() - $decaySeconds));
		$count = count($data);

		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, implode("\n", $data));
		fflush($handle);
		flock($handle, LOCK_UN);
		fclose($handle);

		$this->cleanupAttempts();
		return $count;
	}

	public function clear(string $key): void
	{
		$file = $this->getAttemptsFile($key);
		if (!file_exists($file)) return;

		$handle = fopen($file, 'c+');
		if ($handle === false) return;

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			return;
		}

		ftruncate($handle, 0);
		fflush($handle);
		flock($handle, LOCK_UN);
		fclose($handle);

		@unlink($file);
	}

	private function getAttempts(string $key): int
	{
		if ($this->apcuAvailable) {
			$attempts = apcu_fetch('guard.attempts.' . $key);
			return $attempts === false ? 0 : (int) $attempts;
		}

		$file = $this->getAttemptsFile($key);
		if (!file_exists($file)) return 0;

		$handle = fopen($file, 'r');
		if ($handle === false) {
			$this->logger->warning('guard: failed to open attempts file', ['file' => $file]);
			return PHP_INT_MAX;
		}

		if (!flock($handle, LOCK_SH)) {
			fclose($handle);
			$this->logger->warning('guard: failed to lock attempts file', ['file' => $file]);
			return PHP_INT_MAX;
		}

		$content = stream_get_contents($handle);
		$data = empty($content) ? [] : array_map('intval', explode("\n", $content));
		flock($handle, LOCK_UN);
		fclose($handle);

		$decaySeconds = $this->getDecaySeconds();
		$data = array_filter($data, fn($timestamp) => $timestamp > (time() - $decaySeconds));

		return count($data);
	}

	private function getAttemptsFile(string $key): string
	{
		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		return $dir . '/' . md5($key) . '.attempts';
	}

	private function cleanupAttempts(): void
	{
		if ($this->apcuAvailable) return;

		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) return;

		$marker = $dir . '/.last_cleanup';
		$now = time();

		if (file_exists($marker)) {
			$last = (int) file_get_contents($marker);
			if ($last > 0 && ($now - $last) < 3600) return;
		}

		file_put_contents($marker, (string) $now, LOCK_EX);

		$decaySeconds = $this->getDecaySeconds();
		$files = glob($dir . '/*.attempts');
		if ($files === false) return;

		$removed = 0;
		foreach ($files as $file) {
			if (filemtime($file) < ($now - $decaySeconds)) {
				if (@unlink($file)) $removed++;
			}
		}

		if ($removed > 0) {
			$this->logger->info('guard: cleanup removed stale attempts files', ['removed' => $removed]);
		}
	}

	public function getMaxAttempts(): int
	{
		return (int) $this->configService->get('guard.max_attempts', 5);
	}

	public function getDecaySeconds(): int
	{
		return (int) $this->configService->get('guard.decay_seconds', 300);
	}

	public function getBlockDuration(): int
	{
		return (int) $this->configService->get('guard.block_duration', 3600);
	}
}