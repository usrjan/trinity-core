<?php

/**
 * DI-КОНТЕЙНЕР
 *
 * Здесь описаны правила создания всех объектов системы.
 * PHP-DI автоматически внедряет зависимости.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Environment теперь получает
 * Session. И csrf_token в Twig читается из $session->get('csrf_token').
 * Один источник. Без $_SESSION. Без «двух источников».
 * Без «пиздеца». Без «потом». Без «завтра».
 *
 * [Мириам]: И — это правильно. Потому что GuardService пишет
 * в $session->set('csrf_token'). А Twig — читает. Один источник.
 * Без «пиздеца». И — «навсегда».
 *
 * @package Jan\Trinity\Core
 */

use Jan\Trinity\Core\DatabaseService;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Mime\Registry;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\GuardService;

use Jan\Trinity\Plugin\Admin\AdminController;
use Jan\Trinity\Plugin\Users\AuthController;
use Jan\Trinity\Plugin\Users\ProfileController;
use Jan\Trinity\Plugin\Monitor\MonitorController;
use Jan\Trinity\Plugin\Guard\GuardController;
use Jan\Trinity\Plugin\Map\MapController;
use Jan\Trinity\Plugin\Tools\ToolsController;
use Jan\Trinity\Plugin\Gallery\GalleryController;
use Jan\Trinity\Plugin\Gallery\GalleryImportService;
use Jan\Trinity\Plugin\Spa\ReadmeController;
use Jan\Trinity\Plugin\Spa\Mime\MimeController;
use Jan\Trinity\Plugin\Spa\Mime\MdRenderer;
use Jan\Trinity\Plugin\Spa\Mime\HtmlRenderer;
use Jan\Trinity\Plugin\Spa\Mime\PhpRenderer;
use Jan\Trinity\Plugin\Spa\Mime\JsonRenderer;

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
	DatabaseService::class => \DI\autowire()
		->constructorParameter('config', \DI\get('db.config')),

	// ============================================
	// 3. КЭШ (APCu)
	// ============================================
	Cache::class => function () {
		$enabled = ($_ENV['CACHE_ENABLED'] ?? 'false') === 'true';
		return new Cache($enabled);
	},

	// ============================================
	// 4. СЕССИЯ
	// ============================================
	// [Лорелея]: Сессия не стартует в конструкторе. Только — создаём.
	// start() будет вызван в Kernel, когда нужно. Или — в GuardService.
	Session::class => function () {
		$storage = new NativeSessionStorage([
			'cookie_lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 86400),
			'cookie_secure'   => ($_ENV['APP_ENV'] ?? 'prod') === 'prod',
			'cookie_httponly' => true,
			'cookie_samesite' => 'lax',
		]);

		return new Session($storage);
	},

	// ============================================
	// 5. ШАБЛОНИЗАТОР TWIG
	// ============================================
	// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Environment получает Session.
	// И csrf_token читается из $session->get('csrf_token').
	// Один источник. Без $_SESSION. Без «двух источников».
	// Без «пиздеца». Без «потом». Без «завтра».
	Environment::class => function (Session $session) {
		$basePath = dirname(__DIR__);
		$isDev = ($_ENV['APP_ENV'] ?? 'prod') === 'dev';

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
			'strict_variables' => $isDev,
		]);

		// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. csrf_token стартует сессию,
		// если она не запущена. И — возвращает токен. Без «пустого токена».
		// Без «пиздеца». Без «потом». Без «завтра».
		$twig->addFunction(new \Twig\TwigFunction('csrf_token', function () use ($session) {
			if (!$session->isStarted()) {
				$session->start();
			}

			$token = $session->get('csrf_token');
			if ($token === null) {
				$token = bin2hex(random_bytes(32));
				$session->set('csrf_token', $token);
			}

			return $token;
		}));

		$twig->addGlobal('gallery_url', rtrim($_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery', '/'));

		return $twig;
	},

	// ============================================
	// 6. РЕПОЗИТОРИИ (БАЗА ДАННЫХ)
	// ============================================
	TextRepository::class => \DI\autowire(),

	NeuronRepository::class => \DI\autowire()
		->constructorParameter('logger', \DI\get('logger.admin')),

	SynapseRepository::class => \DI\autowire(),

	// ============================================
	// 7. ПАРАМЕТРЫ ПРИЛОЖЕНИЯ
	// ============================================
	'app.base_path' => dirname(__DIR__),

	// ============================================
	// 8. КОНТРОЛЛЕРЫ ПЛАГИНОВ
	// ============================================
	MonitorController::class => function (
		Environment $twig,
		Session $session,
		NeuronRepository $neuronRepo,
		GuardService $guard,
		SynapseRepository $synapseRepo,
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
			$synapseRepo,
			$logger,
			$configService,
			$validator
		);
	},

	// [Лорелея]: GuardService теперь получает Session. Чтобы
	// generateCsrfToken и validateCsrfToken работали с Symfony Session.
	// Без «двух источников». Без «пиздеца». Без «потом».
	GuardService::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	GuardController::class => \DI\autowire(),
	AuthController::class => \DI\autowire(),
	ProfileController::class => \DI\autowire(),
	MapController::class => \DI\autowire(),
	ToolsController::class => \DI\autowire(),

	// ============================================
	// VALIDATOR
	// ============================================
	Validator::class => \DI\autowire()
		->constructorParameter('neuronRepo', \DI\get(NeuronRepository::class)),

	// ============================================
	// 9. ГАЛЕРЕЯ — ПУТИ К ФАЙЛАМ
	// ============================================
	'gallery.upload_dir' => $_ENV['GALLERY_UPLOAD_DIR'] ?? (dirname(__DIR__) . '/../../../www/uploads/gallery'),
	'gallery.upload_url' => $_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery',

	// ============================================
	// 10. GALLERY CONTROLLER
	// ============================================
	GalleryController::class => \DI\autowire()
		->constructorParameter('galleryUploadDir', \DI\get('gallery.upload_dir'))
		->constructorParameter('galleryUploadUrl', \DI\get('gallery.upload_url')),

	// ============================================
	// 11. GALLERY IMPORT SERVICE
	// ============================================
	GalleryImportService::class => \DI\autowire()
		->constructorParameter('galleryDir', \DI\get('gallery.upload_dir')),

	// ============================================
	// 12. ЛОГГЕРЫ (Monolog)
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

	'logger.admin' => function () {
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

		$logger = new Logger('admin');

		$adminHandler = new RotatingFileHandler($logDir . '/admin.log', 30, Level::Info);
		$adminHandler->setFormatter($formatter);
		$logger->pushHandler($adminHandler);

		return $logger;
	},

	LoggerInterface::class => \DI\get('logger.app'),

	// ============================================
	// 12. ОБРАБОТЧИК ОШИБОК
	// ============================================
	ErrorHandlerInterface::class => \DI\get(MonitorController::class),

	// ============================================
	// CONFIG SERVICE
	// ============================================
	ConfigService::class => \DI\autowire(),

	// ============================================
	// MIME REGISTRY
	// ============================================
	MdRenderer::class   => \DI\autowire(),
	HtmlRenderer::class => \DI\autowire(),
	PhpRenderer::class  => \DI\autowire(),
	JsonRenderer::class => \DI\autowire(),

	Registry::class => function (
		MdRenderer $md,
		HtmlRenderer $html,
		PhpRenderer $php,
		JsonRenderer $json
	) {
		$registry = new Registry();
		$registry->add($md);
		$registry->add($html);
		$registry->add($php);
		$registry->add($json);
		return $registry;
	},

	// ============================================
	// MIME CONTROLLER
	// ============================================
	MimeController::class => \DI\autowire(),

	// ============================================
	// README CONTROLLER
	// ============================================
	ReadmeController::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	// ============================================
	// ADMIN CONTROLLER
	// ============================================
	AdminController::class => \DI\autowire(),
];