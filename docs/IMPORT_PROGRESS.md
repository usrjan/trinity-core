# Прогресс-бар импорта в Trinity Core

## Обзор

Реализована система отслеживания прогресса импорта Excel-файлов в реальном времени с использованием AJAX polling и Bootstrap progress bar.

## Компоненты

### 1. Frontend (HTML + JS)

#### HTML (`index.html.twig`)
Добавлен прогресс-бар в интерфейс админки:
```html
<div id="importProgressContainer" class="mb-3" style="display:none;">
    <div class="card bg-dark border-secondary">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted" id="importStatusText">Импорт...</small>
                <small class="text-info" id="importPercentText">0%</small>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-info" 
                     id="importProgressBar" role="progressbar" style="width: 0%;"></div>
            </div>
            <div id="importDetails" class="mt-1 small text-muted"></div>
        </div>
    </div>
</div>
```

#### JavaScript (`admin.js`)
Функции для работы с прогресс-баром:

- **`importExcel(file)`** — начинает импорт, показывает прогресс-бар
- **`pollImportStatus(jobId)`** — опрашивает сервер каждые 1.5 секунды
- **`showImportComplete(message)`** — показывает успешное завершение (зелёный)
- **`showImportError(message)`** — показывает ошибку (красный)

### 2. Backend (PHP)

#### AdminController.php
Новые методы:

**`import(Request $request)`**
- Для файлов > 1MB создаёт задачу типа `job` в БД
- Возвращает `job_id` для фонового выполнения
- Для маленьких файлов выполняет синхронно

**`importStatus(int $jobId)`**
- Возвращает статус задачи: `pending`, `processing`, `completed`, `failed`
- Прогресс в процентах (0-100)
- Текущий лист Excel
- Количество обработанных строк
- Список ошибок

#### plugin.php
Добавлен маршрут:
```php
$routes->add('admin_import_status', new Route('/api/admin/import-status/{jobId}', [
    '_controller' => AdminController::class,
    '_method'     => 'importStatus',
], ['jobId' => '\\d+'], [], '', [], ['GET']));
```

## Как это работает

### Схема процесса

```
1. Пользователь выбирает файл
   ↓
2. Frontend отправляет POST /api/admin/import
   ↓
3. Backend проверяет размер файла:
   ├─ < 1MB → Синхронный импорт → Ответ сразу
   └─ > 1MB → Создаёт job → Возвращает job_id
   ↓
4. Frontend получает job_id → Запускает pollImportStatus()
   ↓
5. Каждые 1.5 сек: GET /api/admin/import-status/{jobId}
   ↓
6. Обновление прогресс-бара:
   ├─ Ширина полоски (%)
   ├─ Текст статуса
   ├─ Детали (лист, строки, ошибки)
   ↓
7. При завершении (completed/failed):
   ├─ Остановка опроса
   ├─ Показ финального сообщения
   ├─ Перезагрузка дерева нейронов
   └─ Скрытие прогресс-бара через 3-5 сек
```

### Статусы задачи

| Статус | Описание | Цвет |
|--------|----------|------|
| `pending` | В очереди на выполнение | Синий (анимированный) |
| `processing` | Выполняется | Синий |
| `completed` | Успешно завершено | Зелёный |
| `failed` | Ошибка выполнения | Красный |

## Пример ответа API

```json
{
    "success": true,
    "data": {
        "status": "processing",
        "progress": 45,
        "current_sheet": "ITEM",
        "processed_rows": 450,
        "total_rows": 1000,
        "errors": []
    }
}
```

## Обновление данных в ExcelImportService

Для корректной работы прогресс-бара сервис импорта должен обновлять данные задачи:

```php
// В начале импорта
$this->neuronRepo->update($jobId, [
    'data' => [
        'status' => 'processing',
        'progress' => 0,
        'total_rows' => $totalRows,
        'current_sheet' => $sheetName,
        'processed_rows' => 0,
        'errors' => [],
    ]
]);

// После обработки каждой строки
$percent = intval(($processedRows / $totalRows) * 100);
$this->neuronRepo->update($jobId, [
    'data' => [
        'status' => 'processing',
        'progress' => $percent,
        'processed_rows' => $processedRows,
        'current_sheet' => $currentSheet,
    ]
]);

// По завершении
$this->neuronRepo->update($jobId, [
    'data' => [
        'status' => 'completed',
        'progress' => 100,
        'executed_at' => date('Y-m-d H:i:s'),
        'processed_rows' => $processedRows,
        'errors' => $errors,
    ]
]);
```

## Преимущества

1. **Отклик UI** — пользователь видит прогресс в реальном времени
2. **Нет таймаутов** — большие файлы обрабатываются в фоне
3. **Информативность** — видно текущий лист и количество строк
4. **Обработка ошибок** — ошибки показываются по мере возникновения
5. **Масштабируемость** — можно обрабатывать файлы любого размера

## Требования

- Bootstrap 5 (для стилей прогресс-бара)
- Vanilla JS (без зависимостей)
- PHP 8.4+
- База данных с поддержкой JSON (MySQL 5.7+)

## Тестирование

1. Откройте `/admin`
2. Нажмите кнопку импорта (📤)
3. Выберите большой Excel-файл (> 1MB)
4. Наблюдайте за прогресс-баром:
   - Должен показывать процент выполнения
   - Обновляться каждые 1.5 секунды
   - Показать зелёный цвет при успехе
   - Показать красный цвет при ошибке

## Примечания

- Для очень больших файлов рекомендуется использовать фоновый воркер (`php bin/worker`)
- Прогресс-бар автоматически скрывается через 3 секунды после успеха
- Через 5 секунд после ошибки
- Все данные импорта сохраняются в истории задач (нейроны типа `job`)
