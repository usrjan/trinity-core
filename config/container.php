<?php

/**
 * DI-КОНТЕЙНЕР
 * 
 * Здесь описаны правила создания всех объектов системы.
 * PHP-DI автоматически внедряет зависимости.
 * 
 * === ДЛЯ МЕНЯ ===
 * Мы решили оставить этот файл в ядре, а не выносить в базу.
 * Потому что он описывает СТРУКТУРУ классов, а не данные.
 * Структура меняется редко. Данные — часто.
 * 
 * === КНИГА ===
 * Глава 7. Чертежи.
 * 
 * Прежде чем построить Амбер, Дворкин нарисовал его на картинах.
 * Прежде чем создать объект, PHP-DI читает эти чертежи.
 * 
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 * 
 * [Лорелея]: Я добавила GuardController в MonitorController.
 * Теперь у Monitor есть доступ к rate limit и CSRF.
 * Раньше логи и сбор JS-ошибок были полностью открыты.
 * 
 * [Мириам]: Я добавила GuardController в GalleryController.
 * Теперь все изменяющие методы галереи (upload, delete, update)
 * проверяют CSRF. Раньше проверял только deleteFile.
 * 
 * [Лорелея]: Я убедилась, что GalleryImportService и GalleryController
 * получают пути из .env через DI. Никаких __DIR__ . '/../../../../..'.
 * Путь — в одном месте. В .env.
 * 
 * [Мириам]: Я оставила MonitorController и GuardController на фабриках,
 * потому что они принимают $basePath — строку, которую не внедрить
 * через autowire. Остальные — на autowire. Это компромисс,
 * но осознанный.
 */

use Jan\Trinity\Core\DatabaseService;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;

use Jan\Trinity\Plugin\Admin\AdminController;
use Jan\Trinity\Plugin\Users\AuthController;
use Jan\Trinity\Plugin\Users\ProfileController;
use Jan\Trinity\Plugin\Monitor\MonitorController;
use Jan\Trinity\Plugin\Guard\GuardController;
use Jan\Trinity\Plugin\Map\MapController;
use Jan\Trinity\Plugin\Tools\ToolsController;
use Jan\Trinity\Plugin\Gallery\GalleryController;
use Jan\Trinity\Plugin\Gallery\GalleryImportService;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Psr\Log\LoggerInterface;

