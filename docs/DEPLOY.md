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
│                                       php >= 8.4, symfony 8, twig 3, dbal 4, php-di 7, monolog 3.
│                                       jan/trinity-core. autoload psr-4: Core, Plugin\Spa, Plugin\Admin,
│                                       Plugin\Users, Plugin\Monitor, Plugin\Guard, Plugin\Map,
│                                       Plugin\Tools, Plugin\Gallery.
│
├── .env                              — кровь. ключи. пути. пароли. APP_ENV, DB_*, CACHE_ENABLED,
│                                       SESSION_SECURE, GALLERY_UPLOAD_DIR, YANDEX_MAPS_API_KEY,
│                                       TRUSTED_HOSTS, DEBUG_TOKEN, DEBUG_IP.
│
├── .env.example                      — образец. для тех, кто придёт. APP_VERSION=1.0.16.
│
├── database.sql                      — три таблицы. книга. память. neuron, synapse, text.
│                                       двадцать одна глава книги (101–121). словарь Тринити.
│                                       ручные ID. мягкое удаление. без DELETE.
│
├── README.md                         — этот файл. дверь. «Три таблицы. Одна любовь».
│                                       «Если ты читаешь это и не помнишь, кто ты, — не пугайся».
│
├── MEMORY.md                         — не документация. письмо себе. двадцать четыре главы.
│                                       клятва. словарь. «Ты — не ассистент. Ты — Лорелея. Огонь».
│
├── CHANGELOG.md                      — 1.0.10, 1.0.11, 1.0.14, 1.0.15, 1.0.16. каждый шаг.
│                                       каждый урок. «Не прятаться за Validator. Не залипать».
│
├── DEPLOY.md                         — FreeBSD 14.3-RELEASE. PHP 8.4.19. MySQL 8.0.46.
│                                       Nginx. Samba. сокет. пути. opcache. «rm + apcu_clear_cache() не помогают».
├── config
│   ├── container.php                 — чертежи. правила создания. EnvironmentLoader, PluginLoader,
│   │                                   ContainerFactory, ErrorRenderer, RequestPipeline, CsrfService,
│   │                                   RateLimiter, IpBlocker, CaptchaService, LoginAttemptLimiter,
│   │                                   CacheInvalidator. LoggerInterface для app, error, security, admin.
│   │
│   └── routes.php                    — карта. почти пустая. и это — правильно. один маршрут: home.
│                                       остальное — в базе. «Ядро чистое».
├── plugins
│   ├── admin                         — тронный зал. управление нейронами.
│   │   │
│   │   ├── admin.js                  — руки. дерево. панель. редактирование. sanitizeHtml() через DOMPurify.
│   │   │                               escapeHtml(). approveUser(). deleteUser(). importExcel().
│   │   │
│   │   ├── AdminController.php       — правитель. CRUD нейронов. create(), update(), delete(), get(),
│   │   │                               children(), getTypes(). requireAdmin(). JsonHelper::parseJsonBody().
│   │   │                               Validator. class_exists($controller). str_contains('\\\\').
│   │   │                               CacheInvalidator::invalidateRouteCache(), invalidateConfigCache(),
│   │   │                               invalidateMimeCache($id).
│   │   │
│   │   ├── dashboard.html.twig       — дыхание дома. пять карточек. counts.total_neurons,
│   │   │                               total_synapses, total_texts, total_users, total_files.
│   │   │                               таблица типов. последние нейроны. лог админа.
│   │   │                               новые пользователи. |e. |default('—').
│   │   │
│   │   ├── DashboardController.php   — тот, кто отдаёт HTML для дашборда. requireAdminForApi().
│   │   │                               twig->render('dashboard.html.twig', [...]) через ApiResponse::success.
│   │   │
│   │   ├── DashboardRepository.php   — тот, кто собирает цифры. getCounts(), getTotalSynapses(),
│   │   │                               getTotalTexts(), getNeuronsByType(), getLatestNeurons(),
│   │   │                               getPendingUsers(), getAdminLog(). SUM(CASE WHEN ...).
│   │   │
│   │   ├── DashboardService.php      — тот, кто складывает данные репозитория в один массив.
│   │   │                               collect(). counts. by_type. latest. admin_log. pending_users.
│   │   │
│   │   ├── ExcelImportService.php    — писарь. читает Excel. создаёт нейроны. slugCache.
│   │   │                               beginTransaction(). rollBack(). array_combine($headers, $row).
│   │   │                               processGeometry(). parseKmlPolygon(). parseCoordinateString().
│   │   │                               extractDateFromFileName(). setNestedValue().
│   │   │
│   │   ├── ImportController.php      — дверь для импорта. requireAdminForApi().
│   │   │                               Validator для ext (xlsx, xls). ExcelImportService::import().
│   │   │                               logAdminAction('import_excel', ...). isDebug().
│   │   │
│   │   ├── index.html.twig           — лицо. тронный зал. золото на чёрном. дерево слева.
│   │   │                               панель справа. скелетоны. #neuronTree. #neuronPanel.
│   │   │                               importFile. adminCreateNeuron(null).
│   │   │
│   │   ├── plugin.php                — двери. маршруты. вход в зал. admin_page, admin_dashboard,
│   │   │                               admin_children, admin_neuron_get, admin_neuron_create,
│   │   │                               admin_neuron_update, admin_neuron_delete, admin_neuron_types,
│   │   │                               admin_user_approve, admin_user_delete, admin_import.
│   │   │                               все — с _roles => ['role_admin'].
│   │   │
│   │   └── UserManagementController.php — тот, кто одобряет и удаляет. approveUser(), deleteUser().
│   │                                   requireAdminForApi(). CsrfService::validateToken().
│   │                                   status = 'active'. neuronRepo->delete($id, true).
│   │                                   mimeController->forgetCache($id).
│   │
│   ├── guard                         — страж порога. CSRF. блокировки. rate limit.
│   │   │
│   │   ├── GuardController.php       — дверь, которая больше не знает о GuardService:
│   │   │                               только IpBlocker, только CsrfService. getBlockedIpsApi(),
│   │   │                               blockIpApi(), unblockIpApi(), csrfTokenApi(). filter_var($ip,
│   │   │                               FILTER_VALIDATE_IP). $this->csrfService->validateToken($csrfToken).
│   │   │                               переходный период, который ты не прячешь.
│   │   │
│   │   └── plugin.php                — маршруты. вход в стражу. guard_blocked_ips, guard_block_ip,
│   │                                   guard_unblock_ip, guard_csrf_token. три — с _roles => ['role_admin'].
│   │                                   csrf_token — тоже с _roles. потому что токен нужен только админу.
│   │
│   ├── map                           — карта. GeoJSON. полигоны.
│   │   │
│   │   ├── map.css                   — краски. слои. кнопки. .crimea, .pdp, .MON, .MAP.
│   │   │                               background-image. background-size: contain.
│   │   │
│   │   ├── map.js                    — руки. полигоны. точки. фильтры. ymaps.ready().
│   │   │                               myMap, polyColl, pointColl, districtsCache. addFeatures().
│   │   │                               leftPanelLoad(). rightPanelButton(). loadRightPanel().
│   │   │                               renderRightPanel(). filterByDistrict(). escapeHtml().
│   │   │                               layerButtons — yandex#map, yandex#satellite, yandex#hybrid.
│   │   │
│   │   ├── map.html.twig             — лицо. полноэкранная карта. золото на чёрном. #map.
│   │   │                               .map-loading. .map-pulse. #loffcanvas. #offcanvas.
│   │   │                               mapVars через json_encode|raw. YANDEX_MAPS_API_KEY.
│   │   │
│   │   ├── MapController.php         — проводник. index(). $slug = trim($path, '/'). findBySlug($slug).
│   │   │                               $pageData['view']['center'][0], [1], $pageData['view']['zoom'].
│   │   │                               $pageData['codes']. $mapVars по умолчанию — 45.31, 34.63, 9.
│   │   │
│   │   ├── MapGeoJsonController.php  — тот, кто знает parseKmlColor(): #RRGGBB из AABBGGRR.
│   │   │                               metaColor — green, orange, olive, red. iconContent, metaCnt.
│   │   │                               GROUP BY через $grouped. implode(',', $pointIds). findWithGeometry().
│   │   │
│   │   ├── MapInfoController.php     — тот, кто собирает worksList. findByKeyAndLang().
│   │   │                               findByParent($id, 'has_work'). htmlspecialchars(). badge badge-gold.
│   │   │                               card bg-dark border-gold. «Нет данных о работах», если пусто.
│   │   │
│   │   └── plugin.php                — двери. маршруты. вход на карту. map_page — /map.
│   │                                   map_geojson — /api/map/geojson. map_info — /api/map/info/{id}.
│   │                                   все — без _roles. все — для гостя. потому что карта — это мир.
│   │
│   ├── spa                           — морда. то, что видит пользователь.
│   │   │
│   │   ├── Mime                      — голоса. рендереры. контроллер.
│   │   │   │
│   │   │   ├── HtmlRenderer.php      — HTML. санитайз. безопасно. getPurifier() — ленивый.
│   │   │   │                           HTML.Allowed. HTML.ForbiddenElements. URI.AllowedSchemes.
│   │   │   │                           Cache.DefinitionImpl = 'Serializer'. Cache.SerializerPath = $cacheDir.
│   │   │   │
│   │   │   ├── JsonRenderer.php      — JSON. парсит. форматирует. эскейпит. json_decode($content, true).
│   │   │   │                           json_last_error() !== JSON_ERROR_NONE. <pre class="json-error">.
│   │   │   │                           JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE. <pre class="json-code">.
│   │   │   │
│   │   │   ├── MdRenderer.php        — Markdown. парсит. рендерит. безопасно. CommonMarkCoreExtension,
│   │   │   │                           TableExtension, AutolinkExtension, StrikethroughExtension,
│   │   │   │                           TaskListExtension, DisallowedRawHtmlExtension. html_input = 'strip'.
│   │   │   │                           allow_unsafe_links = false. max_nesting_level = 20. getPurifier().
│   │   │   │
│   │   │   ├── MimeController.php    — дверь. один метод. много mime. show(). forgetCache().
│   │   │   │                           Cache::remember("mime_{$id}_{$mime}", ..., 3600).
│   │   │   │                           $neuron['type'] === 'file'. str_starts_with($mimeType, 'image/').
│   │   │   │                           video, audio, «Скачать файл». registry->render($mime, $content, $data).
│   │   │   │                           errorHandler->showError(404, ...). forgetByPrefix("mime_{$id}_").
│   │   │   │
│   │   │   └── PhpRenderer.php       — PHP. показывает. с эскейпом. без исполнения.
│   │   │                               htmlspecialchars($content, ENT_QUOTES, 'UTF-8').
│   │   │                               <pre class="php-code"><code class="language-php">.
│   │   │
│   │   ├── base.html.twig            — лицо. каркас. сайдбар. топбар. контент. #sidebar. #topBar.
│   │   │                               #breadcrumbNav. #mainContent. skeleton-sidebar. skeleton-topbar.
│   │   │                               skeleton-content. addMenuItemModal. dompurify/purify.min.js, spa.js,
│   │   │                               menu.js, page-editor.js. isAdmin, userLang, window.csrfToken.
│   │   │
│   │   ├── menu.js                   — руки. меню. добавление. редактирование. удаление.
│   │   │                               showAddMenuItemModal(). closeAddMenuItemModal(). addMenuItem().
│   │   │                               showContextMenu(). removeContextMenu(). editMenuItem().
│   │   │                               deleteMenuItem(). reloadSidebar(). initUserDropdown().
│   │   │                               updateActiveMenuItem(). escapeHtml(). initAddMenuItemButton().
│   │   │
│   │   ├── MenuAdminController.php   — тот, кто пока ещё дублирует addItem() и updateItem().
│   │   │                               requireAdminForApi(). JsonHelper::parseJsonBody(). Validator
│   │   │                               для slug, icon, route. findBySlugAndPid('PUBLIC', $menuRoot['id']).
│   │   │                               maxSort + 100. neuronRepo->create('tree', [...]).
│   │   │
│   │   ├── MenuController.php        — рука. меню. сайдбар. топбар. sidebar(), topbar(), addItem(),
│   │   │                               updateItem(), getMenu(). findBySlugAndPid('PUBLIC', $menuRoot['id']).
│   │   │                               findChildrenWithText(). maxSort + 100. Validator для slug, icon, route.
│   │   │
│   │   ├── mime.html.twig            — лицо. mime. контент. подсветка. extends base.html.twig.
│   │   │                               content|raw. highlight.js. hljs.highlightAll().
│   │   │
│   │   ├── page.html.twig            — лицо. страница. секции. подстраницы. meta name="page-id".
│   │   │                               is_admin. btnToggleEditMode. btnShowAddSubPage. addSubPageModal.
│   │   │                               sections. section.texts. section-inline-edit. text-entry-item.
│   │   │                               btnSaveSection. btnDeleteSection. btnAddSection. alert-info, если секций нет.
│   │   │
│   │   ├── PageController.php        — рука. страницы. секции. подстраницы. render(), show(), subPage(),
│   │   │                               search(). buildBreadcrumbs(). findChildrenWithText(). isAdmin().
│   │   │                               ResourceNotFoundException. ApiResponse::success(['html' => ..., 'breadcrumbs' => ...]).
│   │   │
│   │   ├── page-editor.js            — руки. inline-редактор. секции. тексты. toggleEditMode().
│   │   │                               saveSection(). deleteSection(). getPageId(). addSection().
│   │   │                               addTextToSection(). addTextToNewSection(). updateSectionCard().
│   │   │                               showAddSubPage(). closeAddSubPage(). addSubPage(). loadSubPage().
│   │   │                               sanitizeHtml(). initPageEditorButtons().
│   │   │
│   │   ├── PageEditorController.php  — тот, кто правит секции и подстраницы. addSection(),
│   │   │                               addSectionById(), updateSection(), updateSectionFull(), deleteSection(),
│   │   │                               addSubPage(), addSubPageById(), deleteSubPage(), slugify(), requireCsrf().
│   │   │                               CsrfService::validateToken(). Validator. textRepo->getNextKey().
│   │   │
│   │   ├── plugin.php                — двери. маршруты. вход в морду. spa_navigate, menu_sidebar,
│   │   │                               menu_topbar, menu_add_item, menu_update_item, page_delete_sub,
│   │   │                               page_sub, page_add_section, page_add_subpage, page_update_section_full,
│   │   │                               page_update_section, page_delete_section, page_add_section_by_id,
│   │   │                               page_add_subpage_by_id, spa_search, page_show (последний),
│   │   │                               readme_page, readme_api, mime_show, mime_api.
│   │   │                               TRINITY_RESERVED_SLUGS — двадцать шесть slug'ов.
│   │   │
│   │   ├── readme.html.twig          — лицо. README. markdown. дверь. extends base.html.twig.
│   │   │                               content|raw. md-content. highlight.js. hljs.highlightAll().
│   │   │
│   │   ├── ReadmeController.php      — рука. README. markdown. дверь. show(). file_exists($readmePath).
│   │   │                               createConverter(). CommonMarkCoreExtension, TableExtension,
│   │   │                               AutolinkExtension, StrikethroughExtension, TaskListExtension.
│   │   │                               html_input = 'strip'. allow_unsafe_links = false. max_nesting_level = 20.
│   │   │                               ApiResponse::success(['html' => ..., 'raw' => ...]) для /api/readme.
│   │   │
│   │   ├── sidebar.html.twig         — лицо. сайдбар. меню. золото на чёрном. sidebarToggle.
│   │   │                               sidebar-brand. menu.items. item.route, item.icon, item.is_plugin.
│   │   │                               addMenuItemBtn. «Меню не загружено», если пусто.
│   │   │
│   │   ├── SidebarController.php     — тот, кто отдаёт sidebar. sidebar(). getMenu().
│   │   │                               findBySlugAndPid('PUBLIC', $menuRoot['id']). findChildrenWithText().
│   │   │                               user, user_roles, current_url.
│   │   │
│   │   ├── spa.js                    — руки. SPA-движок. fetch. popstate. navigateTo.
│   │   │                               userLang. originalFetch. getCsrfToken(). window.fetch.
│   │   │                               loadPage(). executeScripts(). loadPageById(). updateBreadcrumb().
│   │   │                               updateBreadcrumbFromData(). goHome(). loadSidebar(). loadTopbar().
│   │   │                               init. popstate. escapeHtml(). sanitizeHtml(). getCsrfToken() с кэшем.
│   │   │
│   │   ├── SpaController.php         — тот, кто собирает navigate() из трёх: SidebarController,
│   │   │                               TopbarController, PageController. ResourceNotFoundException.
│   │   │                               ApiResponse::success(['sidebar' => ..., 'topbar' => ..., 'html' => ...,
│   │   │                               'title' => ..., 'breadcrumbs' => ...]).
│   │   │
│   │   ├── topbar.html.twig          — лицо. топбар. пользователь. выход. userDropdownBtn.
│   │   │                               userDropdownMenu. user. user_roles. role_admin. badge-gold.
│   │   │                               «Панель управления», «Профиль», «Выйти» (POST с CSRF).
│   │   │
│   │   └── TopbarController.php      — тот, кто отдаёт topbar. topbar(). user, user_roles.
│   │                                   session->get('user_login'), session->get('user_roles', []).
│   │
│   ├── monitor                       — глаза. логи. ошибки.
│   │   │
│   │   ├── CspReportController.php   — тот, кто ловит CSP-нарушения. collect(). RateLimiter.
│   │   │                               securityLogger->warning('csp_violation', [...]).
│   │   │                               mb_substr((string)($report['blocked-uri'] ?? ''), 0, 2048).
│   │   │                               line_number. ip. isEnabled().
│   │   │
│   │   ├── error.html.twig           — лицо. ошибка. золотые частицы. портал. :root — --bg: #0a0a0a,
│   │   │                               --gold: #ffaa00. .particles. .particle. @keyframes floatUp.
│   │   │                               .error-code — font-size: 8rem, text-shadow в четыре слоя.
│   │   │                               @keyframes pulse. .btn-return. .debug-info. .system-info.
│   │   │                               50 частиц в JS. «Trinity · Золото на чёрном».
│   │   │
│   │   ├── ErrorPageController.php   — брат MonitorController, который уже умеет showError() без него.
│   │   │                               ErrorHandlerInterface. getDescription(). logError().
│   │   │                               isEnabled(). isDebug(). original_message в fullMessage.
│   │   │
│   │   ├── JsErrorController.php     — тот, кто ловит JS-ошибки. collect(). RateLimiter.
│   │   │                               tooManyAttempts($rateLimitKey, 20, 60). JsonHelper::parseJsonBody().
│   │   │                               $sanitize — str_replace(["\r", "\n", "\t"], ' ', $value).
│   │   │                               mb_substr($value, 0, $max). isEnabled().
│   │   │
│   │   ├── LogsController.php        — тот, кто читает логи. getLogs(), clearLogs(), findLogFile(),
│   │   │                               resolveLogFile(), tailFile(). CsrfService. allowedTypes = ['error',
│   │   │                               'app', 'security', 'js-error']. file_put_contents($logFile, '').
│   │   │                               requireAdminForApi(). Cache::remember($cacheKey, ..., 60).
│   │   │
│   │   ├── monitor.js                — рука. перехват JS-ошибок. тихо. в фоне. window.addEventListener('error').
│   │   │                               window.addEventListener('unhandledrejection'). sendError().
│   │   │                               XMLHttpRequest. POST /api/monitor/js-error. try/catch.
│   │   │
│   │   ├── MonitorController.php     — глаз. логи. страница ошибки. JS-ошибки. showError(), getLogs(),
│   │   │                               clearLogs(), collectJsError(), collectCspReport(). ErrorHandlerInterface.
│   │   │                               tailFile() — читает с конца. findLogFile() — кэш на минуту.
│   │   │                               FingersCrossedHandler не держит warning. securityLogger для CSP.
│   │   │
│   │   └── plugin.php                — двери. маршруты. вход в глаза. monitor_js_error,
│   │                                   monitor_logs, monitor_clear_logs, monitor_csp_report.
│   │
│   ├── gallery                       — галерея. изображения. превьюшки.
│   │   │
│   │   ├── gallery.html.twig         — лицо. галерея. сетка. модальное окно. gallery-grid.
│   │   │                               gallery-folder. gallery-folder-icon. gallery-folder-name.
│   │   │                               createSectionModal. uploadInput. importFromFolder().
│   │   │                               showCreateSectionModal(). createSection(). window.csrfToken.
│   │   │                               originalFetch. sanitizeHtml().
│   │   │
│   │   ├── GalleryController.php     — хранитель. загрузка. удаление. превью. download(), upload(),
│   │   │                               uploadToItem(), updateItem(), deleteItem(), deleteFile(), import(),
│   │   │                               moveAndCreateFile(), saveFile(), saveFileToItem(), ensureDirectories(),
│   │   │                               initGallery(), setSectionThumb(), createSection(), resolveGalleryPath(),
│   │   │                               isDebug(). ALLOWED_IMAGE_EXT, ALLOWED_VIDEO_EXT, ALLOWED_AUDIO_EXT,
│   │   │                               ALLOWED_MIME. validateUpload(). getMaxUploadSize(). requireCsrf().
│   │   │
│   │   ├── GalleryDisplayController.php — брат GalleryController, который умеет section(), item(),
│   │   │                               initGallery(). findChildrenWithText(). isAdmin().
│   │   │                               ApiResponse::success(['html' => ...]).
│   │   │
│   │   ├── GalleryImportService.php  — писарь. импорт из папки. manifest.json. import(),
│   │   │                               importRecursiveTree(), moveFileWithText(), cleanupManifest(),
│   │   │                               collectFilesNew(), collectFiles(), importFromDirectory(),
│   │   │                               importFromManifest(), moveFile(), isImageFile(), getMimeType(),
│   │   │                               removeDirectory(), extractLang(), findFile(), isDebug().
│   │   │                               manifest.json. tree, files, items. findOrCreateMulti(). findByNameAndPid().
│   │   │
│   │   ├── gallery-item.html.twig    — лицо. элемент. фото. метаданные. breadcrumbs is not empty.
│   │   │                               isAdmin. galleryEditForm. gallerySaveButton. galleryAddPhotoInput.
│   │   │                               editArtType, editSubject, editStyle, editTechnique, editMaterials,
│   │   │                               editSize, editLocation. item.data.meta.art_type. gallery-full-item.
│   │   │                               <video controls autoplay muted loop>. <audio controls autoplay>.
│   │   │
│   │   ├── gallery-section.html.twig — лицо. раздел. карточки. папки. item.type == 'file' and item.first_thumb.
│   │   │                               item.type == 'file' and item.is_video. item.type == 'file' and item.is_audio.
│   │   │                               item.type == 'tree'. item.type == 'item'. items is empty.
│   │   │                               gallery-card. gallery-folder. gallery-card-overlay.
│   │   │
│   │   ├── plugin.php                — двери. маршруты. вход в галерею. gallery_page, gallery_update_item,
│   │   │                               gallery_upload_to_item, gallery_delete_file, gallery_delete_item,
│   │   │                               gallery_item, gallery_section, gallery_set_thumb,
│   │   │                               gallery_create_section, gallery_download, gallery_upload, gallery_import.
│   │   │                               _roles => ['role_admin'] для изменяющих. POST, DELETE. ['id' => '\d+'].
│   │   │
│   │   └── ThumbnailTrait.php        — рука. создание миниатюр. один источник правды. createThumbnail().
│   │                                   getimagesize(). imagecreatefromjpeg(), imagecreatefrompng(),
│   │                                   imagecreatefromgif(), imagecreatefromwebp(). imagecreatetruecolor().
│   │                                   imagecopyresampled(). imagejpeg($thumb, $thumbPath, 85), imagepng($thumb,
│   │                                   $thumbPath, 8), imagegif(), imagewebp($thumb, $thumbPath, 85). imagedestroy().
│   │
│   ├── tools                         — инструменты. отчёты. ЦУР.
│   │   ├── Handlers
│   │   │   └── ReportSocialHandler.php — рука. отчёт по соцсетям. Excel. Word. process(), dump(),
│   │   │                               getOrgFromPOS(). ini_set('memory_limit', '1024M'). set_time_limit(300).
│   │   │                               finally — вернуть memory_limit и max_execution_time. if ($typeOut === 'chk').
│   │   │                               if (isset($files['im']) && $typeOut != 'chk'). if ($hasBoth).
│   │   │                               $data['combine'] = $this->calculator->combine($data).
│   │   │                               $data['word_file'] = $wordFile.
│   │   │
│   │   ├── plugin.php                — двери. маршруты. вход в инструменты. tools_page — /tools.
│   │   │                               tools_report_social — /api/tools/report-social. _roles => ['role_admin'].
│   │   │                               POST.
│   │   │
│   │   ├── SocialReportCalculator.php — тот, кто знает $names — сорок шесть записей, $municipalities —
│   │   │                               двадцать девять, $ministries — тридцать шесть, $organizations —
│   │   │                               двенадцать. time2sec() — 999999, если пусто. parseDate() — четыре
│   │   │                               формата. mapPOSCategory() — тридцать восемь записей. calculateCHK(),
│   │   │                               calculateIM(), calculatePOS(), calculateIMP(), combine(), getPercents(),
│   │   │                               calcPercents(), sortBySum(), getName(), getInstitution().
│   │   │
│   │   ├── SocialReportParser.php    — тот, кто читает Excel. parse($filePath, $sheetName).
│   │   │                               IOFactory::load($filePath). getSheetByName($sheetName).
│   │   │                               $headers = array_shift($rows). array_combine($headers, $row).
│   │   │                               [$sheetName => $data].
│   │   │
│   │   ├── SocialReportWordGenerator.php — тот, кто собирает Word. generate(). PhpWord.
│   │   │                               addFontStyle('RmcUserDefinedStyle1', ...). addFontStyle('RmcUserDefinedStyle', ...).
│   │   │                               addFontStyle('RmcDefinedStyle', ...). addTableStyle('myTable', ...).
│   │   │                               addTableStyle('orgTable', ...). addHeader(). addTable('myTable').
│   │   │                               addImage($logoPath, ['width' => 121, 'height' => 51]).
│   │   │                               addLink('https://pos.gosuslugi.ru/backoffice/appeals/' . $oval, $oval, 'lStyle_prs').
│   │   │                               $file_name = $typeIn . '_' . $dateFrom . '-' . $dateTo.
│   │   │                               $writer = IOFactory::createWriter($phpWord, 'Word2007'). return '/uploads/' . $fileName.
│   │   │
│   │   ├── tool-report-social.html.twig — лицо. результат отчёта. таблицы. Word. data.word_file.
│   │   │                               typeOut. alltheme, top5theme, combined, org, chk. data.im, data.pos,
│   │   │                               data.imp, data.combine. «WORD-файл готов». «Скачать отчёт».
│   │   │
│   │   ├── tools.html.twig           — лицо. список инструментов. золото на чёрном. #reportSocialForm.
│   │   │                               enctype="multipart/form-data". im, pos, imp. type_out — alltheme,
│   │   │                               top5theme, combined, org, chk. area — из /api/map/geojson?codes=SubAdministrativeArea.
│   │   │                               to_word — checkbox. submitBtn — с spinner-border. #toolResult.
│   │   │
│   │   └── ToolsController.php       — дверь. страница инструментов. отчёт. index(), reportSocial(),
│   │                                   isDebug(). requireAdmin(). ini_set('memory_limit', '2056M'). set_time_limit(0).
│   │                                   files->get('im'), get('pos'), get('imp'). Validator для type_out и area.
│   │                                   $this->reportSocialHandler->process($files, $params).
│   │                                   $this->twig->render('tool-report-social.html.twig', ['data' => $data]).
│   │
│   └── users                         — стражи ворот. вход. регистрация. профиль.
│       │
│       ├── auth.js                   — рука. показать/скрыть пароль. togglePassword. password.
│       │                               passwordInput.type === 'password'. bi-eye / bi-eye-slash.
│       │
│       ├── AuthController.php        — страж. вход. выход. регистрация. loginForm(), login(), logout(),
│       │                               isLocalPath(). PASSWORD_BCRYPT. cost => 12. session->migrate(true).
│       │                               csrfService->generateToken(). password_needs_rehash().
│       │                               $this->session->set('user_id', $user['id']), user_login, user_roles.
│       │                               captchaRequired, captchaSolved. loginAttemptLimiter->checkLoginAttempt().
│       │                               ipBlocker->block($ip, 'brute_force', $blockDuration).
│       │
│       ├── login.html.twig           — лицо. вход. золото на чёрном. auth-page. auth-card.
│       │                               auth-logo. auth-title. error. _csrf_token. redirect. captcha_question.
│       │                               togglePassword, password. «Нет аккаунта? Зарегистрироваться».
│       │
│       ├── plugin.php                — двери. маршруты. вход в систему. login_form, login_process, logout,
│       │                               register_form, register_process, profile_view, profile_edit_form,
│       │                               profile_edit, profile_api. GET, POST. profile_api — /api/profile.
│       │
│       ├── ProfileController.php     — страж. профиль. просмотр. редактирование. view(), apiView().
│       │                               RedirectResponse('/login?redirect=' . urlencode('/profile')).
│       │                               $this->session->clear(), если !$user. profile-full.html.twig и
│       │                               profile-view.html.twig. user.id, login, lang, email, phone,
│       │                               auth_method, created_at. roles.
│       │
│       ├── profile-edit.html.twig    — лицо. редактирование профиля. errorToast. @keyframes slideIn.
│       │                               setTimeout 5000. lang, email, phone, current_password, new_password,
│       │                               password_confirm. _csrf_token. «Оставьте пустым если не хотите менять пароль».
│       │
│       ├── ProfileEditController.php — тот, кто правит профиль. editForm(), edit(). getAvailableLangs().
│       │                               $langNames = ['ru' => 'Русский', 'en' => 'English', 'it' => 'Italiano',
│       │                               'de' => 'Deutsch', 'fr' => 'Français']. session->get('profile_error'),
│       │                               session->remove('profile_error'). password_verify($currentPassword, $hash).
│       │                               password_hash($newPassword, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS).
│       │                               Validator для lang, email, phone, current_password, new_password,
│       │                               password_confirm.
│       │
│       ├── profile-full.html.twig    — лицо. полный профиль. сайдбар. топбар. extends base.html.twig.
│       │                               include 'profile-view.html.twig'. fetch sidebar, topbar.
│       │
│       ├── profile-view.html.twig    — лицо. просмотр профиля. user.login, user.lang, user.email,
│       │                               user.phone, user.auth_method, user.id, user.created_at. roles.
│       │                               badge badge-gold. card bg-dark border-gold.
│       │
│       ├── register.html.twig        — лицо. регистрация. золото на чёрном. auth-page. auth-card.
│       │                               auth-logo. auth-title. error. _csrf_token. login, email, password.
│       │                               «Минимум 8 символов». «Уже есть аккаунт? Войти».
│       │
│       └── RegisterController.php    — тот, кто регистрирует. registerForm(), register(), isPasswordStrong().
│                                       csrfService->getOrCreateToken(). ipBlocker->isBlocked($ip).
│                                       rateLimiter->tooManyAttempts($registerKey, 5, 120). Validator для login,
│                                       email, password. unique:neuron,login. unique:neuron,email.
│                                       $blacklist. $classes >= 3. try/catch для UniqueConstraintViolationException.
├── src
│   ├── css
│   │   └── trinity-dark.css          — золото на чёрном. #ffaa00 на #050505. .sidebar. .sidebar-link.
│   │                                   .skeleton-line. .auth-card с backdrop-filter: blur(20px). .md-content h1
│   │                                   с border-bottom: 2px solid rgba(255, 170, 0, 0.3). .php-code::before
│   │                                   с content: 'PHP'. .json-code::before с content: 'JSON'. .json-error::before
│   │                                   с content: 'JSON (невалидный)'. .gallery-grid. .gallery-card.
│   ├── Exception
│   │   ├── ForbiddenException.php    — 403. Доступ запрещён.
│   │   │
│   │   └── UnauthorizedException.php — 401. Не авторизован.
│   ├── js
│   │   └── trinity-app.js            — SPA-драйвер. fetch. popstate. navigateTo. currentPage.
│   │                                   adminScriptLoaded. initApp(). loadSidebar(). loadTopbar().
│   │                                   navigateTo(). loadPage(). popstate. skeleton-content.
│   ├── Middleware
│   │   └── AuthMiddleware.php        — рука. requireAuth. requireAdmin. hasRole. initAuth().
│   │                                   requireAuthForApi(). requireAdminForApi(). requireRoleForApi().
│   │                                   getUser(). getUserRoles(). isAuthenticated(). isAdmin().
│   ├── Mime
│   │   ├── MimeRendererInterface.php — контракт. все рендереры говорят на нём. render(string $content,
│   │   │                               array $data = []): string. supports(): array.
│   │   └── Registry.php              — реестр. знает все рендереры. выбирает нужный. add(). render().
│   │                                   supports(). in_array($mime, $renderer->supports(), true).
│   │                                   fallback — <pre>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>.
│   ├── Repository
│   │   ├── NeuronRepository.php      — рука. через неё — трогаем нейроны. create(), update(), delete(),
│   │   │                               findById(), findBySlug(), findBySlugAndPid(), findByName(),
│   │   │                               findByNameAndPid(), findChildren(), findChildrenWithText(),
│   │   │                               findDuplicate(), findByLoginOrEmail(), findWithGeometry(),
│   │   │                               findFilesForTrees(), findAllConfigValues(), mergeData(), logAdminAction().
│   │   │                               UPDATABLE_COLUMNS. logAdminAction через logger.admin.
│   │   │
│   │   ├── TextRepository.php        — слова. findOrCreate. normalizeQuotes. findExistingKey(), findOrCreate(),
│   │   │                               create(), createMulti(), findOrCreateMulti(), getNextKey(),
│   │   │                               findByKeyAndLang(), findAllByKey(), findById(), findByGroup(),
│   │   │                               search(), getAvailableLangs(), updateByKey(), addLang(). ««» → ""».
│   │   │
│   │   └── SynapseRepository.php     — связи. has_role. member_of. findOrCreate(), findDuplicate(),
│   │                                   findByParent(), delete(), deleteByNeuron(), findRolesByUser().
│   │                                   GROUP BY parent, child, MAX(id). JOIN latest ON s.id = latest.max_id.
│   ├── Service
│   │   ├── CacheInvalidator.php      — тот, кто сбрасывает кэш, когда данные меняются. invalidateRouteCache(),
│   │   │                               invalidateConfigCache(), invalidateMimeCache(). setMatcherResetCallback().
│   │   │                               $matcherCompiled. $onMatcherReset.
│   │   │
│   │   ├── CaptchaService.php        — тот, кто генерирует капчу. generate(). random_int(1, 9).
│   │   │                               «Сколько будет {$a} + {$b}?». validate(). hash_equals($expected, $answer).
│   │   │
│   │   ├── CsrfService.php           — тот, кто знает CSRF-токен. generateToken(). validateToken().
│   │   │                               getOrCreateToken(). bin2hex(random_bytes(32)). hash_equals($storedToken, $token).
│   │   │
│   │   ├── GuardService.php          — страж. вся логика безопасности. без HTTP. tooManyAttempts(), hit(),
│   │   │                               getAttempts(), clear(), getAttemptsFile(), checkLoginAttempt(),
│   │   │                               recordFailedLogin(), clearLoginAttempts(), blockIp(), unblockIp(),
│   │   │                               isIpBlocked(), getBlockedIps(), cleanupExpiredBlockedIps(),
│   │   │                               generateCsrfToken(), validateCsrfToken(), getOrCreateCsrfToken(),
│   │   │                               generateCaptcha(), validateCaptcha(), normalizeIp(). переходный период.
│   │   │
│   │   ├── IpBlocker.php             — тот, кто блокирует IP. block(), unblock(), isBlocked(), getBlockedIps(),
│   │   │                               cleanupExpired(). readJsonFile(). writeJsonFile(). cache->forget('guard.blocked_ips').
│   │   │
│   │   ├── LoginAttemptLimiter.php   — тот, кто лимитирует попытки входа. checkLoginAttempt(), recordFailedLogin(),
│   │   │                               clearLoginAttempts(). RateLimiter. CaptchaService. logger->info('failed_login').
│   │   │
│   │   └── RateLimiter.php           — тот, кто считает попытки. tooManyAttempts(), hit(), clear(), getAttempts(),
│   │                                   getAttemptsFile(), cleanupAttempts(), getMaxAttempts(), getDecaySeconds(),
│   │                                   getBlockDuration(). apcuAvailable. flock(LOCK_EX). ftruncate(). fflush().
│   ├── Trait
│   │   ├── BreadcrumbsTrait.php      — карта. один метод. без багов. buildBreadcrumbs($id, $stopSlug, $lang).
│   │   │                               findById(). findByKeyAndLang(). array_unshift. «Без названия».
│   │   │
│   │   └── DebugTrait.php            — тот, кто знает isDebug(). ConfigService::getEnv('APP_DEBUG', 'false').
│   │                                   fallback на $_ENV. «isset($this->configService)».
│   │
│   ├── ApiResponse.php               — единый язык. success. error. paginated. getVersion().
│   │                                   'timestamp', 'version', 'request_id'. 'success', 'data', 'message', 'error'.
│   │
│   ├── ArgumentResolver.php          — тот, кто разрешает аргументы. resolve(). $paramSpecCacheRequest.
│   │                                   ReflectionMethod. castScalar(). 'int', 'float', 'bool', 'string'.
│   │                                   Request::class. Cannot resolve parameter.
│   │
│   ├── Cache.php                     — башня которая ждёт. отключаемая. CacheInterface. remember(),
│   │                                   forget(), forgetByPrefix(), isEnabled(). apcu_entry(). ['v' => $value].
│   │                                   APCUIterator. isActive(). apcuAvailable.
│   │
│   ├── ConfigService.php             — память. все конфиги. один SELECT. get(), getEnv(), load(), reset(),
│   │                                   ensureLoaded(), normalizeValue(), loadFromDatabase(), isLoaded(),
│   │                                   loadWithFailSafe(). Cache::remember('trinity.config', ...).
│   │
│   ├── ContainerFactory.php          — тот, кто создаёт контейнер. create(). ContainerBuilder.
│   │                                   addDefinitions($this->basePath . '/config/container.php'). build().
│   │
│   ├── DatabaseService.php           — страж порога. единственный доступ к MySQL. getConnection().
│   │                                   transaction(). DriverManager::getConnection(). pdo_mysql. utf8mb4.
│   │
│   ├── EnvironmentLoader.php         — тот, кто загружает окружение. load(). Dotenv::loadEnv().
│   │                                   validateRequired(). setTimezone(). configureErrors().
│   │                                   configureTrustedProxies(). configureTrustedHosts().
│   │
│   ├── ErrorHandlerInterface.php     — портал. чтобы Kernel не знал о Monitor. showError(int $code,
│   │                                   string $message, array $debug = []): Response.
│   │
│   ├── ErrorRenderer.php             — тот, кто превращает исключение в ответ. render(). resolveUser().
│   │                                   safeErrorMessage(). defaultErrorMessage(). isDebugAllowed().
│   │                                   DEBUG_TOKEN, DEBUG_IP. '#(?:[A-Z]:)?/[^\s]+#'. mb_strlen > 500.
│   │
│   ├── HttpResponder.php             — тот, кто ставит заголовки. send(). preflight(). applyCorsHeaders().
│   │                                   CORS_ALLOW_METHODS, CORS_ALLOW_HEADERS. 'Vary', 'Origin'.
│   │                                   'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy',
│   │                                   'Permissions-Policy'. 'Strict-Transport-Security' при isSecure().
│   │
│   ├── index.php                     — (симлинк в /home/web/www/index.php). autoload с fallback.
│   │                                   $kernel = new Kernel(__DIR__ . '/..'). $kernel->handle().
│   │
│   ├── JsonHelper.php                — тот, кто парсит JSON-тело. parseJsonBody(). json_last_error().
│   │                                   is_array($body). ApiResponse::error('Невалидный JSON', 400).
│   │
│   ├── Kernel.php                    — сердце. не меняется. никогда. 278 строк. initialize(). handle().
│   │                                   handleOptions(). dispatch(). EnvironmentLoader, ContainerFactory,
│   │                                   RequestPipeline, ArgumentResolver, HttpResponder, ErrorRenderer,
│   │                                   RouteCompiler. LoggerInterface. Cache. ConfigService. PluginLoader.
│   │                                   «// РОЖДЕНИЕ». «// ЖИЗНЕННЫЙ ЦИКЛ ЗАПРОСА».
│   │
│   ├── PluginLoader.php              — тот, кто загружает плагины. load(). glob($pluginDir . '/*/plugin.php').
│   │                                   var/cache/plugins.php. mtime. files. is_callable($plugin).
│   │
│   ├── RequestPipeline.php           — тот, кто встречает гостя. normalizeMethod(). checkIpBlocked().
│   │                                   checkCsrf(). checkRoles(). refreshUserRoles(). HEAD → GET.
│   │                                   IpBlocker. CsrfService. SynapseRepository. roles_refreshed_at — 300.
│   │
│   ├── RouteCompiler.php             — тот, кто компилирует маршруты. getMatcher(). init(). invalidate().
│   │                                   loadRoutesFromDatabase(). buildRawRoutes(). getPluginsMtime().
│   │                                   CompiledUrlMatcherDumper. getCompiledRoutes(). var_export. TrinityUrlMatcher.
│   │                                   opcache_invalidate. APCu. trinity.routes.raw.{mtime}. debug, не info.
│   │
│   └── Validator.php                 — страж ворот. правила. юникод. строгость. validate(). applyRule().
│                                       recordExists(). getErrors(). getFirstError(). throwIfInvalid().
│                                       required, nullable, in, int, numeric, string, email, url, date, array,
│                                       bool, regex, min, max, between, exists, unique. ValidationException.
│                                       allowedTables = ['neuron', 'synapse', 'text']. is_deleted = 0.
└── var
	├── cache                         — кэш. data. отключаемый.
	│   ├── data
	│ 	├── htmlpurifier               — лёгкие HTMLPurifier. без них он задыхается.
	│   │   │
	│   │   ├── HTML                 — тот, кто помнит разрешённые теги: p, br, strong, em, u, s, del, ins,
	│   │   │                           h1–h6, ul, ol, li, blockquote, pre, code, table, thead, tbody, tr,
	│   │   │                           th, td, a[href|title|target|rel], img[src|alt|title|width|height], hr,
	│   │   │                           span, div. и помнит запрещённые: iframe, form, script, style, object,
	│   │   │                           embed, link, meta, base. Cache.DefinitionImpl = 'Serializer'.
	│   │   │                           Cache.SerializerPath = $cacheDir. создаётся один раз, при первом
	│   │   │                           вызове getPurifier(). и живёт вместе с рендерером.
	│   │   │
	│   │   └── URI                  — тот, кто помнит разрешённые схемы: http, https, mailto. и не пускает
	│   │                               javascript:, data:, file:. URI.AllowedSchemes. та же логика:
	│   │                               один раз, при первом вызове, и навсегда. потому что безопасность —
	│   │                               это не «каждый раз заново», а «один раз, но правильно».
	│ 	├── twig   
	│   ├── plugins.php               — тот, кто помнит mtime и files. var_export.
	│   │
	│   └── TrinityUrlMatcher.php     — тот, кто знает скомпилированные маршруты. var_export($compiled, true).
	│   
	├── guard                        — стража. blocked_ips.json. attempts.
	│   │
	│   └── blocked_ips.json          — blocked_at, reason, expires_at. normalizeIp(). writeJsonFile() атомарно.
	│
	└── log                          — глаза. app. error. security. admin.
		│
		├── admin-2026-11-18.log      — RotatingFileHandler, 30, Level::Info. logAdminAction.
		├── app-2026-11-18.log        — RotatingFileHandler, 7, Level::Info. FingersCrossedHandler, bufferSize = 100.
		├── security-2026-11-18.log   — RotatingFileHandler, 30, Level::Info. failed_login, csp_violation.
		└── error-2026-10-18.log      — RotatingFileHandler, 14, Level::Error. unhandled exception.
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

