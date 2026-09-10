<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Queue;

use Jan\Trinity\Core\DatabaseService;
use Jan\Trinity\Core\Services\Logger;

/**
 * Менеджер очереди задач.
 * 
 * Обрабатывает хранение и выполнение фоновых задач.
 * Задачи хранятся в таблице `queue_jobs` базы данных.
 * 
 * @author Trinity Core Team
 */
class Queue
{
    private DatabaseService $db;
    private Logger $logger;

    /**
     * Конструктор менеджера очереди.
     * 
     * @param DatabaseService $db Сервис базы данных
     * @param Logger $logger Сервис логирования
     */
    public function __construct(DatabaseService $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->ensureTableExists();
    }

    /**
     * Создает таблицу очереди, если она не существует.
     * 
     * @return void
     */
    private function ensureTableExists(): void
    {
        $this->db->executeStatement("
            CREATE TABLE IF NOT EXISTS queue_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_class VARCHAR(255) NOT NULL,
                payload JSON NOT NULL,
                queue_name VARCHAR(50) DEFAULT 'default',
                status VARCHAR(20) DEFAULT 'pending',
                attempts INT DEFAULT 0,
                max_attempts INT DEFAULT 3,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                executed_at TIMESTAMP NULL,
                error_message TEXT NULL,
                INDEX idx_status_queue (status, queue_name),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Добавляет задачу в очередь.
     * 
     * @param JobInterface $job Экземпляр задачи
     * @param array $payload Данные для задачи
     * @return int ID добавленной задачи
     */
    public function push(JobInterface $job, array $payload = []): int
    {
        $jobClass = get_class($job);
        $queueName = $job->getQueue();
        $maxAttempts = $job->getMaxAttempts();

        $this->db->insert('queue_jobs', [
            'job_class' => $jobClass,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'queue_name' => $queueName,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => $maxAttempts
        ]);

        $jobId = (int) $this->db->lastInsertId();

        $this->logger->info('Задача добавлена в очередь: {job} (ID: {id}, очередь: {queue})', [
            'job' => $jobClass,
            'id' => $jobId,
            'queue' => $queueName
        ]);

        return $jobId;
    }

    /**
     * Извлекает следующую задачу из очереди для выполнения.
     * 
     * @param string $queueName Имя очереди (по умолчанию 'default')
     * @return array|null Массив с данными задачи или null, если задач нет
     */
    public function pop(string $queueName = 'default'): ?array
    {
        $conn = $this->db->getConnection();
        
        // Блокируем строку для предотвращения гонки
        $conn->beginTransaction();
        
        try {
            $stmt = $conn->executeQuery("
                SELECT id, job_class, payload, queue_name, attempts, max_attempts
                FROM queue_jobs
                WHERE status = 'pending' AND queue_name = ?
                ORDER BY created_at ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            ", [$queueName]);

            $job = $stmt->fetchAssociative();

            if ($job) {
                // Обновляем статус на 'processing'
                $this->db->update('queue_jobs', [
                    'status' => 'processing',
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $job['id']]);

                $conn->commit();
                
                $job['payload'] = json_decode($job['payload'], true);
                return $job;
            }

            $conn->commit();
            return null;
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Отмечает задачу как выполненную успешно.
     * 
     * @param int $jobId ID задачи
     * @return void
     */
    public function markAsCompleted(int $jobId): void
    {
        $this->db->update('queue_jobs', [
            'status' => 'completed',
            'executed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $jobId]);

        $this->logger->debug('Задача ID {id} выполнена успешно', ['id' => $jobId]);
    }

    /**
     * Отмечает задачу как проваленную.
     * 
     * @param int $jobId ID задачи
     * @param string $errorMessage Сообщение об ошибке
     * @param bool $shouldRetry Следует ли повторить попытку
     * @return void
     */
    public function markAsFailed(int $jobId, string $errorMessage, bool $shouldRetry = true): void
    {
        if ($shouldRetry) {
            $this->db->update('queue_jobs', [
                'status' => 'pending',
                'attempts' => $this->db->fetchOne(
                    "SELECT attempts FROM queue_jobs WHERE id = ?", 
                    [$jobId]
                ) + 1,
                'error_message' => $errorMessage,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $jobId]);

            $this->logger->warning('Задача ID {id} будет повторена. Ошибка: {error}', [
                'id' => $jobId,
                'error' => $errorMessage
            ]);
        } else {
            $this->db->update('queue_jobs', [
                'status' => 'failed',
                'error_message' => $errorMessage,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $jobId]);

            $this->logger->error('Задача ID {id} провалена после исчерпания попыток. Ошибка: {error}', [
                'id' => $jobId,
                'error' => $errorMessage
            ]);
        }
    }

    /**
     * Возвращает статистику по очереди.
     * 
     * @param string|null $queueName Имя очереди (null для всех очередей)
     * @return array Статистика по статусам
     */
    public function getStats(?string $queueName = null): array
    {
        $params = [];
        $where = '';

        if ($queueName) {
            $where = 'WHERE queue_name = ?';
            $params = [$queueName];
        }

        $result = $this->db->fetchAssociative("
            SELECT 
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM queue_jobs
            $where
        ", $params);

        return array_map('intval', $result);
    }

    /**
     * Очищает старые выполненные задачи.
     * 
     * @param int $days Количество дней хранения (по умолчанию 7)
     * @return int Количество удаленных записей
     */
    public function cleanOldJobs(int $days = 7): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $affected = $this->db->executeStatement("
            DELETE FROM queue_jobs 
            WHERE status IN ('completed', 'failed') AND executed_at < ?
        ", [$cutoffDate]);

        $this->logger->info('Очищено {count} старых задач', ['count' => $affected]);

        return $affected;
    }
}
