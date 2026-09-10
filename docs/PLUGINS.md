# Плагины Trinity

## Структура

plugins/
├── spa/
│ ├── plugin.php
│ ├── MenuController.php
│ ├── PageController.php
│ └── ...
├── admin/
├── users/
├── monitor/
├── guard/
├── map/
├── tools/
└── gallery/

## Как создать плагин

1. Создать папку `plugins/myplugin/`
2. Создать `plugin.php`:

```php
use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\MyPlugin\MyController;

return function ($routes) {
    $routes->add('my_page', new Route('/my', [
        '_controller' => MyController::class,
        '_method'     => 'index',
    ]));
};
```

3. Создать контроллер:

```php
namespace Jan\Trinity\Plugin\MyPlugin;

use Symfony\Component\HttpFoundation\Response;

class MyController
{
    public function index(): Response
    {
        return new Response('Hello from MyPlugin');
    }
}
```

4. Добавить namespace в composer.json ядра:

```json
"autoload": {
    "psr-4": {
        "Jan\\Trinity\\Plugin\\MyPlugin\\": "plugins/myplugin/"
    }
}
```

5. composer dump-autoload

Готово. Плагин появится в системе.

---

## 📄 `docs/API.md`

```markdown
# API Trinity

## Формат ответа

Все API-методы возвращают JSON в едином формате:

```json
{
    "success": true,
    "data": {},
    "message": null,
    "meta": {
        "timestamp": "2026-07-29T10:00:00+03:00",
        "version": "1.2.3",
        "request_id": "..."
    },
    "error": null
}
```

Основные эндпоинты

Метод	Путь	Описание
GET	/api/menu/sidebar	Боковое меню
GET	/api/menu/topbar	Верхняя панель
GET	/api/page/{slug}	Страница
POST	/api/page/section	Добавить секцию
POST	/api/menu/add-item	Добавить пункт меню
GET	/api/admin/tree	Дерево нейронов
POST	/api/admin/neuron	Создать нейрон
DELETE	/api/admin/neuron/{id}	Удалить нейрон
GET	/api/map/geojson	GeoJSON карты
GET	/api/map/info/{id}	Информация об объекте
POST	/api/monitor/js-error	JS-ошибка
GET	/api/monitor/logs	Логи
POST	/api/gallery/upload	Загрузка в галерею
POST	/api/tools/report-social	Отчёт по соцсетям
POST	/login	Вход
POST	/register	Регистрация
GET	/logout	Выход
GET	/profile	Профиль
POST	/profile/edit	Сохранить профиль
Аутентификация
Сессия: $_SESSION['user_id'], $_SESSION['user_login'], $_SESSION['user_roles']

CSRF: заголовок X-CSRF-Token или поле _csrf_token