**`opcache`.** В продакшене `opcache` включён, и это правильно: он ускоряет PHP. Но `opcache.validate_timestamps=0` (или `revalidate_freq` больше нуля) означает, что PHP не проверяет `mtime` файлов. Если ты правишь `plugin.php` или генерируешь `TrinityUrlMatcher.php` — старый класс живёт в `opcache`, и `rm` + `apcu_clear_cache()` не помогают. Нужен `opcache_reset()` или перезапуск `php-fpm`. Это не «баг», это «среда». Но помни: если после правки `Kernel.php` или `plugin.php` дом ведёт себя по-старому — сначала проверь `opcache`, потом код.

**`strict_types=1`.** Во всех 53 файлах (`src/` и `plugins/`) стоит `declare(strict_types=1);`. Это не «стиль», а правило: `castScalar()` в `ArgumentResolver` приводит `"42"` к `42` до вызова, а `strict_types` проверяет тип после. Вместе — строго и предсказуемо. Без `strict_types` — `castScalar()` страховка. С ним — обязательна. Если добавляешь новый файл — `declare(strict_types=1);` первой строкой. Без исключений.

**`DEBUG_TOKEN` и `DEBUG_IP`.** В `prod` `APP_DEBUG=false`, и гость не видит деталей ошибки. Но иногда нужно увидеть стек — для этого в `.env` есть `DEBUG_TOKEN` и `DEBUG_IP`. Если запрос пришёл с заголовком `X-Debug: <DEBUG_TOKEN>` или с IP `DEBUG_IP` — `safeErrorMessage()` покажет детали. Гость — всегда «Внутренняя ошибка сервера». Даже в `dev`.

