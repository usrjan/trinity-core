<?php

/**
 * ФАЙЛОВЫЙ КЭШ
 *
 * Простое хранилище «ключ → значение» на диске (var/cache/data).
 * Инмемори-слой закрывает повторные чтения внутри одного запроса,
 * диск — переживает запросы между собой.
 *
 * === ПРИНЦИП РАБОТЫ ===
 * Кэш отключаемый: если $enabled = false — все read() возвращают null,
 * а write()/delete() ничего не делают. Поведение системы при этом
 * не меняется, просто всё читается из источника каждый раз.
 *
 * === ДЛЯ МЕНЯ ===
 * Этот класс был в ядре всегда, но потерялся при переезде.
 * config/container.php вызывает new Cache($basePath, $enabled) —
 * вот он, родной.
 *
 * === КНИГА ===
 * Глава 6. Память.
 *
 * Тени помнят то, что было. Кэш помнит то, что уже посчитано.
 * И то и другое можно стереть — реальность от этого не изменится.
 */

namespace Jan\Trinity\Core;

class Cache
{
    /** @var string Каталог кэша (var/cache/data) */
    private string $cacheDir;

    /** @var bool Включён ли кэш */
    private bool $enabled;

    /** @var array Инмемори-слой записей текущего запроса */
    private array $memo = [];

    /**
     * @param string $basePath — корень проекта
     * @param bool $enabled — использовать кэш или нет
     */
    public function __construct(string $basePath, bool $enabled = false)
    {
        $this->cacheDir = rtrim($basePath, '/') . '/var/cache/data';
        $this->enabled = $enabled;
    }

    /**
     * Прочитать значение по ключу.
     *
     * @return mixed|null — null если кэш выключен или записи нет/она истекла
     */
    public function read(string $key): mixed
    {
        if (!$this->enabled) {
            return null;
        }

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $entry = json_decode($raw, true);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return null;
        }

        if (($entry['ttl'] ?? 0) > 0 && time() > $entry['expires_at']) {
            @unlink($file);
            return null;
        }

        return $this->memo[$key] = $entry['value'];
    }

    /**
     * Записать значение по ключу.
     *
     * @param string $key
     * @param mixed $value — сериализуемое в JSON значение
     * @param int $ttl — время жизни в секундах (0 = бессрочно)
     */
    public function write(string $key, mixed $value, int $ttl = 0): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->memo[$key] = $value;

        $dir = dirname($this->path($key));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $entry = [
            'key' => $key,
            'ttl' => $ttl,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
            'value' => $value,
        ];

        file_put_contents(
            $this->path($key),
            json_encode($entry, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * Удалить запись по ключу.
     */
    public function delete(string $key): void
    {
        unset($this->memo[$key]);

        $file = $this->path($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Очистить весь кэш.
     */
    public function clear(): void
    {
        $this->memo = [];

        if (!is_dir($this->cacheDir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }

    /**
     * Путь к файлу записи. Ключ безвредно хешируется в имя файла.
     */
    private function path(string $key): string
    {
        $hash = sha1($key);
        return $this->cacheDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    }
}
