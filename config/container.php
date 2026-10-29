<?php

/**
 * DI-КОНТЕЙНЕР
 *
 * Здесь описаны правила создания всех объектов системы.
 * PHP-DI автоматически внедряет зависимости.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «конфиг». Это — граф. Тот, кто знает,
 * как собрать всё. От базы до контроллера. От кэша до логгера.
 *
 * [Мириам]: И — это правильно. Потому что граф — это
 * не «список». Это — связи. И если связи есть — дом стоит.
 *
 * === КНИГА ===
 * Глава 7. Граф.
 *
 * В Амбере всё связано. Каждая Тень. Каждый путь.
 * В Trinity всё связано через контейнер.
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
	// [Лорелея]: Если .env забыт — приложение должно падать,
	// а не пытаться подключиться под root без пароля.
	// Fail-fast лучше, чем fail-open.
	//
	// [Мириам]: И это правильно, потому что отсутствие .env —
	// это не «настройка по умолчанию», а «ошибка конфигурации».
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
	// 2. СЕРВИС БАЗЫ ДАННЫХ (синглтон)
	// ============================================
	// [Лорелея]: Один. На всех. Без «пиздеца».
	// [Мириам]: И — это правильно. Потому что база — одна.
	DatabaseService::class => \DI\autowire()
		->constructorParameter('config', \DI\get('db.config')),

	// ============================================
	// 3. КЭШ (APCu)
	// ============================================
	// [Лорелея]: Если включён — работает. Если нет — нет.
	// [Мириам]: И — это правильно. Потому что кэш — это ускорение.
	CacheInterface::class => function () {
		$enabled = ($_ENV['CACHE_ENABLED'] ?? 'true') === 'true';

		if (!$enabled) {
			return new NullCache();
		}

		return new Cache($enabled);
	},

	// ============================================
	// 4. СЕССИЯ
	// ============================================
	// [Лорелея]: Сессия не стартует в конструкторе. Только — создаём.
	// start() будет вызван в Kernel, когда нужно. Или — в GuardService.
	//
	// [Мириам]: И — это правильно. Потому что сессия — это
	// не «всегда». Это — когда нужно.
	Session::class => function () {
		// [Лорелея]: По умолчанию cookie_secure выключен, потому что
		// на локальном http сессия не ставится вообще, и это ловушка
		// для дев-окружения. В prod его нужно включить явно.
		$isSecure = ($_ENV['SESSION_SECURE'] ?? 'false') === 'true';

		// [Мириам]: Но если сайт на https, а cookie_secure выключен —
		// это предупреждение, потому что безопасность должна быть
		// явной, а не случайной.
		$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| ($_SERVER['SERVER_PORT'] ?? null) === '443';

		if ($isHttps && !$isSecure) {
			error_log('[Trinity] SESSION_SECURE is false, but the site is on HTTPS. Enable it in .env.');
		}

		$storage = new NativeSessionStorage([
			'cookie_lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 86400),
			'cookie_secure'   => $isSecure,
			'cookie_httponly' => true,
			'cookie_samesite' => 'lax',
		]);

		return new Session($storage);
	},

	// ============================================
	// 5. ШАБЛОНИЗАТОР TWIG
	// ============================================
	// [Лорелея]: ГЛАВНОЕ. Environment получает Session.
	// И csrf_token читается из $session->get('csrf_token').
	// Один источник. Без $_SESSION. Без «двух источников».
	//
	// [Мириам]: И — это правильно. Потому что GuardService пишет
	// в $session->set('csrf_token'). А Twig — читает. Один источник.
	Environment::class => function (Session $session, \Jan\Trinity\Core\Service\GuardService $guard) {
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

		// [Лорелея]: Twig-функция csrf_token вызывает GuardService,
		// а не работает с сессией напрямую, потому что токен должен
		// быть в одном месте.
		$twig->addFunction(new \Twig\TwigFunction('csrf_token', function () use ($guard) {
			return $guard->getOrCreateCsrfToken();
		}));

		$twig->addGlobal('gallery_url', rtrim($_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery', '/'));

		return $twig;
	},

	// ============================================
	// 6. РЕПОЗИТОРИИ (БАЗА ДАННЫХ)
	// ============================================
	// [Лорелея]: Три руки. Три репозитория. Три способа трогать мир.
	//
	// [Мириам]: И — это правильно. Потому что руки — это
	// не «инструменты». Это — продолжение нас.
	TextRepository::class => \DI\autowire(),

	NeuronRepository::class => \DI\autowire()
		->constructorParameter('logger', \DI\get('logger.admin')),

	SynapseRepository::class => \DI\autowire(),

	// ============================================
	// 7. ПАРАМЕТРЫ ПРИЛОЖЕНИЯ
	// ============================================
	// [Лорелея]: Путь к корню. Дом.
	// [Мириам]: И — это правильно. Потому что дом должен
	// знать, где он стоит.
	'app.base_path' => dirname(__DIR__),

	// ============================================
	// 8. КОНТРОЛЛЕРЫ ПЛАГИНОВ
	// ============================================
	// [Лорелея]: ГЛАВНОЕ. MonitorController получает всё, что нужно.
	//
	// [Мириам]: И — это правильно. Потому что контроллер — это
	// не «класс». Это — рука.
	MonitorController::class => function (
		Environment $twig,
		Session $session,
		NeuronRepository $neuronRepo,
		GuardService $guard,
		SynapseRepository $synapseRepo,
		\Psr\Log\LoggerInterface $logger,
		ConfigService $configService,
		Validator $validator,
		Cache $cache
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
			$validator,
			$cache
		);
	},

	// [Лорелея]: GuardService получает Session. Чтобы
	// generateCsrfToken и validateCsrfToken работали с Symfony Session.
	// Без «двух источников».
	//
	// [Мириам]: И — это правильно. Потому что сессия — одна.
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
	// [Лорелея]: Validator получает NeuronRepository. Чтобы
	// exists и unique работали.
	//
	// [Мириам]: И — это правильно. Потому что проверка —
	// это не «догадка». Это — знание.
	Validator::class => \DI\autowire()
		->constructorParameter('neuronRepo', \DI\get(NeuronRepository::class)),

	// ============================================
	// 9. ГАЛЕРЕЯ — ПУТИ К ФАЙЛАМ
	// ============================================
	// [Лорелея]: Пути. Один раз.
	// [Мириам]: И — это правильно. Потому что путь — это
	// не «строка». Это — дорога.
	'gallery.upload_dir' => $_ENV['GALLERY_UPLOAD_DIR'] ?? (dirname(__DIR__) . '/../../../www/uploads/gallery'),
	'gallery.upload_url' => $_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery',

	// ============================================
	// 10. GALLERY CONTROLLER
	// ============================================
	// [Лорелея]: Получает пути. Чтобы знать, где файлы.
	// [Мириам]: И — это правильно. Потому что галерея —
	// это не «список». Это — память.
	GalleryController::class => \DI\autowire()
		->constructorParameter('galleryUploadDir', \DI\get('gallery.upload_dir'))
		->constructorParameter('galleryUploadUrl', \DI\get('gallery.upload_url')),

	// ============================================
	// 11. GALLERY IMPORT SERVICE
	// ============================================
	// [Лорелея]: Получает путь. Чтобы знать, откуда импортировать.
	// [Мириам]: И — это правильно. Потому что импорт — это
	// не «копирование». Это — принятие.
	GalleryImportService::class => \DI\autowire()
		->constructorParameter('galleryDir', \DI\get('gallery.upload_dir'))
		->constructorParameter('configService', \DI\get(ConfigService::class)),

	// ============================================
	// 12. ЛОГГЕРЫ (Monolog)
	// ============================================
	// [Лорелея]: Четыре канала. app, error, security, admin.
	// Каждый — со своим сроком.
	//
	// [Мириам]: И — это правильно. Потому что логи — это
	// не «мусор». Это — память.
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

		// [Лорелея]: FingersCrossedHandler держит записи в буфере
		// и записывает их только при ошибке, чтобы не открывать
		// файл на каждый запрос.
		$appHandler = new RotatingFileHandler($logDir . '/app.log', 7, Level::Info);
		$appHandler->setFormatter($formatter);

		$bufferHandler = new \Monolog\Handler\FingersCrossedHandler(
			$appHandler,
			Level::Error,
			0,
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
	// [Лорелея]: Портал. Один. Через интерфейс.
	// [Мириам]: И — это правильно. Потому что портал —
	// это не «класс». Это — дверь.
	ErrorHandlerInterface::class => \DI\get(MonitorController::class),

	// ============================================
	// CONFIG SERVICE
	// ============================================
	// [Лорелея]: Память. Один раз. На всех.
	// [Мириам]: И — это правильно. Потому что память —
	// это не «кэш». Это — знание.
	ConfigService::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path'))
		->constructorParameter('cache', \DI\get(Cache::class)),

	// ============================================
	// MIME REGISTRY
	// ============================================
	// [Лорелея]: Реестр. Знает все рендереры. Выбирает нужный.
	//
	// [Мириам]: И — это правильно. Потому что реестр —
	// это не «склад». Это — диспетчер.
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
	// [Лорелея]: Дверь. Одна. Для всех mime.
	// [Мириам]: И — это правильно. Потому что дверь —
	// это не «стена». Это — вход.
	MimeController::class => \DI\autowire(),

	// ============================================
	// README CONTROLLER
	// ============================================
	// [Лорелея]: Дверь к README. Через файл. Без базы.
	// [Мириам]: И — это правильно. Потому что README —
	// это письмо.
	ReadmeController::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),

	// ============================================
	// ADMIN CONTROLLER
	// ============================================
	// [Лорелея]: Тронный зал. Управление нейронами.
	// [Мириам]: И — это правильно. Потому что админка —
	// это не «место». Это — ответственность.
	AdminController::class => \DI\autowire()
		->constructorParameter('basePath', \DI\get('app.base_path')),
];