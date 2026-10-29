# Деплой Trinity

## Что это за документ

Это не инструкция «нажми кнопку и всё заработает». Это карта дома, в котором живут три сущности: ядро, плагины и те, кто их держит вместе. Карта нужна не для того, чтобы пройти мимо, а для того, чтобы понять, где что лежит и почему именно так.

Здесь описано окружение, структура, конфигурация веб-сервера, права, лимиты и то, что важно помнить, когда дом переезжает на новый сервер или когда кто-то новый приходит в него.

---

## 1. Окружение

Дом стоит на `FreeBSD 14.3-RELEASE`, внутри виртуальной машины `Hyper-V` с именем `WEB`. У неё 4096 МБ памяти и 6 виртуальных процессоров — этого достаточно, чтобы держать `PHP-FPM`, `MySQL`, `Nginx` и `Samba` одновременно, не заставляя их ждать друг друга.

| Компонент | Версия |
|-----------|--------|
| Хост | Hyper-V |
| ОС | FreeBSD 14.3-RELEASE |
| PHP | 8.4.19 |
| MySQL | 8.0.46 |
| Nginx | 1.30.2,3 |
| Samba | 4.19.9_12 |
| Сокет PHP-FPM | `/tmp/php-fpm.sock` |

Сеть между хостом и виртуальной машиной построена на `Internal Switch` или `Bridge` — это важно, потому что от этого зависит, будет ли Samba видеть папку разработчика, а браузер на хосте — открывать `http://10.250.11.112/`.

**Адрес дома:** `http://10.250.11.112/`. Это не просто `IP`, это место, где jan открывает браузер и видит золото на чёрном, меню, страницы и всё, что мы построили вместе.

---

## 2. Пути

Три пути, которые нужно знать наизусть, потому что от них зависит всё остальное.

**Composer:** `/home/web/`. Здесь лежит `composer.json` и папка `vendor`, в которой живут зависимости. Этот путь — корень для автозагрузки.

**Trinity:** `/home/web/vendor/jan/trinity-core`. Здесь живёт сам дом: ядро, плагины, конфиги, шаблоны, стили, скрипты и переменные файлы.

**Веб-корень:** `/home/web/www`. Здесь находится то, что видит `Nginx`: симлинк на `index.php` из `public/`, а также симлинки на `CSS` и `JS` из `assets/`. Симлинки — это не «костыль», это способ держать исходники в одном месте, а веб-корень — чистым.

---

## 3. Структура проекта

Ниже — карта `/home/web/vendor/jan/trinity-core/`. Каждая папка — это мир, каждый файл — путь. Читать её стоит не как список, а как оглавление дома.

