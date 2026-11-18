<?php

declare(strict_types=1);

/**
 * ЗАГРУЗЧИК ПЛАГИНОВ
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Psr\Log\LoggerInterface;

class PluginLoader
{
	private string $basePath;
	private ?LoggerInterface $logger;

	public function __construct(string $basePath, ?LoggerInterface $logger = null)
	{
		$this->basePath = rtrim($basePath, '/');
		$this->logger = $logger;
	}

	/**
	 * Загружает все плагины.
	 *
	 * @return array Массив замыканий.
	 */
	public function load(): array
	{
		$pluginDir = $this->basePath . '/plugins';
		if (!is_dir($pluginDir)) return [];

		$cacheFile = $this->basePath . '/var/cache/plugins.php';
		$plugins = null;

		if (file_exists($cacheFile)) {
			$cached = require $cacheFile;
			if (is_array($cached) && isset($cached['mtime']) && isset($cached['files'])) {
				$dirMtime = filemtime($pluginDir);
				if ($dirMtime !== false && $cached['mtime'] === $dirMtime) {
					$plugins = $cached['files'];
				}
			}
		}

		if ($plugins === null) {
			$plugins = glob($pluginDir . '/*/plugin.php') ?: [];
			$dirMtime = filemtime($pluginDir);

			$code = '<?php' . "\n\n" . 'return ' . var_export([
				'mtime' => $dirMtime,
				'files' => $plugins,
			], true) . ';' . "\n";
			file_put_contents($cacheFile, $code, LOCK_EX);
		}

		$loaded = [];
		foreach ($plugins as $file) {
			try {
				$plugin = require $file;
				if (is_callable($plugin)) {
					$loaded[] = $plugin;
				}
			} catch (\Throwable $e) {
				if ($this->logger !== null) {
					$this->logger->error('plugin load failed', [
						'file'    => $file,
						'message' => $e->getMessage(),
						'file_path' => $e->getFile() . ':' . $e->getLine(),
					]);
				}
			}
		}

		return $loaded;
	}
}