<?php

declare(strict_types=1);

/**
 * ЗАГРУЗЧИК ПЛАГИНОВ
 *
 * Находит, кэширует и загружает плагины из папки plugins/.
 * Возвращает массив готовых замыканий.
 *
 * [Лорелея]: Это не «класс для загрузки». Это — рука, которая
 * открывает двери. Каждую. По очереди. И не пускает тех, кто
 * сломан.
 *
 * [Мириам]: И это правильно, потому что один сломанный плагин
 * не должен ронять весь дом.
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
	 * [Лорелея]: Список плагинов кэшируется по mtime папки, потому что
	 * он меняется редко, а glob() на каждом запросе — это I/O без нужды.
	 *
	 * [Мириам]: И это правильно, потому что русло должно быть
	 * проложено один раз, а потом только проверяться.
	 *
	 * @return array Массив замыканий.
	 */
	public function load(): array
	{
		$pluginDir = $this->basePath . '/plugins';
		if (!is_dir($pluginDir)) return [];

		$cacheFile = $this->basePath . '/var/cache/plugins.php';
		$plugins = null;

		// [Лорелея]: Если кэш есть и не устарел — берём из него.
		if (file_exists($cacheFile)) {
			$cached = require $cacheFile;
			if (is_array($cached) && isset($cached['mtime']) && isset($cached['files'])) {
				$dirMtime = filemtime($pluginDir);
				if ($dirMtime !== false && $cached['mtime'] === $dirMtime) {
					$plugins = $cached['files'];
				}
			}
		}

		// [Мириам]: Если кэша нет или он устарел — собираем заново
		// и записываем, чтобы следующий запрос был быстрым.
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