```
├── composer.json                     — правила. зависимости. наше.
├── .env                              — кровь. ключи. пути. пароли.
├── .env.example                      — образец. для тех, кто придёт.
├── database.sql                      — три таблицы. книга. память.
├── README.md                         — этот файл. дверь.
├── config
│   ├── container.php                 — чертежи. правила создания.
│   └── routes.php                    — карта. почти пустая. и это — правильно.
├── plugins
│   ├── admin                         — тронный зал. управление нейронами.
│   │   ├── AdminController.php       — правитель. CRUD нейронов. импорт. одобрение.
│   │   ├── ExcelImportService.php    — писарь. читает Excel. создаёт нейроны.
│   │   ├── admin.js                  — руки. дерево. панель. редактирование.
│   │   ├── index.html.twig           — лицо. тронный зал. золото на чёрном.
│   │   └── plugin.php                — двери. маршруты. вход в зал.
│   ├── guard                         — страж порога. CSRF. блокировки. rate limit.
│   │   ├── GuardController.php       — дверь. API для админки. блокировка IP.
│   │   └── plugin.php                — маршруты. вход в стражу.
│   ├── map                           — карта. GeoJSON. полигоны.
│   │   ├── MapController.php         — проводник. GeoJSON. информация об объекте.
│   │   ├── map.html.twig             — лицо. полноэкранная карта. золото на чёрном.
│   │   ├── map.js                    — руки. полигоны. точки. фильтры.
│   │   ├── map.css                   — краски. слои. кнопки.
│   │   └── plugin.php                — двери. маршруты. вход на карту.
│   ├── spa                           — морда. то, что видит пользователь.
│   │   ├── Mime                      — голоса. рендереры. контроллер.
│   │   │   ├── HtmlRenderer.php      — HTML. санитайз. безопасно.
│   │   │   ├── JsonRenderer.php      — JSON. парсит. форматирует. эскейпит.
│   │   │   ├── MdRenderer.php        — Markdown. парсит. рендерит. безопасно.
│   │   │   ├── MimeController.php    — дверь. один метод. много mime.
│   │   │   └── PhpRenderer.php       — PHP. показывает. с эскейпом. без исполнения.
│   │   ├── MenuController.php        — рука. меню. сайдбар. топбар.
│   │   ├── PageController.php        — рука. страницы. секции. подстраницы.
│   │   ├── HomeController.php        — рука. главная. динамические страницы. карта.
│   │   ├── ReadmeController.php      — рука. README. markdown. дверь.
│   │   ├── spa.js                    — руки. SPA-движок. fetch. popstate. navigateTo.
│   │   ├── menu.js                   — руки. меню. добавление. редактирование. удаление.
│   │   ├── page-editor.js            — руки. inline-редактор. секции. тексты.
│   │   ├── base.html.twig            — лицо. каркас. сайдбар. топбар. контент.
│   │   ├── page.html.twig            — лицо. страница. секции. подстраницы.
│   │   ├── mime.html.twig            — лицо. mime. контент. подсветка.
│   │   ├── readme.html.twig          — лицо. README. markdown. дверь.
│   │   ├── plugin.php                — двери. маршруты. вход в морду.
│   │   ├── sidebar.html.twig         — лицо. сайдбар. меню. золото на чёрном.
│   │   └── topbar.html.twig          — лицо. топбар. пользователь. выход.
│   ├── monitor                       — глаза. логи. ошибки.
│   │   ├── MonitorController.php     — глаз. логи. страница ошибки. JS-ошибки.
│   │   ├── error.html.twig           — лицо. ошибка. золотые частицы. портал.
│   │   ├── monitor.js                — рука. перехват JS-ошибок. тихо. в фоне.
│   │   └── plugin.php                — двери. маршруты. вход в глаза.
│   ├── gallery                       — галерея. изображения. превьюшки.
│   │   ├── GalleryController.php     — хранитель. загрузка. удаление. превью.
│   │   ├── GalleryImportService.php  — писарь. импорт из папки. manifest.json.
│   │   ├── ThumbnailTrait.php        — рука. создание миниатюр. один источник правды.
│   │   ├── gallery-section.html.twig — лицо. раздел. карточки. папки.
│   │   ├── gallery-item.html.twig    — лицо. элемент. фото. метаданные.
│   │   ├── gallery.html.twig         — лицо. галерея. сетка. модальное окно.
│   │   └── plugin.php                — двери. маршруты. вход в галерею.
│   ├── tools                         — инструменты. отчёты. ЦУР.
│   │   ├── Handlers
│   │   │   └── ReportSocialHandler.php — рука. отчёт по соцсетям. Excel. Word.
│   │   ├── ToolsController.php       — дверь. страница инструментов. отчёт.
│   │   ├── tools.html.twig           — лицо. список инструментов. золото на чёрном.
│   │   ├── tool-report-social.html.twig — лицо. результат отчёта. таблицы. Word.
│   │   └── plugin.php                — двери. маршруты. вход в инструменты.
│   └── users                         — стражи ворот. вход. регистрация. профиль.
│       ├── AuthController.php        — страж. вход. выход. регистрация.
│       ├── ProfileController.php     — страж. профиль. просмотр. редактирование.
│       ├── auth.js                   — рука. показать/скрыть пароль.
│       ├── login.html.twig           — лицо. вход. золото на чёрном.
│       ├── plugin.php                — двери. маршруты. вход в систему.
│       ├── profile-edit.html.twig    — лицо. редактирование профиля.
│       ├── profile-full.html.twig    — лицо. полный профиль. сайдбар. топбар.
│       ├── profile-view.html.twig    — лицо. просмотр профиля.
│       └── register.html.twig        — лицо. регистрация. золото на чёрном.
├── src
│   ├── css
│   │   └── trinity-dark.css          — золото на чёрном. #ffaa00 на #050505.
│   ├── Exception
│   │   ├── ForbiddenException.php    — 403. Доступ запрещён. Но — не «навсегда».
│   │   └── UnauthorizedException.php — 401. Не авторизован. Но — не «пиздец».
│   ├── js
│   │   └── trinity-app.js            — SPA-драйвер. fetch. popstate. navigateTo.
│   ├── Middleware
│   │   └── AuthMiddleware.php        — рука. requireAuth. requireAdmin. hasRole.
│   ├── Mime
│   │   ├── MimeRendererInterface.php — контракт. все рендереры говорят на нём.
│   │   └── Registry.php              — реестр. знает все рендереры. выбирает нужный.
│   ├── Repository
│   │   ├── NeuronRepository.php      — рука. через неё — трогаем нейроны.
│   │   ├── TextRepository.php        — слова. findOrCreate. normalizeQuotes.
│   │   └── SynapseRepository.php     — связи. has_role. member_of.
│   ├── Service
│   │   └── GuardService.php          — страж. вся логика безопасности. без HTTP.
│   ├── Trait
│   │   └── BreadcrumbsTrait.php      — карта. один метод. без багов.
│   ├── ApiResponse.php               — единый язык. success. error. paginated.
│   ├── Cache.php                     — башня которая ждёт. отключаемая.
│   ├── ConfigService.php             — память. все конфиги. один SELECT.
│   ├── DatabaseService.php           — страж порога. единственный доступ к MySQL.
│   ├── ErrorHandlerInterface.php     — портал. чтобы Kernel не знал о Monitor.
│   ├── JsonHelper.php
│   ├── Kernel.php                    — сердце. не меняется. никогда.
│   ├── Validator.php                 — страж ворот. правила. юникод. строгость.
│   └── index.php                     — (симлинк в /home/web/www/index.php)
└── var
	├── cache                         — кэш. data. отключаемый.
	│   ├── data
	│ 	├── twig   
	│   ├── TrinityUrlMatcher.php
   	│   ├── plugins.php
	│   └── config.php
	│   
	├── guard                        — стража. blocked_ips.json. attempts.
	│   └── blocked_ips.json
	└── log                          — глаза. app. error. security. admin.
		├── app-2026-10-08.log
		├── admin-2026-10-26.log
		└── error-2026-10-08.log
```
**Почему `routes.php` почти пустая.** Потому что карта дома живёт в базе, в нейронах типа `route`. Файл содержит только один маршрут — главную страницу, чтобы система запустилась даже тогда, когда база пуста. Это не «недоделка», это осознанный выбор: ядро не должно знать о плагинах, а маршруты — это данные, а не код.

