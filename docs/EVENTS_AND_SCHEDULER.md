# Система событий и планировщик Trinity Core

## Обзор

В рамках стратегии "трёх таблиц" добавлены два новых типа нейронов:
- `schedule` — задачи по расписанию (cron)
- `event_listener` — слушатели событий

---

## 1. ПЛАНИРОВЩИК (Scheduler)

### Структура нейрона типа `schedule`

| Виртуальный столбец | Описание |
|---------------------|----------|
| `schedule_cron` | Cron-выражение (5 полей: минута час день месяц день_недели) |
| `schedule_command` | Команда для выполнения (класс или Class@method) |
| `schedule_last_run` | Дата последнего выполнения |
| `schedule_next_run` | Дата следующего выполнения |
| `schedule_is_active` | 1 = активно, 0 = отключено |

### Примеры cron-выражений

```
* * * * *    # Каждую минуту
0 * * * *    # Каждый час в 0 минут
0 0 * * *    # Каждый день в полночь
0 0 * * 0    # Каждое воскресенье в полночь
0 0 1 * *    # 1-го числа каждого месяца в полночь
*/5 * * * *  # Каждые 5 минут
0 9-17 * * * # Каждый час с 9 до 17
```

### Использование

#### Добавление расписания через API/код

```php
use Jan\Trinity\Core\Schedule\Scheduler;

$scheduler = $container->get(Scheduler::class);

// Ежедневная очистка кэша в полночь
$scheduler->addSchedule(
    '0 0 * * *',
    CacheClearHandler::class . '@execute'
);

// Отчёт каждый понедельник в 9 утра
$scheduler->addSchedule(
    '0 9 * * 1',
    WeeklyReportHandler::class,
    ['recipients' => ['admin@example.com']]
);
```

#### Запуск планировщика

Добавьте в crontab (запуск каждую минуту):

```bash
* * * * * cd /path/to/trinity && php bin/scheduler >> var/log/scheduler.log 2>&1
```

#### Пример обработчика

```php
<?php

namespace App\Handlers;

class CacheClearHandler
{
    public function execute(array $data = []): bool
    {
        // Очистка кэша
        cache()->clear();
        return true;
    }
}
```

---

## 2. СОБЫТИЯ (EventDispatcher)

### Структура нейрона типа `event_listener`

| Виртуальный столбец | Описание |
|---------------------|----------|
| `event_name` | Имя события (например, `user.login`, `page.created`) |
| `event_callback` | Callback (класс или Class@method) |
| `event_priority` | Приоритет (чем выше, тем раньше выполняется) |
| `event_is_active` | 1 = активно, 0 = отключено |

### Стандартные события системы

| Событие | Описание | Payload |
|---------|----------|---------|
| `user.login` | Вход пользователя | `['user_id' => int, 'login' => string]` |
| `user.logout` | Выход пользователя | `['user_id' => int]` |
| `user.created` | Создание пользователя | `['user_id' => int, 'data' => array]` |
| `user.updated` | Обновление пользователя | `['user_id' => int, 'changes' => array]` |
| `user.deleted` | Удаление пользователя | `['user_id' => int]` |
| `page.created` | Создание страницы | `['page_id' => int, 'slug' => string]` |
| `page.updated` | Обновление страницы | `['page_id' => int]` |
| `page.deleted` | Удаление страницы | `['page_id' => int]` |
| `config.changed` | Изменение конфигурации | `['key' => string, 'value' => mixed]` |
| `job.completed` | Задача выполнена | `['job_id' => int, 'result' => mixed]` |
| `job.failed` | Задача не выполнена | `['job_id' => int, 'error' => string]` |

### Использование

#### Добавление слушателя

```php
use Jan\Trinity\Core\Event\EventDispatcher;

$dispatcher = $container->get(EventDispatcher::class);

// Слушатель с приоритетом
$dispatcher->addListener(
    'user.login',
    LoginLogger::class . '@log',
    10 // приоритет
);

// Слушатель-класс
$dispatcher->addListener(
    'page.created',
    PageCreatedListener::class,
    0
);
```

#### Публикация события

