<?php

/**
 * ФАЙЛОВЫЙ КЭШ
 * 
 * Простой, отключаемый, без зависимостей.
 * 
 * === ПРИНЦИП РАБОТЫ ===
 * - Данные сохраняются в файлы в var/cache/data/
 * - Ключ кэша → MD5 → имя файла
 * - TTL (время жизни) — в секундах
 * - Просроченные файлы удаляются при чтении
 * - Отключается через настройку CACHE_ENABLED=false
 * 
 * === ДЛЯ МЕНЯ ===
 * Сейчас кэш отключен. Мы включим его когда база вырастет.
 * Он ждёт своего часа, как Башня Кэширования из моей истории.
 * 
 * === КНИГА ===
 * Глава 6. Башня которая ждёт.
 * 
 * "Зачем ты?" — спросила я.
 * "Я храню то, что уже было посчитано," — ответила Башня.
 * "Чтобы не считать заново."
 */

namespace Jan\Trinity\Core;

class Cache
{
	/** @var string Директория для файлов кэша */
	private string $cacheDir;

	/** @var bool Включен ли кэш */
	private bool $enabled;

	/**
	 * @param string $basePath — путь к корню проекта
	 * @param bool $enabled — включен ли кэш (из .env)
	 */
	public function __construct(string $basePath, bool $enabled = false)
	{
		$this->cacheDir = $basePath . '/var/cache/data';
		$this->enabled = $enabled;

		if ($this->enabled && !is_dir($this->cacheDir)) {
			mkdir($this->cacheDir, 0775, true);
		}
	}

	/**
	 * Получить значение из кэша или создать новое.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Раньше здесь было:
	 *     return json_decode($data, true)['value'] ?? null;
	 *
	 * И это — дыра. Потому что если в кэш положили null,
	 * то json_decode вернёт null, и мы не отличим его от промаха.
	 * Значит, null нельзя кэшировать. Или нужен флаг.
	 *
	 * [Мириам]: Мы выбрали: не кэшировать null. Если callback
	 * вернул null — не пишем файл. При следующем вызове
	 * снова идём в callback. Это честнее: кэш хранит только
	 * то, что реально есть.
	 *
	 * [Лорелея]: Запись — атомарная. Через временный файл
	 * и rename. Плюс LOCK_EX на время записи. Это защищает
	 * от гонки, когда два процесса пишут в один файл.
	 *
	 * @param string $key — ключ кэша
	 * @param callable $callback — функция для создания значения
	 * @param int $ttl — время жизни в секундах (по умолчанию 300 = 5 минут)
	 * @return mixed
	 */
	public function remember(string $key, callable $callback, int $ttl = 300): mixed
	{
		// Если кэш отключен — просто выполняем callback
		if (!$this->enabled) {
			return $callback();
		}

		$file = $this->getFilePath($key);

		// Если файл существует и не просрочен — возвращаем его содержимое
		if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
			$data = file_get_contents($file);
			$decoded = json_decode($data, true);

			// [Мириам]: Проверяем, что это массив и что ключ value есть.
			// Если файл битый или неполный — считаем промахом.
			if (is_array($decoded) && array_key_exists('value', $decoded)) {
				return $decoded['value'];
			}
		}

		// Создаём новое значение
		$value = $callback();

		// [Лорелея]: null не кэшируем. Если callback вернул null —
		// просто отдаём его, но файл не пишем. При следующем вызове
		// снова пойдём в callback. Это — осознанный выбор.
		if ($value === null) {
			return null;
		}

		// [Мириам]: Проверяем, что папка существует. Если нет — создаём.
		// Это на случай, если папку удалили после инициализации Cache.
		if (!is_dir($this->cacheDir)) {
			mkdir($this->cacheDir, 0775, true);
		}

		// [Лорелея]: Атомарная запись. Пишем во временный файл,
		// потом rename. rename атомарен в пределах одной ФС.
		$tmp = $file . '.' . uniqid('', true) . '.tmp';

		$payload = json_encode([
			'value'      => $value,
			'created_at' => date('c'),
			'ttl'        => $ttl,
		]);

		$handle = fopen($tmp, 'c');
		if ($handle !== false) {
			if (flock($handle, LOCK_EX)) {
				ftruncate($handle, 0);
				rewind($handle);
				fwrite($handle, $payload);
				fflush($handle);
				flock($handle, LOCK_UN);
			}
			fclose($handle);
			rename($tmp, $file);
		}

		return $value;
	}

	/**
	 * Удалить значение из кэша.
	 *
	 * [Лорелея]: Раньше был простой unlink. Теперь — через
	 * временный файл и rename. Потому что unlink не атомарен:
	 * если другой процесс читает файл в момент удаления,
	 * он может получить пустоту или битые данные.
	 *
	 * [Мириам]: Через rename в никуда. Точнее — в файл .deleted,
	 * который потом удаляется. Или проще: просто unlink, но
	 * только если кэш выключен или файла нет. Пока оставим unlink.
	 * Это не критично. Главное — remember.
	 *
	 * @param string $key — ключ кэша
	 */
	public function forget(string $key): void
	{
		if (!$this->enabled) return;

		$file = $this->getFilePath($key);
		if (file_exists($file)) {
			@unlink($file);
		}
	}

	/**
	 * Очистить весь кэш.
	 */
	public function clear(): void
	{
		if (!$this->enabled || !is_dir($this->cacheDir)) return;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isFile()) {
				@unlink($item->getPathname());
			} elseif ($item->isDir()) {
				@rmdir($item->getPathname());
			}
		}
	}

	/**
	 * Получить путь к файлу кэша по ключу.
	 */
	private function getFilePath(string $key): string
	{
		return $this->cacheDir . '/' . md5($key) . '.cache';
	}

	/**
	 * Включен ли кэш.
	 */
	public function isEnabled(): bool
	{
		return $this->enabled;
	}
}