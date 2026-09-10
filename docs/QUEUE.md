# Система очередей Trinity Core

## 📋 Обзор

Система асинхронных очередей позволяет выполнять тяжелые задачи в фоновом режиме, не блокируя основной поток приложения. Это критически важно для:
- Импорта/экспорта больших файлов
- Отправки массовых уведомлений
- Генерации отчетов
- Обработки изображений
- Любых долгих операций

## 🏗 Архитектура

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│ Контроллер  │────▶│   Queue      │────▶│  База данных│
│ (веб-запрос)│     │  (менеджер)  │     │  (таблица)  │
└─────────────┘     └──────────────┘     └─────────────┘
                           │
                           ▼
                    ┌──────────────┐
                    │ bin/worker   │
                    │ (фоновый демон)│
                    └──────────────┘
```

## 📁 Структура файлов

```
src/Queue/
├── JobInterface.php      # Интерфейс всех задач
├── Queue.php             # Менеджер очереди
└── Jobs/
    └── ExcelImportJob.php  # Пример задачи импорта

bin/
└── worker                # Консольный воркер
```

## 🚀 Быстрый старт

### 1. Создание задачи

```php
use Trinity\Queue\JobInterface;
use Trinity\Services\Logger;

class MyCustomJob implements JobInterface
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function execute(array $payload): void
    {
        // Логика задачи
        $this->logger->info('Выполняется задача', $payload);
        
        // Ваша бизнес-логика здесь
        // ...
    }

    public function getQueue(): string
    {
        return 'default'; // или 'import', 'email', etc.
    }

    public function getMaxAttempts(): int
    {
        return 3;
    }

    public function onSuccess(array $payload): void
    {
        $this->logger->info('Задача выполнена успешно');
    }

    public function onFailure(array $payload, \Throwable $exception): void
    {
        $this->logger->error('Задача провалена: ' . $exception->getMessage());
    }
}
```

### 2. Добавление задачи в очередь

```php
// В контроллере через DI
class MyController {
    public function __construct(private Queue $queue) {}
    
    public function action(): void
    {
        $job = new MyCustomJob($this->logger);
        
        $jobId = $this->queue->push($job, [
            'user_id' => 123,
            'data' => 'some data'
        ]);
        
        echo "Задача добавлена с ID: {$jobId}";
    }
}
```

### 3. Запуск воркера

**Однократная обработка (для тестов):**
```bash
php bin/worker --once --verbose
```

**Постоянная работа (продакшен):**
```bash
# Обработка очереди по умолчанию
php bin/worker

# Обработка конкретной очереди
php bin/worker --queue=import

# Обработка нескольких очередей
php bin/worker --queue=default,import,email

# Подробный вывод
php bin/worker --verbose
```

**Запуск в фоне (Linux):**
```bash
nohup php bin/worker --queue=import > /var/log/trinity_worker.log 2>&1 &
```

**Systemd сервис (рекомендуется для продакшена):**
```ini
# /etc/systemd/system/trinity-worker.service
[Unit]
Description=Trinity Queue Worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/trinity-core
ExecStart=/usr/bin/php bin/worker --queue=default,import
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable trinity-worker
sudo systemctl start trinity-worker
```

## 📊 Мониторинг очереди

### Получение статистики

```php
$stats = $queue->getStats();
// ['pending' => 5, 'processing' => 2, 'completed' => 100, 'failed' => 1]

$importStats = $queue->getStats('import');
// Статистика только по очереди 'import'
```

### Очистка старых задач

```php
// Удалить выполненные задачи старше 7 дней
$deletedCount = $queue->cleanOldJobs(7);
```

## 🔧 Настройка

Таблица `queue_jobs` создается автоматически при первом использовании.

**Структура таблицы:**
- `id` — ID задачи
- `job_class` — полный класс задачи
- `payload` — JSON с данными
- `queue_name` — имя очереди
- `status` — pending/processing/completed/failed
- `attempts` — количество попыток
- `max_attempts` — лимит попыток
- `created_at`, `updated_at`, `executed_at` — временные метки
- `error_message` — текст ошибки при провале

**Индексы:**
- `(status, queue_name)` — для быстрого поиска задач
- `(created_at)` — для сортировки по времени

## 🎯 Примеры использования

### Импорт Excel (уже реализовано)

```php
$job = new ExcelImportJob($logger);
$queue->push($job, [
    'file_path' => '/tmp/upload_123.xlsx',
    'user_id' => $currentUserId,
    'entity_type' => 'products'
]);
```

### Массовая рассылка email

```php
class EmailNotificationJob implements JobInterface
{
    public function execute(array $payload): void
    {
        $userId = $payload['user_id'];
        $message = $payload['message'];
        
        // Отправка email
        mail($userEmail, $subject, $message);
    }
    
    public function getQueue(): string { return 'email'; }
    public function getMaxAttempts(): int { return 5; }
    // ... остальные методы
}

// Использование
$queue->push(new EmailNotificationJob($logger), [
    'user_id' => 42,
    'message' => 'Ваш заказ готов'
]);
```

### Генерация отчета

```php
class ReportGenerationJob implements JobInterface
{
    public function execute(array $payload): void
    {
        $reportType = $payload['type'];
        $dateFrom = $payload['date_from'];
        $dateTo = $payload['date_to'];
        
        // Долгая генерация отчета
        $reportData = $this->generateReport($reportType, $dateFrom, $dateTo);
        
        // Сохранение результата
        file_put_contents("/reports/{$reportType}.pdf", $reportData);
    }
    
    public function getQueue(): string { return 'reports'; }
    public function getMaxAttempts(): int { return 2; }
    // ...
}
```

## ⚠️ Важные замечания

1. **Идемпотентность**: Задачи должны быть идемпотентными — повторное выполнение с теми же данными должно давать тот же результат.

2. **Таймауты**: Для очень долгих задач увеличьте `max_execution_time` в PHP или разбейте задачу на подзадачи.

3. **Блокировки**: Используется `FOR UPDATE SKIP LOCKED` для предотвращения гонки между воркерами.

4. **Логирование**: Все действия логируются. Проверяйте `var/logs/` для отладки.

5. **Память**: При обработке больших объемов данных освобождайте память: `unset($largeData)`.

6. **Тестирование**: Используйте флаг `--once --verbose` для отладки задач.

## 🛠 Отладка

**Просмотр задач в БД:**
```sql
SELECT id, job_class, status, attempts, created_at 
FROM queue_jobs 
ORDER BY created_at DESC 
LIMIT 20;
```

**Очистка проваленных задач:**
```sql
DELETE FROM queue_jobs WHERE status = 'failed' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
```

**Перезапуск проваленных задач:**
```sql
UPDATE queue_jobs SET status = 'pending', attempts = 0, error_message = NULL 
WHERE status = 'failed';
```

## 📈 Масштабирование

Для высокой нагрузки:
1. Запустите несколько воркеров на разных серверах
2. Разделите очереди по типам задач (`import`, `email`, `reports`)
3. Настройте приоритеты через порядок обработки очередей
4. Используйте отдельные базы данных для очередей

---

**Автор**: Trinity Core Team  
**Версия**: 1.0  
**Лицензия**: MIT
