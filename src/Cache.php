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
            return json_decode($data, true)['value'] ?? null;
        }

        // Создаём новое значение
        $value = $callback();
        file_put_contents($file, json_encode([
            'value'     => $value,
            'created_at' => date('c'),
            'ttl'       => $ttl,
        ]));

        return $value;
    }

    /**
     * Удалить значение из кэша.
     * 
     * @param string $key — ключ кэша
     */
    public function forget(string $key): void
    {
        if (!$this->enabled) return;

        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Очистить весь кэш.
     */
    public function clear(): void
    {
        if (!$this->enabled || !is_dir($this->cacheDir)) return;

        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
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