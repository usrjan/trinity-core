<?php

declare(strict_types=1);

/**
 * DI-КОНТЕЙНЕР
 *
 * Здесь описаны правила создания всех объектов системы.
 * PHP-DI автоматически внедряет зависимости.
 *
 * @package Jan\Trinity\Core
 */

use Jan\Trinity\Core\DatabaseService;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\ArgumentResolver;
use Jan\Trinity\Core\HttpResponder;
use Jan\Trinity\Core\RouteCompiler;
use Jan\Trinity\Core\ErrorRenderer;
use Jan\Trinity\Core\RequestPipeline;
use Jan\Trinity\Core\EnvironmentLoader;
use Jan\Trinity\Core\PluginLoader;
use Jan\Trinity\Core\ContainerFactory;
use Jan\Trinity\Core\Mime\Registry;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\CacheInvalidator;
use Jan\Trinity\Core\Service\CsrfService;
use Jan\Trinity\Core\Service\RateLimiter;
use Jan\Trinity\Core\Service\IpBlocker;
use Jan\Trinity\Core\Service\CaptchaService;
use Jan\Trinity\Core\Service\LoginAttemptLimiter;
use Jan\Trinity\Core\Service\PasswordHasher;
use Jan\Trinity\Core\Service\RecordChecker;
use Jan\Trinity\Plugin\Admin\AdminController;
use Jan\Trinity\Plugin\Admin\ImportController;
use Jan\Trinity\Plugin\Admin\DashboardRepository;
use Jan\Trinity\Plugin\Admin\DashboardService;
use Jan\Trinity\Plugin\Admin\DashboardController;
use Jan\Trinity\Plugin\Admin\UserManagementController;
use Jan\Trinity\Plugin\Users\AuthController;
use Jan\Trinity\Plugin\Users\ProfileController;
use Jan\Trinity\Plugin\Users\ProfileEditController;
use Jan\Trinity\Plugin\Users\RegisterController;
use Jan\Trinity\Plugin\Monitor\ErrorPageController;
use Jan\Trinity\Plugin\Monitor\JsErrorController;
use Jan\Trinity\Plugin\Monitor\LogsController;
use Jan\Trinity\Plugin\Monitor\CspReportController;
use Jan\Trinity\Plugin\Guard\GuardController;
use Jan\Trinity\Plugin\Map\MapController;
use Jan\Trinity\Plugin\Map\MapInfoController;
use Jan\Trinity\Plugin\Map\MapGeoJsonController;
use Jan\Trinity\Plugin\Tools\ToolsController;
use Jan\Trinity\Plugin\Tools\SocialReportParser;
use Jan\Trinity\Plugin\Tools\SocialReportCalculator;
use Jan\Trinity\Plugin\Tools\SocialReportWordGenerator;
use Jan\Trinity\Plugin\Tools\Handlers\ReportSocialHandler;
use Jan\Trinity\Plugin\Gallery\GalleryController;
use Jan\Trinity\Plugin\Gallery\GalleryDisplayController;
use Jan\Trinity\Plugin\Gallery\GalleryImportService;
use Jan\Trinity\Plugin\Spa\PageController;
use Jan\Trinity\Plugin\Spa\PageEditorController;
use Jan\Trinity\Plugin\Spa\PageRepository;
use Jan\Trinity\Plugin\Spa\SpaController;
use Jan\Trinity\Plugin\Spa\SidebarController;
use Jan\Trinity\Plugin\Spa\TopbarController;
use Jan\Trinity\Plugin\Spa\MenuAdminController;
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
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Psr\Log\LoggerInterface;