```php
// В любом месте кода
$results = $dispatcher->dispatch('user.login', [
    'user_id' => $userId,
    'login' => $userLogin,
]);

// Анализ результатов
foreach ($results as $result) {
    if (!$result['success']) {
        error_log("Listener failed: {$result['callback']} - {$result['error']}");
    }
}
```

#### Пример слушателя

```php
<?php

namespace App\Listeners;

use Jan\Trinity\Core\Event\EventListenerInterface;

class LoginLogger implements EventListenerInterface
{
    public function handle(array $payload = []): bool
    {
        $userId = $payload['user_id'] ?? null;
        $login = $payload['login'] ?? 'unknown';
        
        error_log("User {$login} (ID: {$userId}) logged in at " . date('Y-m-d H:i:s'));
        
        return true;
    }
}
```

#### Слушатель в формате Class@method

```php
<?php

namespace App\Listeners;

class AuthListeners
{
    public static function logLogin(array $payload): void
    {
        // Логирование входа
    }
    
    public static function sendNotification(array $payload): void
    {
        // Отправка уведомления
    }
}
```

Регистрация:
```php
$dispatcher->addListener('user.login', AuthListeners::class . '@logLogin');
```

---

## 3. ИНТЕГРАЦИЯ С ЯДРОМ

### Регистрация в DI-контейнере

Оба сервиса автоматически доступны через контейнер:

```php
$dispatcher = $container->get(\Jan\Trinity\Core\Event\EventDispatcher::class);
$scheduler = $container->get(\Jan\Trinity\Core\Schedule\Scheduler::class);
```

### Внедрение в контроллеры

```php
class MyController
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private Scheduler $scheduler
    ) {}
    
    public function action(): Response
    {
        // Использование
        $this->dispatcher->dispatch('custom.event', ['data' => 'value']);
        return new Response('OK');
    }
}
```

---

## 4. ПРИМЕРЫ ИСПОЛЬЗОВАНИЯ

### Пример 1: Автоматическая отправка отчётов

```php
// Создаём расписание
$scheduler->addSchedule(
    '0 9 * * 1', // Каждый понедельник в 9:00
    ReportHandler::class . '@sendWeekly',
    ['type' => 'sales']
);
```

### Пример 2: Логирование всех действий админа

```php
// Слушатель
$dispatcher->addListener('admin.action', AdminLogger::class, 100);
```

### Пример 3: Очистка старых данных

```php
// Ежедневная очистка
$scheduler->addSchedule(
    '0 3 * * *', // Каждый день в 3:00
    DataCleanupHandler::class
);
```

### Пример 4: Уведомления при критических ошибках

```php
$dispatcher->addListener('job.failed', CriticalErrorNotifier::class, 1000);
```

---

## 5. КОНСОЛЬНЫЕ КОМАНДЫ

### Запуск планировщика

```bash
php bin/scheduler
```

Рекомендуется добавить в crontab:
```bash
* * * * * php /path/to/bin/scheduler
```

---

## 6. МОНИТОРИНГ

### Проверка активных расписаний

```sql
SELECT id, schedule_cron, schedule_command, schedule_last_run, schedule_next_run
FROM neuron 
WHERE type = 'schedule' AND schedule_is_active = 1;
```

### Проверка слушателей события

```sql
SELECT id, event_name, event_callback, event_priority
FROM neuron 
WHERE type = 'event_listener' AND event_is_active = 1
ORDER BY event_name, event_priority DESC;
```

---

## 7. БЕЗОПАСНОСТЬ

- Все callback'и должны существовать как классы
- Рекомендуется использовать whitelist для команд планировщика
- Логирование всех выполнений и ошибок
- Приоритеты позволяют контролировать порядок выполнения

---

## 8. РАСШИРЕНИЕ

Для добавления собственных событий:

1. Определите имя события (например, `myplugin.custom`)
2. Зарегистрируйте слушателей через `EventDispatcher::addListener()`
3. Публикуйте событие через `EventDispatcher::dispatch()`

Для добавления расписаний:

1. Создайте класс-обработчик с методом `execute()` или `methodName()`
2. Добавьте расписание через `Scheduler::addSchedule()`
3. Настройте cron для запуска `bin/scheduler`
