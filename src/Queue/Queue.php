<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Queue;

use Jan\Trinity\Core\DatabaseService;
use Jan\Trinity\Core\Services\Logger;

/**
 * Менеджер очереди задач.
 * 
 * Обрабатывает хранение и выполнение фоновых задач.
 * Задачи хранятся в таблице `neuron` с type='job'.
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
    }

    /**
     * Добавляет задачу в очередь.
     * 
     * @param JobInterface $job Экземпляр задачи
     * @param array $payload Данные для задачи
     * @return int ID добавленной задачи (ID нейрона)
     */
    public function push(JobInterface $job, array $payload = []): int
    {
        $jobClass = get_class($job);
        $queueName = $job->getQueue();
        $maxAttempts = $job->getMaxAttempts();

        // Создаём нейрон типа 'job'
        $data = [
            'job_class' => $jobClass,
            'payload' => $payload,
            'queue_name' => $queueName,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $conn = $this->db->getConnection();
        $conn->insert('neuron', [
            'pid' => null,
            'type' => 'job',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'date' => date('Y-m-d H:i:s')
        ]);

        $jobId = (int) $conn->lastInsertId();

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
                SELECT id, 
                       JSON_EXTRACT(data, '$.job_class') as job_class,
                       JSON_EXTRACT(data, '$.payload') as payload,
                       JSON_EXTRACT(data, '$.queue_name') as queue_name,
                       JSON_EXTRACT(data, '$.attempts') as attempts,
                       JSON_EXTRACT(data, '$.max_attempts') as max_attempts
                FROM neuron
                WHERE type = 'job'
                  AND is_deleted = 0
                  AND JSON_EXTRACT(data, '$.status') = 'pending'
                  AND JSON_EXTRACT(data, '$.queue_name') = ?
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            ", [$queueName]);

            $job = $stmt->fetchAssociative();

            if ($job) {
                // Обновляем статус на 'processing'
                $currentData = json_decode($job['payload'], true);
                $currentData['status'] = 'processing';
                $currentData['updated_at'] = date('Y-m-d H:i:s');
                
                $conn->update('neuron', [
                    'data' => json_encode($currentData, JSON_UNESCAPED_UNICODE)
                ], ['id' => $job['id']]);

                $conn->commit();
                
                $job['payload'] = json_decode((string) $job['payload'], true);
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
        $neuron = $this->getJobNeuron($jobId);
        if (!$neuron) return;
        
        $data = is_string($neuron['data']) ? json_decode($neuron['data'], true) : $neuron['data'];
        $data['status'] = 'completed';
        $data['executed_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->db->update('neuron', [
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
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
        $neuron = $this->getJobNeuron($jobId);
        if (!$neuron) return;
        
        $data = is_string($neuron['data']) ? json_decode($neuron['data'], true) : $neuron['data'];
        
        if ($shouldRetry) {
            $data['status'] = 'pending';
            $data['attempts'] = ($data['attempts'] ?? 0) + 1;
            $data['error_message'] = $errorMessage;
            $data['updated_at'] = date('Y-m-d H:i:s');

            $this->db->update('neuron', [
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
            ], ['id' => $jobId]);

            $this->logger->warning('Задача ID {id} будет повторена. Ошибка: {error}', [
                'id' => $jobId,
                'error' => $errorMessage
            ]);
        } else {
            $data['status'] = 'failed';
            $data['error_message'] = $errorMessage;
            $data['updated_at'] = date('Y-m-d H:i:s');

            $this->db->update('neuron', [
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
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
        $where = "WHERE type = 'job' AND is_deleted = 0";

        if ($queueName) {
            $where .= " AND JSON_EXTRACT(data, '$.queue_name') = ?";
            $params = [$queueName];
        }

        $result = $this->db->fetchAssociative("
            SELECT 
                SUM(CASE WHEN JSON_EXTRACT(data, '$.status') = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN JSON_EXTRACT(data, '$.status') = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN JSON_EXTRACT(data, '$.status') = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN JSON_EXTRACT(data, '$.status') = 'failed' THEN 1 ELSE 0 END) as failed
            FROM neuron
            $where
        ", $params);

        return array_map('intval', $result ?: []);
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
            DELETE FROM neuron 
            WHERE type = 'job'
              AND is_deleted = 0
              AND JSON_EXTRACT(data, '$.status') IN ('completed', 'failed')
              AND JSON_EXTRACT(data, '$.executed_at') < ?
        ", [$cutoffDate]);

        $this->logger->info('Очищено {count} старых задач', ['count' => $affected]);

        return $affected;
    }

    /**
     * Получает данные нейрона-задачи.
     * 
     * @param int $jobId ID задачи
     * @return array|null
     */
    private function getJobNeuron(int $jobId): ?array
    {
        return $this->db->getConnection()->executeQuery(
            "SELECT * FROM neuron WHERE id = ? AND type = 'job' AND is_deleted = 0",
            [$jobId]
        )->fetchAssociative() ?: null;
    }
}