return [

	// ============================================
	// 1. ПАРАМЕТРЫ ПРИЛОЖЕНИЯ
	// ============================================
	'app.base_path' => dirname(__DIR__),

	// ============================================
	// 2. ПАРАМЕТРЫ ПОДКЛЮЧЕНИЯ К БАЗЕ
	// ============================================
	'db.config' => (function () {
		$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
		$missing = [];

		foreach ($required as $key) {
			if (empty($_ENV[$key])) {
				$missing[] = $key;
			}
		}

		if (!empty($missing)) {
			throw new \RuntimeException(
				'Trinity: missing required DB config in .env: ' . implode(', ', $missing)
			);
		}

		return [
			'host'     => $_ENV['DB_HOST'],
			'port'     => $_ENV['DB_PORT'] ?? 3306,
			'dbname'   => $_ENV['DB_NAME'],
			'user'     => $_ENV['DB_USER'],
			'password' => $_ENV['DB_PASSWORD'],
			'charset'  => 'utf8mb4',
		];
	})(),

	// ============================================
	// 3. СЕРВИС БАЗЫ ДАННЫХ (синглтон)
	// ============================================
	DatabaseService::class => \DI\autowire()
		->constructorParameter('config', \DI\get('db.config')),

	// ============================================
	// 4. КЭШ (APCu)
	// ============================================
	Cache::class => function () {
		$enabled = ($_ENV['CACHE_ENABLED'] ?? 'true') === 'true';
		return new Cache($enabled);
	},

	// ============================================
	// 5. CACHE INVALIDATOR
	// ============================================
	CacheInvalidator::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	// ============================================
	// 6. СЕССИЯ
	// ============================================
	Session::class => function () {
		$isSecure = ($_ENV['SESSION_SECURE'] ?? 'false') === 'true';

		$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| ($_SERVER['SERVER_PORT'] ?? null) === '443';

		if ($isHttps && !$isSecure) {
			error_log('[Trinity] SESSION_SECURE is false, but the site is on HTTPS. Enable it in .env.');
		}

		ini_set('session.sid_length', '48');
		ini_set('session.sid_bits_per_character', '6');

		$storage = new NativeSessionStorage([
			'cookie_lifetime'         => (int)($_ENV['SESSION_LIFETIME'] ?? 86400),
			'cookie_secure'           => $isSecure,
			'cookie_httponly'         => true,
			'cookie_samesite'         => 'lax',
			'cookie_path'             => '/',
			'use_strict_mode'         => 1,
		]);

		return new Session($storage);
	},

	// ============================================
	// 7. ШАБЛОНИЗАТОР TWIG
	// ============================================
	Environment::class => function (
			Session $session,
			CsrfService $csrfService,
			ConfigService $configService
		) {
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

		$twig->addFunction(new \Twig\TwigFunction('csrf_token', function () use ($csrfService) {
			return $csrfService->getOrCreateToken();
		}));

		$twig->addGlobal('gallery_url', rtrim($_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery', '/'));

		$configService->load();
		$twig->addGlobal('app_version', $configService->get('app.version') ?? $_ENV['APP_VERSION'] ?? 'unknown');

		return $twig;
	},

	// ============================================
	// 8. РЕПОЗИТОРИИ (БАЗА ДАННЫХ)
	// ============================================
	TextRepository::class => \DI\autowire(),

	NeuronRepository::class => \DI\autowire()
		->constructorParameter('logger', \DI\get('logger.admin')),

	SynapseRepository::class => \DI\autowire(),

	// ============================================
	// 8.1. PAGE REPOSITORY — ОДИН ИСТОЧНИК ПРАВДЫ
	// ============================================
	// [Лорелея]: Теперь сборка $sections, $subPages и $title
	// живёт здесь. И PageController — только зовёт buildPageData().
	// Потому что «один источник правды» — это не «красиво».
	// Это — правильно.
	PageRepository::class => \DI\autowire(),

	// ============================================
	// 9. КОНТРОЛЛЕРЫ ПЛАГИНОВ
	// ============================================

	// --- Обработчик ошибок ---
	ErrorHandlerInterface::class => \DI\get(ErrorPageController::class),

	ErrorPageController::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	// --- Монитор ---
	JsErrorController::class => \DI\autowire(),

	LogsController::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	CspReportController::class => \DI\autowire()
		->constructorParameter('securityLogger', \DI\get('logger.security')),

	// --- Сервисы безопасности ---
	CsrfService::class => \DI\autowire(),

	RateLimiter::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	IpBlocker::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	CaptchaService::class => \DI\autowire(),

	LoginAttemptLimiter::class => \DI\autowire()
		->constructorParameter('logger', \DI\get('logger.security')),

	// --- Стражи и пользователи ---
	GuardController::class => \DI\autowire(),
	AuthController::class => \DI\autowire(),
	RegisterController::class => \DI\autowire(),
	ProfileController::class => \DI\autowire(),
	ProfileEditController::class => \DI\autowire(),
	PasswordHasher::class => \DI\autowire(PasswordHasher::class),

	// --- Карта ---
	MapController::class => \DI\autowire(),
	MapInfoController::class => \DI\autowire(),
	MapGeoJsonController::class => \DI\autowire(),

	// --- Ядро ---
	ArgumentResolver::class => \DI\autowire(),
	HttpResponder::class => \DI\autowire(),
	RouteCompiler::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path'))
		->constructorParameter('logger', \DI\get('logger.app')),
	EnvironmentLoader::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),
	PluginLoader::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path'))
		->constructorParameter('logger', \DI\get('logger.app')),
	ContainerFactory::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	// ============================================
	// 10. ERROR RENDERER
	// ============================================
	ErrorRenderer::class => function (
		ErrorPageController $errorHandler,
		ConfigService $configService,
		LoggerInterface $logger
	) {
		return new ErrorRenderer(
			$errorHandler,
			$configService->get('app.debug', false),
			$logger
		);
	},

	RequestPipeline::class => \DI\autowire(),

	// ============================================
	// 11. VALIDATOR
	// ============================================
	RecordChecker::class => \DI\autowire(RecordChecker::class),

	Validator::class => \DI\autowire(),

	// ============================================
	// 12. ГАЛЕРЕЯ — ПУТИ К ФАЙЛАМ
	// ============================================
	'gallery.upload_dir' => $_ENV['GALLERY_UPLOAD_DIR'] ?? (dirname(__DIR__) . '/../../../www/uploads/gallery'),
	'gallery.upload_url' => $_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery',

	// ============================================
	// 13. GALLERY CONTROLLER
	// ============================================
	GalleryController::class => \DI\autowire()
		->constructorParameter('galleryUploadDir', \DI\get('gallery.upload_dir'))
		->constructorParameter('galleryUploadUrl', \DI\get('gallery.upload_url')),

	GalleryDisplayController::class => \DI\autowire(),

	// ============================================
	// 14. GALLERY IMPORT SERVICE
	// ============================================
	GalleryImportService::class => \DI\autowire()
		->constructorParameter('galleryDir', \DI\get('gallery.upload_dir')),

	// ============================================
	// 15. ЛОГГЕРЫ (Monolog)
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
			false,
			true
		);

		$logger = new Logger('app');

		$appHandler = new RotatingFileHandler($logDir . '/app.log', 7, Level::Info);
		$appHandler->setFormatter($formatter);

		$bufferHandler = new FingersCrossedHandler(
			$appHandler,
			Level::Error,
			100,
			true,
			true
		);

		$logger->pushHandler($bufferHandler);

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
			false,
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
			false,
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
			false,
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
	// 16. CONFIG SERVICE
	// ============================================
	ConfigService::class => \DI\autowire()
		->constructorParameter('cache', \DI\get(Cache::class)),

	// ============================================
	// 17. MIME REGISTRY
	// ============================================
	MdRenderer::class => \DI\autowire(),
	HtmlRenderer::class => \DI\autowire(),
	PhpRenderer::class => \DI\autowire(),
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
	// 18. MIME CONTROLLER
	// ============================================
	MimeController::class => \DI\autowire(),

	// ============================================
	// 19. README CONTROLLER
	// ============================================
	ReadmeController::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	// ============================================
	// 20. ADMIN CONTROLLER
	// ============================================
	AdminController::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	ImportController::class => \DI\autowire(),

	DashboardRepository::class => \DI\autowire(),
	DashboardService::class => \DI\autowire(),
	DashboardController::class => \DI\autowire(),

	UserManagementController::class => \DI\autowire(),

	// ============================================
	// 21. TOOLS CONTROLLER
	// ============================================
	ToolsController::class => \DI\autowire(),
	ReportSocialHandler::class => \DI\autowire(),
	SocialReportParser::class => \DI\autowire(),
	SocialReportCalculator::class => \DI\autowire(),
	SocialReportWordGenerator::class => \DI\autowire(),

	// ============================================
	// 22. SPA CONTROLLERS
	// ============================================
	// [Лорелея]: PageController теперь получает PageRepository
	// через конструктор. PHP-DI сам разберётся по типу.
	// А PageRepository — уже зарегистрирован выше (п. 8.1).
	PageController::class => \DI\autowire(),
	PageEditorController::class => \DI\autowire(),
	SpaController::class => \DI\autowire(),
	SidebarController::class => \DI\autowire(),
	TopbarController::class => \DI\autowire(),
	MenuAdminController::class => \DI\autowire(),
];