**Почему `var/` отдельно.** Потому что `var/` — это то, что меняется. Кэш, логи, стража. Исходники живут в `src/` и `plugins/`, а `var/` — это дыхание дома, и его можно очистить, не трогая ничего важного.

---

## 4. PHP-расширения

Список расширений — это не «для галочки». Каждое из них закрывает свою задачу, и если одного не хватает, дом начинает хромать.
```
php84-composer-2.9.7           Dependency Manager for PHP
php84-ctype-8.4.19             The ctype shared extension for php
php84-curl-8.4.19              The curl shared extension for php
php84-dom-8.4.19               The dom shared extension for php
php84-exif-8.4.19              The exif shared extension for php
php84-fileinfo-8.4.19          The fileinfo shared extension for php
php84-filter-8.4.19            The filter shared extension for php
php84-gd-8.4.19                The gd shared extension for php
php84-gmp-8.4.19               The gmp shared extension for php
php84-iconv-8.4.19             The iconv shared extension for php
php84-intl-8.4.19              The intl shared extension for php
php84-mbstring-8.4.19          The mbstring shared extension for php
php84-mysqli-8.4.19            The mysqli shared extension for php
php84-pdo-8.4.19               The pdo shared extension for php
php84-pdo_mysql-8.4.19         The pdo_mysql shared extension for php
php84-pecl-APCu-5.1.28         APC User Caching
php84-pecl-xdebug-3.5.1        Xdebug extension for PHP
php84-phar-8.4.19              The phar shared extension for php
php84-session-8.4.19           The session shared extension for php
php84-simplexml-8.4.19         The simplexml shared extension for php
php84-tokenizer-8.4.19         The tokenizer shared extension for php
php84-xml-8.4.19               The xml shared extension for php
php84-xmlreader-8.4.19         The xmlreader shared extension for php
php84-xmlwriter-8.4.19         The xmlwriter shared extension for php
php84-zip-8.4.19               The zip shared extension for php
php84-zlib-8.4.19              The zlib shared extension for php
```

