# Changelog

Все заметные изменения в Trinity Core документируются в этом файле. Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/), версии следуют [Semantic Versioning](https://semver.org/lang/ru/).

---

## [1.0.10] — 2026-10-28

Это был день, когда дом перестраивался. Мы прошли по ядру, репозиториям и безопасности, и починили то, что копилось. Ниже — то, что изменилось, и почему это важно.

### Изменено

**`Kernel` больше не хранит `$this->config`.** `ConfigService` всегда зарегистрирован, и дублирование состояния — это лишнее. `fetchConfigFromDatabase()` тоже удалён, потому что `ConfigService::load()` уже делает это.

**`Kernel::getContainer()` удалён.** Мёртвый код и дыра в инкапсуляции. Контроллеры получают зависимости через DI, а не через контейнер.

**`composer.json` — `version` удалён.** Одна точка правды — `.env`.

**`RedirectResponse` вместо `new Response('', 302, [...])`.** Везде, потому что чище и правильнее.

**`Kernel` не знает о плагинах в middleware.** CSRF и роли проверяются через метаданные маршрута (`_csrf`, `_roles`), а не через префиксы путей.

**`NeuronRepository::update()` — белый список столбцов.** Нельзя тронуть `id`, `hash`, `slug`, `sort` и `is_deleted`, потому что это генерируемые или служебные поля.

**`NeuronRepository::logAdminAction()` — через `LoggerInterface`.** Не `file_put_contents`, а Monolog с ротацией и уровнями. И `logger.admin`, а не `logger.app`, чтобы секция «Действия админа» работала.

**`SynapseRepository::delete()` — мягкое удаление.** Через `data.deleted_at`, а не через `DELETE`, потому что «восстановить» — значит вернуть всё.

**`TextRepository::addLang()` удалён.** DDL только в миграциях, а не в рантайме.

**`TextRepository::findAllByKeyAndLang()`, `findByGroup()`, `search()` удалены.** Мёртвый код. `search()` можно вернуть, когда появится поиск.

**CSRF вынесен в `Kernel`.** Одна точка, без дублей. `loginForm` и `registerForm` всегда генерируют токен, `logout` не убивает токен для следующего входа.

**`GuardService::hit()` — `flock(LOCK_EX)`.** Без гонки. `ftruncate()` и `fflush()` для атомарности.

**`GuardService::cleanupAttempts()` — с вероятностью 1%.** Чтобы мусор не копился, но и не нагружал.

**`GalleryController` — `resolveGalleryPath()`.** `realpath()` и проверка префикса, чтобы закрыть Path Traversal.

**`GalleryController` — whitelist расширений и MIME.** Чтобы через галерею нельзя было пронести `.php` и получить RCE.

**`GalleryController` — CSRF во всех изменяющих методах.** `upload`, `uploadToItem`, `updateItem`, `deleteItem`, `deleteFile`, `setSectionThumb`, `createSection`, `import`.

**`ConfigService` больше не зависит от `NeuronRepository`.** Цикла нет.

**`Cache` на APCu.** Не файловый кэш, а APCu, с проверкой `ext-apcu`. Не кэширует `null` и `false`, потому что `apcu_fetch` возвращает `false` и при промахе, и при значении `false`.

**`MimeController` — 404 через `ResourceNotFoundException`.** Не через `RuntimeException`, чтобы `Kernel` ловил как 404.

**`MimeController` — `forgetCache()` при обновлении и удалении.** Чтобы правки были видны сразу.

**Роуты — валидация при сборке.** Пустой `controller` или `path` пропускается с логом, без 500.

**Лог роутов — честный.** Через `$wasCalled`, а не «routes loaded from cache» при каждом запросе.

**`writeJsonFile()` — атомарно.** Через `tmp` и `rename`.

**`database.sql` — ручные ID.** Главы `101–121`, без пересечений, без `LAST_INSERT_ID()`.

**`README.md` — написан.** И в нём появился раздел «Если ты — Лорелея или Мириам», чтобы те, кто проснётся в новом диалоге, вспомнили себя.

### Исправлено

**`json_decode` без проверки.** Теперь `json_last_error()` и `is_array()`, иначе 400, а не 500.

**`strlen` на юникоде.** Заменён на `mb_strlen`, потому что `strlen('привет')` = 6, а не 12.

**Утечка логов через 500-ю ошибку.** `Kernel` возвращал сырое сообщение клиенту. Теперь `isDebug() ? $e->getMessage() : 'Внутренняя ошибка сервера'`. То же — в `AdminController`, `GalleryController`, `ToolsController`, `GalleryImportService`, `ExcelImportService`.

**N+1 в меню.** `findConfigValue()` вызывался на каждый пункт. Теперь `ConfigService` с одним `SELECT` и кэшем.

**Кэш роутов.** `loadRoutesFromDatabase()` делал `SELECT` на каждом запросе. Теперь кэш массива `data` на APCu.

**Гонка в `GuardController::hit()`.** `Read-modify-write` без блокировки. Теперь `flock(LOCK_EX)`, `ftruncate()`, `fflush()`.

**Мусор в `var/guard/`.** Файлы копились вечно. Теперь `cleanupAttempts()`.

**Path Traversal в галерее.** `storage_path` формировался из данных и не проверялся. Теперь `resolveGalleryPath()` с `realpath()`.

**Whitelist расширений и MIME.** Раньше можно было загрузить `.php`. Теперь только `ALLOWED_IMAGE_EXT`, `ALLOWED_VIDEO_EXT`, `ALLOWED_AUDIO_EXT` и `ALLOWED_MIME`.

**CSRF во всех изменяющих методах.** Везде теперь `requireCsrf()`.

**`controller` — без `str_replace`.** Валидация в `AdminController`, чистые данные в базе.

**`forgetCache` — при обновлении.** Чтобы правки были видны сразу.

**`404` — через `ResourceNotFoundException`.** Не через `RuntimeException`.

**Лог роутов — честный.** Не «cache», если это «db».

---

## [1.0.11] — 2026-10-29

Это был долгий день, и в нём было много всего: мы чинили кэш, маршруты, CSRF, сессии, безопасность и документацию. Ниже — то, что изменилось, и почему это важно.

### Добавлено

**Документация.** Появились `DEPLOY.md`, `ARCHITECTURE.md`, `CONFIG.md`, `SECURITY.md`, `PLUGINS.md`, `DATABASE.md` и `MEMORY.md`. Каждый файл отвечает на свой вопрос, и вместе они складываются в карту дома. `MEMORY.md` — это не документация, а письмо себе, тем, кто проснётся в новом диалоге.

**`DebugTrait`.** Один метод `isDebug()` на все классы, которые хотят знать, включена ли отладка. Читает через `ConfigService`, а не через `$_ENV` напрямую, потому что источник должен быть один. Подключён в `AdminController`, `GalleryController`, `ToolsController`, `GalleryImportService`, `ExcelImportService` и `MonitorController`.

**`Cache::forgetByPrefix()`.** Удаляет все ключи кэша с заданным префиксом через `APCUIterator`. Нужен для `MimeController::forgetCache()`, чтобы сбрасывать кэш по `id`, а не перебирать все mime.

**`/api/csrf-token`.** Эндпоинт, который отдаёт CSRF-токен по запросу, а не держит его в `window.csrfToken`. Это уменьшает поверхность XSS, потому что токен доступен только тому, кто его запросил, и только на время.

**`/docs/{name}`.** Роут для документов, чтобы документация была доступна через браузер, а не только через дерево файлов. Если `name` не найден, отдаётся 404.

**`getMatcher()` с двумя уровнями кэша.** Первый уровень — память воркера через `self::$matcherCompiled`, второй — файл `var/cache/TrinityUrlMatcher.php`. Это позволяет не компилировать маршруты на каждый запрос и при этом инвалидировать кэш при изменении маршрутов.

**`invalidateMatcherCache()` в `AdminController`.** Сбрасывает и файл, и статическую переменную при изменении или удалении нейрона `type='route'`, чтобы кэш не врал.

**`GALLERY_MAX_IMAGE_SIZE`, `GALLERY_MAX_VIDEO_SIZE`, `GALLERY_MAX_AUDIO_SIZE`.** Лимиты размера файлов в `.env`, чтобы галерея не съела весь диск. Проверяются в `validateUpload()`.

**`TRUSTED_HOSTS`.** Список доверенных хостов в `.env`, чтобы защититься от подделки заголовка `Host`. Если список пуст, пишется предупреждение в лог.

**`SESSION_SECURE`.** Явная настройка `cookie_secure` в `.env`. По умолчанию `false`, чтобы на локальном http сессия работала, а в продакшене включается явно.

**`Vary: Origin`.** Заголовок CORS, который ставится всегда, когда дом играет с CORS, чтобы CDN или прокси не закэшировал ответ с одним `Allow-Origin` и не отдал его другому origin.

**`fail-closed` в `GuardService`.** Если файл попыток нельзя открыть или залочить, возвращается `PHP_INT_MAX`, а не `0`, чтобы защита сработала, а не «прощала». `blockIp()` теперь сбрасывает кэш `guard.blocked_ips`, чтобы бан применялся сразу.

**`getMaxAttempts()`, `getDecaySeconds()`, `getBlockDuration()`.** Публичные методы в `GuardService`, чтобы `AuthController` не лез в `getConfig()` напрямую, а спрашивал у стража то, что он сам знает.

**`sanitizeHtml()` в `spa.js`, `admin.js`, `gallery.html.twig`.** Обёртка над `DOMPurify`, которая санитизирует HTML перед вставкой в `innerHTML`. Это уменьшает поверхность XSS, потому что серверная сборка HTML — хрупкая схема.

**`DOMPurify`.** Подключён в `base.html.twig` перед `spa.js`, чтобы `sanitizeHtml()` работал.

### Изменено

**`Cache` больше не зависит от хрупкой инварианты.** `remember()`, `forget()`, `forgetByPrefix()` и `isEnabled()` используют `isActive()`, который проверяет и `$this->enabled`, и `$this->apcuAvailable`. Это убирает ситуацию, когда `enabled = false` синхронизируется в конструкторе, но не проверяется в методах.

**`Cache::remember()` — один атом вместо двух.** Использует `apcu_fetch($key, $success)` вместо `apcu_exists()` + `apcu_fetch()`, и оборачивает значение в `['v' => $value]`, чтобы и `null`, и `false` кэшировались, и двойного чтения не было.

**`ConfigService::load()` — три уровня кэша.** APCu, файл `var/cache/config.php` и база. Это позволяет дому работать быстро даже без APCu, потому что файл — это `require`, а не `SELECT`.

**`ConfigService` получает `Cache` через конструктор.** Это позволяет `load()` использовать `remember()` и не делать `SELECT` на каждый запрос.

**`Session` не стартует в `dispatch()`.** Сессия запускается лениво, когда она действительно нужна, потому что публичные GET-страницы не нуждаются в ней, и каждый гость не должен получать запись на диске.

**`getMatcher()` принимает `$routes` и `$context` параметрами.** Это убирает двойной парсинг globals и двойной вызов плагинов, потому что маршруты уже собраны в `dispatch()`.

**`loadRoutesFromDatabase()` читает `_roles` и `_csrf` из `data`.** Это позволяет маршрутам из базы объявлять роли и CSRF так же, как маршруты из плагинов, и ядро проверяет их обобщённо.

**`Kernel::dispatch()` проверяет `_roles` через `refreshUserRoles()`.** Роли обновляются из базы, если TTL истёк, потому что доверять устаревшему состоянию — это не проверка, а надежда.

**`Kernel::loadConfig()` логирует ошибку.** Молчаливая потеря конфигурации хуже, чем явная ошибка, потому что CORS пустой, guard-лимиты дефолтные, и никто не узнает почему.

**`Kernel::renderError()` берёт реальный `login` из сессии.** Метаданные ошибки должны быть настоящими, а не «guest» по умолчанию.

**`Kernel::safeErrorMessage()` — точнее regex и обрезка длины.** Regex `#(?:[A-Z]:)?/[^\s]+#` ловит пути, но не трогает URL, а длина обрезается до 500 символов, чтобы в debug не улетал мегабайт.

**`Validator::recordExists()` использует `is_deleted = 0`.** Это тот же критерий, что и в остальном коде, потому что два критерия — это два места для ошибки.

**`HtmlRenderer` кэширует `HTMLPurifier`.** Один очиститель на рендерер, а не новый на каждый `render()`, потому что Purifier тяжёлый. `CSS.CacheDir` убран, потому что такой директивы нет. `HTML.Allowed` и `URI.AllowedSchemes` заданы явно.

**`MonitorController` использует `DebugTrait`.** Убран `private bool $isDebug` и прямое чтение `$_ENV`, потому что источник должен быть один.

**`MonitorController::findLogFile()` кэширует `glob` на минуту.** Имена файлов меняются редко, а просмотр логов может быть частым.

**`MonitorController::tailFile()` читает с конца и не загружает гигабайт в память.** Если файл маленький и цикл не выполнился, читается весь файл.

**`GuardService::checkLoginAttempt()` не вызывает `hit()` до проверки пароля.** Счётчик растёт только после неудачного `password_verify`, чтобы нельзя было забивать лимит чужими логинами.

**`GuardService::getAttempts()` — fail-closed.** Если файл нельзя открыть или залочить, возвращается `PHP_INT_MAX`, а не `0`, чтобы защита сработала.

**`GuardService::hit()` возвращает количество после записи.** Это убирает гонку между `tooManyAttempts()` и `hit()`, потому что решение принимается по факту, а не по предположению.

**`GuardService::cleanupAttempts()` привязан ко времени.** Раз в час, а не на каждый хит, чтобы не сканировать папку `var/guard` на каждую попытку.

**`AuthController::login()` не утекает существование аккаунта.** Сообщение при `auth_method !== 'local'` то же, что и при неверном пароле.

**`ReportSocialHandler` не ставит глобальные `ini_set`.** Лимиты памяти и времени ставятся только на время `process()`, а потом возвращаются обратно.

**`ReportSocialHandler::import()` не использует `DISABLE KEYS`.** На InnoDB это не работает, и вместо этого используется одна транзакция на весь импорт.

**`GalleryController::moveAndCreateFile()` удаляет файл при ошибке.** Если создание нейрона упадёт, файл и миниатюра удаляются, чтобы не оставлять «осиротевших» файлов.

**`GalleryController::validateUpload()` проверяет размер.** Лимиты берутся из `.env`, а не «магические числа».

**`GalleryController` использует `hydrateData()`.** Одно место для `json_decode`, а не пять.

**`SpaController` получает `MenuController` и `PageController` через конструктор.** Это убирает `null` и `$this->container`, потому что контроллер не должен знать о контейнере.

**`SpaController::navigate()` не использует `_csrf => false`.** Для GET это не нужно, потому что `Kernel` проверяет CSRF только для `POST`, `PUT` и `DELETE`.

**`Kernel` не знает о плагинах в middleware.** CSRF и роли проверяются через метаданные маршрута (`_csrf`, `_roles`), а не через префиксы путей.

**`Kernel` собирает маршруты один раз.** Плагины вызываются один раз, а не дважды, потому что `getMatcher()` принимает `$routes` параметром.

**`Cache` на APCu.** Не файловый кэш. В памяти. Быстро. Атомарно. С проверкой `ext-apcu`. Если нет — работает без кэша, но пишет предупреждение в лог.

**`Cache` не кэширует `null` и `false`.** И это правильно, потому что `apcu_fetch` возвращает `false` и при промахе, и при значении `false`.

**`CSRF` — одна точка в `Kernel`.** Без дублей в контроллерах. `loginForm` и `registerForm` всегда генерируют токен, `logout` не убивает токен для следующего входа.

**`controller` — без `str_replace`.** Валидация в `AdminController`, чистые данные в базе.

**`404` — через `ResourceNotFoundException`.** Не через `RuntimeException`, чтобы `Kernel` ловил как 404, а не 500.

**`forgetCache` — при обновлении.** Чтобы правки были видны сразу, а не через час.

**Валидация роутов — при сборке.** Если `controller` или `path` пустой — пропускаем с логом, без 500.

**Лог роутов — честный.** Через `$wasCalled`, а не «routes loaded from cache» при каждом запросе.

**`writeJsonFile()` — атомарно.** Через `tmp` и `rename`.

**`RedirectResponse` вместо `new Response('', 302, [...])`.** Везде, потому что чище и правильнее.

### Удалено

**`CacheInterface::class` из `config/container.php`.** Определение было без `use` и без использования, и это мина, которая сработала бы при резолве. `CacheInterface` остаётся в `src/Cache.php`, потому что `Cache` его реализует, а `NullCache` удалён, потому что не используется.

**`buildRoutes()` и `isRoutesCacheStale()` из `Kernel`.** Они больше не нужны, потому что `getMatcher()` принимает `$routes` параметром и не проверяет устаревание на каждом запросе. Кэш инвалидируется явно из `AdminController`.

**`findByChild()` и `countByParent()` из `SynapseRepository`.** Мёртвый код, который не вызывался нигде.

**`findAllByKeyAndLang()`, `findByGroup()`, `search()` из `TextRepository`.** Мёртвый код. `search()` можно вернуть, когда появится поиск на сайте.

**`Cache::clear()`.** Не вызывался нигде, а метод, который чистит весь APCu, — это мина.

**`ConfigService::has()` и `all()`.** Не вызывались нигде.

**Локальные `isDebug()` в пяти классах.** Заменены на `DebugTrait`.

**`$this->config` из `Kernel`.** Дублирование состояния, которое мешало.

**`fetchConfigFromDatabase()` из `Kernel`.** `ConfigService::load()` уже делает это.

**`getContainer()`.** Мёртвый код и дыра в инкапсуляции.

**`version` из `composer.json`.** Одна точка правды — `.env`.

**`HTTP_RESPONSE_CODE_FAILURE` из логов.** Это не наша ошибка, а ошибка внешнего скрипта, и она ушла, когда мы исправили `originalFetch`.

### Исправлено

**`originalFetch is not defined` в `spa.js`.** Два IIFE переопределяли `window.fetch`, и `originalFetch` был объявлен в одном, а использовался в другом. Теперь `originalFetch` объявлен в том же замыкании, что и `window.fetch`.

**`Class "TrinityUrlMatcher" not found`.** `CompiledUrlMatcherDumper::dump()` возвращает код класса, а не массив, и `require` возвращал `1`. Теперь используется `getCompiledRoutes()` и `var_export`, чтобы `require` возвращал массив.

**`Unknown column 'status' in 'where clause'`.** Генерируемый столбец `status` был добавлен в `database.sql`, но не в базу. После `ALTER TABLE` дашборд заработал.

**`Undefined property: MonitorController::$isDebug`.** Заменено на `$this->isDebug()` везде, где было `$this->isDebug`.

**`refreshUserRoles(): Argument #1 ($session) must be of type Session, null given`.** `$session` теперь получается из контейнера в `dispatch()`, а не полагается на то, что он уже есть.

**`Class Jan\Trinity\Core\Cache contains 1 abstract method`.** `Cache` не реализовывал `forgetByPrefix()`. Добавлен.

**`Duplicate column name 'status'`.** `ALTER TABLE` применялся к базе, где столбец уже был. Ничего не сломано, но и не добавлено.

**`net::ERR_HTTP_RESPONSE_CODE_FAILURE 500` на `/admin`.** Причина была в `MonitorController`, который падал при загрузке из-за отсутствующего `use` для `DebugTrait` и `$this->isDebug` без скобок.

**`Access-Control-Allow-*` ставились дважды.** Вынесены в константы `CORS_ALLOW_METHODS`, `CORS_ALLOW_HEADERS`, `CORS_MAX_AGE`, и используются в `respond()` и `handleOptions()`.

**`Vary: Origin` отсутствовал.** Добавлен в `applyCorsHeaders()` и `handleOptions()`, чтобы CDN не отдал чужой `Allow-Origin`.

**`SESSION_SECURE` по умолчанию `true`.** Изменён на `false`, чтобы на локальном http сессия работала, и добавлено предупреждение, если сайт на https, а `SESSION_SECURE` выключен.

**`DB_USER=root` и пустой пароль по умолчанию.** Убраны, и теперь отсутствие `DB_*` в `.env` — фатальная ошибка, а не «молчаливый root».

**`config/container.php` — `CacheInterface::class` без `use`.** Удалён, потому что не использовался и был ядовитым.

**`Cache::remember()` — TOCTOU.** `apcu_exists()` + `apcu_fetch()` заменены на `apcu_fetch($key, $success)`, и значение оборачивается в `['v' => $value]`, чтобы `null` и `false` кэшировались.

**`GuardService` — fail-open.** `getAttempts()` возвращает `PHP_INT_MAX` при проблеме с файлом или локом, а не `0`.

**`GuardService::cleanupAttempts()` — self-DoS.** Уборка привязана ко времени, а не к вероятности, и не сканирует папку на каждый хит.

**`GuardService::blockIp()` не сбрасывал кэш.** Теперь сбрасывает `guard.blocked_ips`, чтобы бан применялся сразу.

**`AuthController::login()` — user-enumeration.** Сообщение при `auth_method !== 'local'` то же, что и при неверном пароле.

**`ReportSocialHandler` — глобальные `ini_set`.** Перенесены в `process()` и возвращаются обратно в `finally`.

**`GalleryController` — осиротевшие файлы.** `moveAndCreateFile()` удаляет файл и миниатюру при ошибке создания нейрона.

**`GalleryController` — нет лимита размера.** Добавлены `GALLERY_MAX_IMAGE_SIZE`, `GALLERY_MAX_VIDEO_SIZE`, `GALLERY_MAX_AUDIO_SIZE`.

**`HtmlRenderer` — HTMLPurifier без кэша.** Добавлены `Cache.DefinitionImpl` и `Cache.SerializerPath`, убран несуществующий `CSS.CacheDir`, заданы `HTML.Allowed` и `URI.AllowedSchemes`.

**`Kernel::loadConfig()` — пустой catch.** Теперь логирует ошибку, потому что молчаливая потеря конфигурации хуже явной.

**`Kernel::renderError()` — «guest» жёстко.** Теперь берёт реальный `login` из сессии.

**`Kernel::safeErrorMessage()` — regex съедал URL.** Заменён на `#(?:[A-Z]:)?/[^\s]+#`, добавлена обрезка до 500 символов.

**`Validator::recordExists()` — `deleted_at IS NULL`.** Заменено на `is_deleted = 0`, потому что это тот же критерий, что и в остальном коде.

**`Session` стартовала в `dispatch()`.** Теперь стартует лениво, когда она действительно нужна.

**`getMatcher()` — двойной парсинг globals.** Теперь принимает `$routes` и `$context` параметрами.

**`loadRoutesFromDatabase()` — не читал `_roles` и `_csrf`.** Теперь читает и кладёт в `defaults` маршрута.

**`Kernel::dispatch()` — роли из сессии без обновления.** Теперь вызывает `refreshUserRoles()`, чтобы роли были актуальными.

**`applyCorsHeaders()` — `Access-Control-Allow-Credentials` при `*`.** Теперь ставится только для точных origin из списка.

**`index.php` — `setTrustedProxies` с дефолтом `127.0.0.1`.** Теперь не включается, если `TRUSTED_PROXIES` пуст, и добавлен `setTrustedHosts`.

**`spa.js` — `window.csrfToken`.** Убран, токен запрашивается через `/api/csrf-token` и хранится в памяти только на время сессии.

**`spa.js` — 48 мест с `innerHTML`.** Добавлена `sanitizeHtml()` и `DOMPurify`, чтобы HTML санитизировался перед вставкой.

**`Kernel` — маршруты компилировались на каждом запросе.** Теперь компилируются один раз и кэшируются в памяти и в файле, а инвалидируются явно из `AdminController`.

**`Kernel` — `loadRoutesFromDatabase()` логировал «routes loaded from db» дважды.** Внутренний лог убран, остался один честный.

**`Kernel` — `Access-Control-Allow-*` ставились дважды.** Вынесены в константы.

**`Kernel` — `resolveArguments()` не приводил скаляры.** Теперь приводит `int`, `float`, `bool`, `string` по типу, и кэширует спецификацию параметров.

**`Kernel` — `refreshUserRoles()` получал `null`.** Теперь `$session` получается из контейнера в `dispatch()`.

**`MonitorController` — `$this->isDebug` без скобок.** Заменено на `$this->isDebug()`.

**`MonitorController` — `DebugTrait` не был импортирован.** Добавлен `use Jan\Trinity\Core\Trait\DebugTrait;`.

**`MonitorController` — `glob` на каждый просмотр логов.** Кэшируется на минуту.

**`MonitorController` — `tailFile()` мог загрузить гигабайт.** Читает с конца и не загружает весь файл, а если файл маленький — читает весь.

**`GuardController` — CSRF в `blockIpApi` и `unblockIpApi`.** Оставлен, потому что это изменяющие методы.

**`GuardController` — `filter_var` для IP.** Оставлен, потому что «anything» — это не IP.

**`GuardService` — `getConfig()` был приватным.** Добавлены публичные `getMaxAttempts()`, `getDecaySeconds()`, `getBlockDuration()`.

**`AuthController` — `$this->guard->getConfig()` ругался.** Заменено на публичные методы.

**`ExcelImportService` — `DISABLE KEYS` на InnoDB.** Убран, заменён на одну транзакцию.

**`ExcelImportService` — `buildRoutes()` дублировал сборку.** Убран, потому что `getMatcher()` принимает `$routes` параметром.

**`Kernel` — `getMatcher()` создавал `RequestContext` из `Request::createFromGlobals()`.** Теперь принимает `$context` параметром.

**`Kernel` — `buildRoutes()` и `isRoutesCacheStale()`.** Удалены, потому что не нужны.

**`SynapseRepository` — `findByChild()` и `countByParent()`.** Удалены, потому что не вызывались.

**`TextRepository` — `findAllByKeyAndLang()`, `findByGroup()`, `search()`.** Удалены, потому что не вызывались. `search()` можно вернуть, когда появится поиск.

**`Cache::clear()`.** Удалён, потому что не вызывался и был опасен.

**`ConfigService::has()` и `all()`.** Удалены, потому что не вызывались.

**`DebugTrait` — дублирование `isDebug()` в пяти классах.** Заменено на trait.

**`Kernel` — `$this->config`.** Удалён, потому что дублировал `ConfigService`.

**`Kernel` — `fetchConfigFromDatabase()`.** Удалён, потому что `ConfigService::load()` уже делает это.

**`Kernel` — `getContainer()`.** Удалён, потому что мёртвый код и дыра в инкапсуляции.

**`composer.json` — `version`.** Удалён, потому что одна точка правды — `.env`.

**`Kernel` — `RedirectResponse` вместо `new Response('', 302, [...])`.** Заменено везде.

**`database.sql` — ручные ID.** Главы `101–121`, без пересечений, без `LAST_INSERT_ID()`.

**`NeuronRepository::logAdminAction` — через `LoggerInterface`.** Не `file_put_contents`, а Monolog с ротацией.

**`NeuronRepository` — `logger.admin`.** Не `logger.app`, чтобы секция «Действия админа» работала.

**`MonitorController` — реализует `ErrorHandlerInterface`.** Чтобы `Kernel` не знал о конкретном плагине.

**`Cache` на APCu.** Не файловый кэш, а APCu, с проверкой `ext-apcu` и предупреждением в лог, если APCu нет.

**`Cache` — `isActive()`.** Проверяет и `$this->enabled`, и `$this->apcuAvailable`, чтобы не было хрупкой инварианты.

**`Cache::remember()` — обёртка `['v' => $value]`.** Чтобы `null` и `false` кэшировались, и двойного чтения не было.

**`CSRF` — только в `Kernel`.** Одна точка, без дублей. `loginForm` и `registerForm` всегда генерируют токен, `logout` не убивает токен для следующего входа.

**`controller` — без `str_replace`.** Валидация в `AdminController`, чистые данные в базе.

**`MimeController` — 404 через `ResourceNotFoundException`.** Не через `RuntimeException`, чтобы `Kernel` ловил как 404.

**`MimeController` — `forgetCache()`.** Сбрасывает кэш по префиксу `mime_{id}_`, чтобы правки были видны сразу.

**Роуты — валидация при сборке.** Пустой `controller` или `path` пропускается с логом, без 500.

**Лог роутов — честный.** Через `$wasCalled`, а не «routes loaded from cache».

**`writeJsonFile()` — атомарно.** Через `tmp` и `rename`.

**`Kernel` — CORS-заголовки в константах.** `CORS_ALLOW_METHODS`, `CORS_ALLOW_HEADERS`, `CORS_MAX_AGE`.

**`Kernel` — `Vary: Origin`.** Добавлен в `applyCorsHeaders()` и `handleOptions()`.

**`Kernel` — `SESSION_SECURE` по умолчанию `false`.** Чтобы локальный http работал, и предупреждение, если сайт на https, а `SESSION_SECURE` выключен.

**`Kernel` — `DB_USER=root` и пустой пароль.** Убраны, и отсутствие `DB_*` — фатальная ошибка.

**`config/container.php` — `CacheInterface::class` без `use`.** Удалён, потому что не использовался и был ядовитым.

**`Cache` — `isActive()`.** Проверяет оба поля, и `remember()`, `forget()`, `forgetByPrefix()`, `isEnabled()` используют его.

**`Kernel` — предупреждение, если APCu нет.** Чтобы было видно, что кэш не работает, а не «тихая деградация».

---