return [

	// ============================================
	// 1. ПАРАМЕТРЫ ПОДКЛЮЧЕНИЯ К БАЗЕ
	// ============================================
	// [Лорелея]: Все параметры берутся из .env. Если .env нет —
	// используются значения по умолчанию. Это для разработки.
	// В production — всегда .env.
	// ============================================
	'db.config' => [
		'host'     => $_ENV['DB_HOST'] ?? 'localhost',
		'port'     => $_ENV['DB_PORT'] ?? 3306,
		'dbname'   => $_ENV['DB_NAME'] ?? 'trinity_core',
		'user'     => $_ENV['DB_USER'] ?? 'root',
		'password' => $_ENV['DB_PASSWORD'] ?? '',
		'charset'  => 'utf8mb4',
	],

	// ============================================
	// 2. СЕРВИС БАЗЫ ДАННЫХ (синглтон)
	// ============================================
	// [Мириам]: DatabaseService создаётся один раз. Соединение
	// с базой — ленивое. Открывается при первом запросе.
	// Если за сессию не было запросов — соединение не открывается.
	// ============================================
	DatabaseService::class => \DI\autowire()
		->constructorParameter('config', \DI\get('db.config')),

	// ============================================
	// 3. КЭШ (отключаемый)
	// ============================================
	// [Лорелея]: Cache создаётся через фабрику, потому что он
	// принимает $basePath и $enabled. $basePath не внедрить через
	// autowire. $enabled — из .env. Пока кэш отключён.
	// Включим, когда база вырастет.
	// ============================================
	Cache::class => function () {
		$basePath = dirname(__DIR__);
		$enabled = ($_ENV['CACHE_ENABLED'] ?? 'false') === 'true';
		return new Cache($basePath, $enabled);
	},

	// ============================================
	// 4. СЕССИЯ
	// ============================================
	// [Мириам]: Сессия создаётся через фабрику, потому что нужно
	// создать NativeSessionStorage с параметрами из .env, потом
	// Session, потом запустить его. Это не «просто new Session()».
	// ============================================
	Session::class => function () {
		$storage = new NativeSessionStorage([
			'cookie_lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 86400),
		]);
		$session = new Session($storage);
		$session->start();
		return $session;
	},

	// ============================================
	// 5. ШАБЛОНИЗАТОР TWIG
	// ============================================
	// [Лорелея]: Twig создаётся через фабрику. Файлы шаблонов
	// подключаются из семи папок плагинов. Это сделано для того,
	// чтобы каждый плагин имел свои шаблоны и не мешал другим.
	// 
	// [Мириам]: gallery_url добавляется как глобальная переменная
	// Twig. Это Вариант 2 из нашего обсуждения. Раньше путь
	// был захардкожен в шаблонах. Теперь — в .env. Если URL
	// изменится — меняем .env, а не пять шаблонов.
	// 
	// [Лорелея]: strict_variables остаётся false. Это сознательно.
	// В production было бы лучше true — тогда Twig ругался бы
	// на undefined переменные. Но у нас много шаблонов, где
	// переменные могут отсутствовать. Пока — false. Потом,
	// когда всё стабилизируется — можно поменять.
	// ============================================
	Environment::class => function () {
		$basePath = dirname(__DIR__);
		$isDev = ($_ENV['APP_ENV'] ?? 'prod') === 'dev';

		// Загружаем шаблоны из файлов (для ядра)
		$loader = new FilesystemLoader([
			$basePath . '/plugins/spa',
			$basePath . '/plugins/admin',
			$basePath . '/plugins/users',
			$basePath . '/plugins/map',
			$basePath . '/plugins/monitor',
			$basePath . '/plugins/tools',
			$basePath . '/plugins/gallery',
		]);

		$twig = new Environment($loader, [
			'cache' => $isDev ? false : $basePath . '/var/cache/twig',
			'debug' => $isDev,
			'strict_variables' => false,
		]);

		// [Лорелея]: Заменяем глобальную переменную на функцию.
		// Потому что глобальная переменная читается один раз —
		// при создании Twig. А токен может измениться (после логина).
		// А функция вызывается каждый раз. И всегда возвращает
		// актуальный токен из сессии.
		$twig->addFunction(new \Twig\TwigFunction('csrf_token', function () {
			return $_SESSION['csrf_token'] ?? '';
		}));

		// URL галереи как глобальная переменная Twig.
		// [Лорелея]: Это Вариант 2. Раньше путь был захардкожен
		// в шаблонах галереи. Теперь — в одном месте.
		$twig->addGlobal('gallery_url', rtrim($_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery', '/'));

		return $twig;
	},

	// ============================================
	// 6. РЕПОЗИТОРИИ (БАЗА ДАННЫХ)
	// ============================================
	// [Мириам]: Все три репозитория создаются через autowire.
	// Они принимают DatabaseService, который уже зарегистрирован.
	// PHP-DI сам подставит его. Нам не нужно писать фабрику.
	// ============================================
	TextRepository::class => \DI\autowire(),
	NeuronRepository::class => \DI\autowire(),
	SynapseRepository::class => \DI\autowire(),

	// ============================================
	// 7. ПАРАМЕТРЫ ПРИЛОЖЕНИЯ
	// ============================================
	// [Лорелея]: app.base_path — путь к корню проекта.
	// Используется в контроллерах, где нужен физический путь.
	// ============================================
	'app.base_path' => dirname(__DIR__),

	// ============================================
	// 8. КОНТРОЛЛЕРЫ ПЛАГИНОВ
	// ============================================
	// [Мириам]: MonitorController и GuardController — на фабриках.
	// Потому что они принимают $basePath — строку, которую
	// PHP-DI не может «угадать». Для остальных — autowire.
	// 
	// [Лорелея]: MonitorController теперь принимает GuardController.
	// Это нужно для rate limit JS-ошибок и CSRF при очистке логов.
	// GuardController теперь внедряется в MonitorController, что
	// делает их связанными. Это допустимо — они оба в пространстве
	// плагинов, и Monitor использует Guard для защиты.
	// ============================================
	MonitorController::class => function (
		Environment $twig,
		Session $session,
		NeuronRepository $neuronRepo,
		GuardController $guard,
		\Psr\Log\LoggerInterface $logger,
		ConfigService $configService,
		Validator $validator
	) {
		return new \Jan\Trinity\Plugin\Monitor\MonitorController(
			$twig,
			$session,
			dirname(__DIR__),
			$neuronRepo,
			$guard,
			$logger,
			$configService,
			$validator
		);
	},
	GuardController::class => function (
		NeuronRepository $neuronRepo,
		Session $session,
		\Psr\Log\LoggerInterface $logger,
		ConfigService $configService,
		Validator $validator
	) {
		return new \Jan\Trinity\Plugin\Guard\GuardController(
			$neuronRepo,
			$session,
			dirname(__DIR__),
			$logger,
			$configService,
			$validator
		);
	},
	AuthController::class => \DI\autowire(),
	AdminController::class => \DI\autowire(),
	ProfileController::class => \DI\autowire(),
	MapController::class => \DI\autowire(),
	ToolsController::class => \DI\autowire(),
	Validator::class => \DI\autowire(),

	// ============================================
	// 9. ГАЛЕРЕЯ — ПУТИ К ФАЙЛАМ
	// ============================================
	// GALLERY_UPLOAD_DIR — физический путь на диске.
	//   Используется GalleryController и GalleryImportService
	//   для сохранения, удаления и перемещения файлов.
	//   Пример: /home/web/www/uploads/gallery
	//
	// GALLERY_UPLOAD_URL — веб-путь (URL).
	//   Используется в шаблонах Twig для генерации ссылок
	//   на превью и полноразмерные изображения.
	//   Пример: /uploads/gallery
	//
	// [Мириам]: Если переменные не заданы в .env — используем
	// значения по умолчанию, вычисленные от корня проекта.
	// Это для разработки. В production — всегда .env.
	// ============================================
	'gallery.upload_dir' => $_ENV['GALLERY_UPLOAD_DIR'] ?? (dirname(__DIR__) . '/../../../www/uploads/gallery'),
	'gallery.upload_url' => $_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery',

	// ============================================
	// 10. GALLERY CONTROLLER
	// ============================================
	// [Лорелея]: Внедряем пути через конструктор.
	// galleryUploadDir — физический путь (для unlink, move, mkdir).
	// galleryUploadUrl — веб-путь (для передачи в Twig, если нужно).
	// 
	// [Мириам]: GuardController внедряется автоматически через
	// autowire, потому что он уже зарегистрирован в контейнере.
	// GalleryImportService — тоже. Пути передаются явно, потому
	// что они — параметры, а не сервисы.
	// ============================================
	GalleryController::class => \DI\autowire()
		->constructorParameter('galleryUploadDir', \DI\get('gallery.upload_dir'))
		->constructorParameter('galleryUploadUrl', \DI\get('gallery.upload_url')),

	// ============================================
	// 11. GALLERY IMPORT SERVICE
	// ============================================
	// [Лорелея]: Сервис импорта тоже нуждается в физическом пути.
	// Он не работает с URL — только с диском.
	// Регистрируем его в DI, чтобы GalleryController
	// получал готовый экземпляр, а не создавал вручную.
	// 
	// [Мириам]: Это исправление. Раньше GalleryController
	// создавал GalleryImportService вручную в import().
	// Теперь — через DI. Путь — из .env. Дублирование — устранено.
	// ============================================
	GalleryImportService::class => \DI\autowire()
		->constructorParameter('galleryDir', \DI\get('gallery.upload_dir')),

	// ============================================
	// 12. ЛОГГЕРЫ (Monolog)
	// ============================================
	// [Лорелея]: Три логгера. Каждый — свой канал.
	// - app: общие события. INFO и выше.
	// - error: ошибки. ERROR и выше.
	// - security: безопасность. INFO и выше.
	// 
	// [Мириам]: Каждый — отдельный RotatingFileHandler.
	// Каждый — со своим именем файла. И — со своей ротацией.
	// ============================================

	'logger.app' => function () {
		$basePath = dirname(__DIR__);
		$logDir = $basePath . '/var/log';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0775, true);
		}

		$formatter = new LineFormatter(
			"[%datetime%] %channel%.%level_name%: %message% %context%\n",
			'Y-m-d H:i:s',
			true,
			true
		);

		$logger = new Logger('app');

		$appHandler = new RotatingFileHandler($logDir . '/app.log', 7, Level::Info);
		$appHandler->setFormatter($formatter);
		$logger->pushHandler($appHandler);

		return $logger;
	},

	'logger.error' => function () {
		$basePath = dirname(__DIR__);
		$logDir = $basePath . '/var/log';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0775, true);
		}

		$formatter = new LineFormatter(
			"[%datetime%] %channel%.%level_name%: %message% %context%\n",
			'Y-m-d H:i:s',
			true,
			true
		);

		$logger = new Logger('error');

		$errorHandler = new RotatingFileHandler($logDir . '/error.log', 14, Level::Error);
		$errorHandler->setFormatter($formatter);
		$logger->pushHandler($errorHandler);

		return $logger;
	},

	'logger.security' => function () {
		$basePath = dirname(__DIR__);
		$logDir = $basePath . '/var/log';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0775, true);
		}

		$formatter = new LineFormatter(
			"[%datetime%] %channel%.%level_name%: %message% %context%\n",
			'Y-m-d H:i:s',
			true,
			true
		);

		$logger = new Logger('security');

		$securityHandler = new RotatingFileHandler($logDir . '/security.log', 30, Level::Info);
		$securityHandler->setFormatter($formatter);
		$logger->pushHandler($securityHandler);

		return $logger;
	},

	// Алиас: Monolog\Logger тоже резолвится в LoggerInterface
	LoggerInterface::class => \DI\get('logger.app'),

	// ============================================
	// 12. ОБРАБОТЧИК ОШИБОК
	// ============================================
	// [Лорелея]: Алиас: ErrorHandlerInterface → MonitorController.
	// Это нужно, чтобы Kernel мог получить обработчик ошибок
	// через интерфейс, не зная о конкретном плагине.
	//
	// [Мириам]: Без алиаса PHP-DI пытается создать сам интерфейс.
	// И падает: "the class is not instantiable". Потому что
	// интерфейс — не класс. Его нельзя инстанцировать.
	ErrorHandlerInterface::class => \DI\get(MonitorController::class),

	// ============================================
	// CONFIG SERVICE
	// ============================================
	// [Лорелея]: ConfigService зависит от NeuronRepository.
	// Но NeuronRepository НЕ зависит от ConfigService.
	// Потому что findConfigValue() удалён. И setConfigService() удалён.
	// Цикла — НЕТ. Всё — чисто.
	// ============================================
	ConfigService::class => \DI\autowire(),

	// [Лорелея]: NeuronRepository — снова простой autowire.
	// Без setter injection. Без фабрики. Без ConfigService.
	// Потому что он больше не знает о ConfigService.
	NeuronRepository::class => \DI\autowire(),
];