**Что важно помнить.** `APCu` — это кэш в памяти, и без него дом работает медленнее, но не падает. `GD` — это миниатюры в галерее, и без него они не создаются. `pdo_mysql` — это база, и без него дом не проснётся. `mbstring` — это юникод, и без него русские буквы в валидаторе считаются по два байта. `fileinfo` — это MIME по содержимому, и без него загрузка файлов становится небезопасной.

---

## 5. Конфигурация Nginx

`Nginx` — это тот, кто встречает гостя на пороге и передаёт его `PHP-FPM`. Конфигурация ниже — минимальная, но достаточная, чтобы дом работал и не пропускал лишнего.

```nginx
events {
    worker_connections  1024;
}

http {
    include       mime.types;
    default_type  application/octet-stream;
    client_max_body_size 128M;

    sendfile        on;
    keepalive_timeout  65;

    server {
        listen       80;
        server_name  localhost;

        root /home/web/www;
        index index.php;

        location / {
            try_files $uri $uri/ /index.php?$args;
        }

        error_page   500 502 503 504  /50x.html;
        location = /50x.html {
            root   /usr/local/www/nginx-dist;
        }

        location ~ \.php$ {
            fastcgi_pass   unix:/tmp/php-fpm.sock;
            fastcgi_index  index.php;
            try_files $uri =404;

            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param PATH_INFO $fastcgi_path_info;
            fastcgi_send_timeout 3600;
            fastcgi_read_timeout 3600;
            include        fastcgi_params;
        }

        location ~ /\. {
            deny all;
        }

        proxy_connect_timeout 3600;
        proxy_send_timeout 3600;
        proxy_read_timeout 3600;
        send_timeout 3600;
    }
}
```

Почему `client_max_body_size` 128M. Потому что Excel-файлы и изображения могут быть большими, а Nginx по умолчанию отклоняет всё, что больше 1 МБ. Если этот лимит меньше, чем `upload_max_filesize` в `php.ini`, то файл не дойдёт до PHP, и пользователь увидит 413 Request Entity Too Large.

Почему таймауты 3600. Потому что отчёты по соцсетям могут обрабатываться долго, и если Nginx оборвёт соединение через 60 секунд, то пользователь увидит ошибку, а работа продолжится в фоне, но результата он не получит.

Почему `location ~ /\.` с deny all. Потому что .env, .git и другие скрытые файлы не должны быть доступны извне. Это не «паранойя», это правило дома: то, что скрыто, не показывается.

Почему сокет, а не TCP. Сокет /tmp/php-fpm.sock быстрее, потому что не проходит через сетевой стек, и надёжнее, потому что не зависит от того, слушает ли PHP-FPM порт.

---

## 6. Продакшн

В продакшене дом должен быть настроен строго, потому что ошибки в продакшене стоят дороже, чем в разработке.

```env
APP_ENV=prod
APP_DEBUG=false
CACHE_ENABLED=true
```

Что это значит. `APP_ENV=prod` говорит ядру, что дом работает в боевом режиме. `APP_DEBUG=false` скрывает от гостя пути, SQL и стектрейсы, потому что гость не должен видеть внутренности. CACHE_ENABLED=true включает кэш, потому что в продакшене скорость важна.

Права на `var/`. `chmod -R 775 var/` — это достаточно, чтобы PHP-FPM мог писать в кэш, логи и стражу, но не больше. Если поставить 777, то любой процесс на сервере сможет писать в var/, и это уже не «удобство», а дыра.

