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
 */

use Jan\Trinity\Core\DatabaseService;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;

use Jan\Trinity\Core\Controller\HomeController;
use Jan\Trinity\Plugin\Menu\MenuController;
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
	// 3. КЭШ (отключаемый)
	// ============================================
	Cache::class => function () {
		$basePath = dirname(__DIR__);
		$enabled = ($_ENV['CACHE_ENABLED'] ?? 'false') === 'true';
		return new Cache($basePath, $enabled);
	},

	// ============================================
	// 4. СЕССИЯ
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

		// CSRF-токен как глобальная переменная
		$twig->addGlobal('csrf_token', $_SESSION['csrf_token'] ?? '');

		$twig->addGlobal('gallery_url', rtrim($_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery', '/'));

		return $twig;
	},

	// ============================================
	// 6. РЕПОЗИТОРИИ (БАЗА ДАННЫХ)
	// ============================================
	TextRepository::class => \DI\autowire(),
	NeuronRepository::class => \DI\autowire(),
	SynapseRepository::class => \DI\autowire(),

	// ============================================
	// 7. ПАРАМЕТРЫ ПРИЛОЖЕНИЯ
	// ============================================
	'app.base_path' => dirname(__DIR__),

	// ============================================
	// 8. КОНТРОЛЛЕРЫ ПЛАГИНОВ
	// ============================================
	MonitorController::class => function (Environment $twig, Session $session, NeuronRepository $neuronRepo) {
		return new \Jan\Trinity\Plugin\Monitor\MonitorController($twig, $session, dirname(__DIR__), $neuronRepo);
	},
	GuardController::class => function (NeuronRepository $neuronRepo) {
		return new \Jan\Trinity\Plugin\Guard\GuardController($neuronRepo, dirname(__DIR__));
	},
	AuthController::class => \DI\autowire(),
	HomeController::class => \DI\autowire(),
	AdminController::class => \DI\autowire(),
	MenuController::class => \DI\autowire(),
	ProfileController::class => \DI\autowire(),
	MapController::class => \DI\autowire(),
	ToolsController::class => \DI\autowire(),

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
	// Если переменные не заданы в .env — используем значения
	// по умолчанию, вычисленные от корня проекта. Это нужно
	// для разработки, когда .env ещё не заполнен.
	// ============================================
	'gallery.upload_dir' => $_ENV['GALLERY_UPLOAD_DIR'] ?? (dirname(__DIR__) . '/../../../www/uploads/gallery'),
	'gallery.upload_url' => $_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery',

	// ============================================
	// 10. GALLERY CONTROLLER
	// ============================================
	// Внедряем пути через конструктор.
	// galleryUploadDir — физический путь (для unlink, move, mkdir).
	// galleryUploadUrl — веб-путь (для передачи в Twig, если нужно).
	// ============================================
	GalleryController::class => \DI\autowire()
		->constructorParameter('galleryUploadDir', \DI\get('gallery.upload_dir'))
		->constructorParameter('galleryUploadUrl', \DI\get('gallery.upload_url')),

	// ============================================
	// 11. GALLERY IMPORT SERVICE
	// ============================================
	// Сервис импорта тоже нуждается в физическом пути.
	// Он не работает с URL — только с диском.
	// Регистрируем его в DI, чтобы GalleryController
	// получал готовый экземпляр, а не создавал вручную.
	// ============================================
	GalleryImportService::class => \DI\autowire()
		->constructorParameter('galleryDir', \DI\get('gallery.upload_dir')),
];