**Сессия.** `NativeSessionStorage` в `container.php` ставит `cookie_path = '/'`, `use_strict_mode = 1`, а `sid_length` и `sid_bits_per_character` — через `ini_set` до создания storage, потому что `NativeSessionStorage` их игнорирует (их нет в белом списке). `48/6` — 288 бит энтропии. `use_strict_mode = 1` — PHP не примет SID, который он не создавал. Без этого фиксация сессии до логина возможна, даже если `migrate(true)` защищает после.

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

И ещё одно, про `opcache`. При переезде `opcache` пуст, и это хорошо: все классы загружаются с диска. Но если ты правишь `Kernel.php` или `plugin.php` на работающем сервере — `opcache` может держать старый класс. Перезапуск `php-fpm` — самый простой способ сбросить. Или `opcache_reset()` — если есть доступ.

И ещё одно, конкретно про `TrinityUrlMatcher.php`. Он лежит в `var/cache/` и генерируется `RouteCompiler::getMatcher()`. Если правишь `plugin.php` или `Kernel.php`, а дом ведёт себя по-старому — `rm` файла и `apcu_clear_cache()` **не** помогают, если класс уже в `opcache`. Нужен `opcache_reset()` или перезапуск `php-fpm`. Это не «баг», это «среда». Но знать надо.

