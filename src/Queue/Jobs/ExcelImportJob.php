<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Queue\Jobs;

use Jan\Trinity\Core\Queue\JobInterface;
use Jan\Trinity\Core\Services\Logger;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Задача импорта Excel-файла.
 * 
 * Обрабатывает загрузку данных из Excel-файла в фоновом режиме.
 * Используется плагином admin для асинхронного импорта.
 */
class ExcelImportJob implements JobInterface
{
    private Logger $logger;

    /**
     * Конструктор задачи.
     * 
     * @param Logger $logger Сервис логирования
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Выполняет импорт Excel-файла.
     * 
     * Ожидает в payload:
     * - file_path: путь к файлу
     * - user_id: ID пользователя, запустившего импорт
     * - entity_type: тип сущности для импорта
     * 
     * @param array $payload Данные задачи
     * @return void
     * @throws \Exception Если файл не найден или некорректен
     */
    public function execute(array $payload): void
    {
        $filePath = $payload['file_path'] ?? '';
        $userId = $payload['user_id'] ?? 0;
        $entityType = $payload['entity_type'] ?? 'neuron';

        if (!file_exists($filePath)) {
            throw new \Exception("Файл не найден: {$filePath}");
        }

        $this->logger->info('Начало импорта Excel. Файл: {file}, Пользователь: {user}', [
            'file' => $filePath,
            'user' => $userId
        ]);

        // Чтение файла
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (empty($rows)) {
            throw new \Exception('Файл Excel пуст');
        }

        // Пропускаем заголовок, обрабатываем данные
        $header = array_shift($rows);
        $processedCount = 0;

        foreach ($rows as $rowIndex => $row) {
            // Эмуляция обработки строки
            // В реальном проекте здесь будет логика сохранения в БД
            $processedCount++;
        }

        // Удаляем временный файл
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $this->logger->info('Импорт Excel завершен. Обработано строк: {count}', [
            'count' => $processedCount
        ]);
    }

    /**
     * Возвращает имя очереди для задач импорта.
     * 
     * @return string
     */
    public function getQueue(): string
    {
        return 'import';
    }

    /**
     * Максимальное количество попыток для импорта.
     * 
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return 2;
    }

    /**
     * Логирование успешного импорта.
     * 
     * @param array $payload
     * @return void
     */
    public function onSuccess(array $payload): void
    {
        $this->logger->info('Задача импорта успешно выполнена для пользователя {user}', [
            'user' => $payload['user_id'] ?? 'unknown'
        ]);
    }

    /**
     * Логирование провала импорта.
     * 
     * @param array $payload
     * @param \Throwable $exception
     * @return void
     */
    public function onFailure(array $payload, \Throwable $exception): void
    {
        $this->logger->error('Задача импорта провалена для пользователя {user}. Ошибка: {error}', [
            'user' => $payload['user_id'] ?? 'unknown',
            'error' => $exception->getMessage()
        ]);
    }
}