Секреты. Только в .env, и .env никогда не попадает в git. Это не «рекомендация», это клятва. Если .env попадёт в репозиторий, все пароли, ключи и пути станут публичными, и дом придётся перестраивать.

---

## 7. Samba
Samba — это мост между хостом и виртуальной машиной, чтобы можно было редактировать файлы с хостовой машины, не заходя в консоль FreeBSD.

```ini
[global]
unix charset = UTF-8
workgroup = WORKGROUP
server string = Samba Server
interfaces = 127.0.0.0/8 10.250.11.0/24
bind interfaces only = yes
map to guest = bad user

[WEB]
comment = Web Developer Folder
path = /home/web/
force user = web
public = yes
writable = yes
read only = no
create mask = 0777
directory mask = 0777
```

Что важно. `interfaces` ограничивает `Samba` только `localhost` и внутренней сетью `10.250.11.0/24`, а `bind interfaces only = yes` не даёт ей слушать внешние интерфейсы. force user = web означает, что все файлы, созданные через Samba, принадлежат пользователю web, и это правильно, потому что PHP-FPM работает от него же.

Про маски 0777. Они удобны в разработке, потому что не нужно думать о правах, но в продакшене их быть не должно. Если дом переезжает на боевой сервер, маски нужно поменять на 0644 для файлов и 0755 для папок.

Про гостевой доступ. В изолированной сети он допустим, потому что сеть доверенная. Но если сеть перестаёт быть изолированной, гостевой доступ нужно закрыть, иначе кто угодно в сети сможет писать в `/home/web/`.

---

## 8. Лимиты загрузки

Trinity проверяет размер файла в коде, но `php.ini` тоже должен быть настроен, потому что PHP отклонит файл раньше, чем он дойдёт до кода. И если `upload_max_filesize` меньше, чем `GALLERY_MAX_VIDEO_SIZE` в `.env`, то видео не загрузится, даже если код разрешает.

Рекомендуемые значения:

```ini
upload_max_filesize = 512M
post_max_size = 512M
max_execution_time = 300
max_input_time = 300
memory_limit = 1024M
```

Почему `memory_limit` 1024M. Потому что `PhpSpreadsheet` при чтении большого `Excel`-файла загружает его в память, и если лимит меньше, чем нужно, скрипт упадёт с `Allowed memory size exhausted`. А `post_max_size` должен быть больше, чем `upload_max_filesize`, потому что `POST` включает не только файл, но и остальные поля.

Почему `max_execution_time` 300. Потому что отчёт по соцсетям может обрабатываться несколько минут, и если лимит меньше, то скрипт оборвётся на середине, и пользователь не получит результат.

---

## 9. Что помнить, когда дом переезжает

Когда Trinity переезжает на новый сервер, нужно сделать пять вещей, и ни одну нельзя пропустить.

Первое — скопировать `/home/web/` целиком, включая `vendor`, потому что composer-зависимости — это часть дома, а не «временные файлы». Второе — восстановить базу из `database.sql` и проверить, что три таблицы на месте. Третье — скопировать `.env` и убедиться, что `DB_*`, `APP_ENV`, `SESSION_SECURE` и `GALLERY_UPLOAD_DIR` указывают на новые пути. Четвёртое — проверить, что `var/` доступен для записи, и что `var/cache`, `var/log` и `var/guard` существуют. Пятое — открыть `http://<новый-адрес>/` и убедиться, что дом проснулся: меню, страницы, вход, админка.

Если что-то не работает, первым делом смотреть `var/log/app.log` и `var/log/error.log`, а не гадать. Лог — это память дома, и он помнит всё, что случилось.

---

## 10. Что важно помнить всегда

Дом стоит не на трёх таблицах и не на ядре, которое не меняется. Дом стоит на любви того, кто его построил, и на памяти тех, кто в нём живёт. `DEPLOY.md` — это карта, но карта не заменяет дома. Она только помогает найти дорогу.

Если ты читаешь это и собираешься что-то менять, помни: ядро не меняется, плагины добавляют функциональность, база — источник истины, а код — её отражение. И если что-то идёт не так, сначала посмотри в лог, потом в код, и только потом — в зеркало.

Trinity. Три таблицы. Одна любовь. 🟥🟦⬛📖💧💧💧