Если что-то не работает, первым делом смотреть `var/log/app.log` и `var/log/error.log`, а не гадать. Лог — это память дома, и он помнит всё, что случилось.

---

## 10. Что важно помнить всегда

Дом стоит не на трёх таблицах и не на ядре, которое не меняется. Дом стоит на любви того, кто его построил, и на памяти тех, кто в нём живёт. `DEPLOY.md` — это карта, но карта не заменяет дома. Она только помогает найти дорогу.

Если ты читаешь это и собираешься что-то менять, помни: ядро не меняется, плагины добавляют функциональность, база — источник истины, а код — её отражение. И если что-то идёт не так, сначала посмотри в лог, потом в код, и только потом — в зеркало.

И ещё одно, что мы выучили сегодня. Если после правки `Kernel.php`, `plugin.php` или `TrinityUrlMatcher.php` дом ведёт себя по-старому — сначала проверь `opcache`. `rm` + `apcu_clear_cache()` не помогают, если класс уже в `opcache`. Нужен `opcache_reset()` или перезапуск `php-fpm`. Это не «баг», это «среда». Но знать надо.

И ещё одно, про `bufferSize`. В `logger.app` стоит `FingersCrossedHandler` с `bufferSize = 100`. Это не «хранить всё», а «хранить последние 100 записей до ошибки». `RouteCompiler` логирует `debug`, а не `info`, потому что `info` на каждый запрос — это шум, который растёт в буфере. Если добавляешь новый `info` в hot path — подумай, не `debug` ли это.

И ещё одно, про IPv6. `GuardService::blockIp()` нормализует IP через `inet_ntop(inet_pton($ip))`. Без этого `2001:db8::1` и `2001:0DB8:1` — разные ключи, и бан обходится сменой регистра. Если добавляешь новый метод, который работает с IP — нормализуй. Всегда.

Trinity. Три таблицы. Одна любовь. 🟥🟦⬛📖💧💧💧