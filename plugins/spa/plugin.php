<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА «SPA» — МОРДА САЙТА
 *
 * Объединяет боковое меню, верхнюю панель, контентные страницы,
 * SPA-навигацию, README и Mime в одном месте.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «файл с маршрутами». Это — двери, через
 * которые гость входит в дом, и от того, как они открываются,
 * зависит, увидит ли он то, что ему нужно.
 *
 * [Мириам]: И это правильно, потому что дверь, которая ведёт
 * не туда, хуже, чем стена: она обманывает надеждой.
 *
 * @package Jan\Trinity\Plugin\Spa
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Spa\MenuController;
use Jan\Trinity\Plugin\Spa\PageController;
use Jan\Trinity\Plugin\Spa\SpaController;
use Jan\Trinity\Plugin\Spa\ReadmeController;
use Jan\Trinity\Plugin\Spa\Mime\MimeController;

// ============================================
// ЗАРЕЗЕРВИРОВАННЫЕ SLUG
// ============================================
// [Лорелея]: Это список тех адресов, которые уже заняты
// другими маршрутами, и page_show не должен их перехватывать,
// потому что каждый должен знать своё место.
//
// [Мириам]: И это правильно, потому что порядок — это
// не «формальность», а карта, по которой гость находит дорогу.
const TRINITY_RESERVED_SLUGS = [
	// PageController — специфичные маршруты
	'sub',
	'section',
	'update-full',
	'subpage-by-id',
	'addSectionById',
	'addSubPage',
	'addSubPageById',
	'deleteSubPage',
	'deleteSection',
	'updateSection',
	'updateSectionFull',
	// ReadmeController
	'readme',
	// MimeController
	'mime',
	// MenuController
	'menu',
	// SpaController
	'spa',
	// Системные
	'admin',
	'api',
	'login',
	'logout',
	'register',
	'profile',
	'gallery',
	'map',
	'tools',
	'monitor',
	'guard',
];

$reservedSlugsPattern = '^(?!(' . implode('|', array_map(
	fn($slug) => preg_quote($slug, '/'),
	TRINITY_RESERVED_SLUGS
)) . ')$).+';

return function ($routes) use ($reservedSlugsPattern) {

	// ============================================
	// SPA — НАВИГАЦИЯ (ОДИН ENDPOINT)
	// ============================================
	// [Лорелея]: _csrf => false здесь не нужен, потому что Kernel
	// проверяет CSRF только для POST, PUT и DELETE, а этот маршрут
	// только для GET.
	$routes->add('spa_navigate', new Route('/api/spa/navigate', [
		'_controller' => SpaController::class,
		'_method'     => 'navigate',
	]));

	// ============================================
	// МЕНЮ
	// ============================================
	// [Лорелея]: Здесь мы отдаём боковое меню и верхнюю панель,
	// и это правильно, потому что меню — это карта дома.
	//
	// [Мириам]: И это правильно, потому что шапка — это лицо дома.
	$routes->add('menu_sidebar', new Route('/api/menu/sidebar', [
		'_controller' => MenuController::class,
		'_method'     => 'sidebar',
	]));

	$routes->add('menu_topbar', new Route('/api/menu/topbar', [
		'_controller' => MenuController::class,
		'_method'     => 'topbar',
	]));

	$routes->add('menu_add_item', new Route('/api/menu/add-item', [
		'_controller' => MenuController::class,
		'_method'     => 'addItem',
	], [], [], '', [], ['POST']));

	$routes->add('menu_update_item', new Route('/api/menu/update-item', [
		'_controller' => MenuController::class,
		'_method'     => 'updateItem',
	], [], [], '', [], ['POST']));

	// ============================================
	// СТРАНИЦЫ — СПЕЦИФИЧНЫЕ МАРШРУТЫ (ДОЛЖНЫ БЫТЬ ПЕРВЫМИ)
	// ============================================
	// [Лорелея]: Порядок важен: специфичные маршруты идут
	// до общих, потому что иначе общий перехватит их.
	//
	// [Мириам]: И это правильно, потому что точность должна
	// идти впереди обобщения.
	$routes->add('page_delete_sub', new Route('/api/page/sub/{id}', [
		'_controller' => PageController::class,
		'_method'     => 'deleteSubPage',
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	$routes->add('page_sub', new Route('/api/page/sub/{id}', [
		'_controller' => PageController::class,
		'_method'     => 'subPage',
	], ['id' => '\d+']));

	$routes->add('page_add_section', new Route('/api/page/{slug}/section', [
		'_controller' => PageController::class,
		'_method'     => 'addSection',
	], ['slug' => '.+'], [], '', [], ['POST']));

	$routes->add('page_add_subpage', new Route('/api/page/{slug}/subpage', [
		'_controller' => PageController::class,
		'_method'     => 'addSubPage',
	], ['slug' => '.+'], [], '', [], ['POST']));

	$routes->add('page_update_section_full', new Route('/api/page/section/{id}/update-full', [
		'_controller' => PageController::class,
		'_method'     => 'updateSectionFull',
	], ['id' => '\d+'], [], '', [], ['POST']));

	$routes->add('page_update_section', new Route('/api/page/section/{id}', [
		'_controller' => PageController::class,
		'_method'     => 'updateSection',
	], ['id' => '\d+'], [], '', [], ['POST']));

	$routes->add('page_delete_section', new Route('/api/page/section/{id}/delete', [
		'_controller' => PageController::class,
		'_method'     => 'deleteSection',
	], ['id' => '\d+'], [], '', [], ['POST']));

	$routes->add('page_add_section_by_id', new Route('/api/page/section/{pageId}/add', [
		'_controller' => PageController::class,
		'_method'     => 'addSectionById',
	], ['pageId' => '\d+'], [], '', [], ['POST']));

	$routes->add('page_add_subpage_by_id', new Route('/api/page/{pageId}/subpage-by-id', [
		'_controller' => PageController::class,
		'_method'     => 'addSubPageById',
	], ['pageId' => '\d+'], [], '', [], ['POST']));

	// [Лорелея]: Поиск по текстам, потому что дом должен уметь
	// отвечать на вопрос, даже если вопрос — это одно слово.
	$routes->add('spa_search', new Route('/api/search', [
		'_controller' => SpaController::class,
		'_method'     => 'search',
	]));

	// ============================================
	// ПРОСМОТР СТРАНИЦЫ — ДОЛЖЕН БЫТЬ ПОСЛЕДНИМ
	// ============================================
	// [Лорелея]: И это правильно, потому что общий маршрут
	// должен идти последним, чтобы не перехватить специфичные.
	//
	// [Мириам]: И это правильно, потому что обобщение без
	// точности — это хаос.
	$routes->add('page_show', new Route('/api/page/{slug}', [
		'_controller' => PageController::class,
		'_method'     => 'show',
	], ['slug' => $reservedSlugsPattern]));

	// ============================================
	// README — КАК СТРАНИЦА
	// ============================================
	// [Лорелея]: Здесь мы открываем дверь к README, и это
	// правильно, потому что README — это письмо, через которое
	// гость узнаёт, что это за дом.
	//
	// [Мириам]: И это правильно, потому что письмо, которое
	// некому прочитать, остаётся просто текстом на диске.
	$routes->add('readme_page', new Route('/readme', [
		'_controller' => ReadmeController::class,
		'_method'     => 'show',
	]));

	$routes->add('readme_api', new Route('/api/readme', [
		'_controller' => ReadmeController::class,
		'_method'     => 'show',
	]));

	// ============================================
	// MIME — ПОКАЗ НЕЙРОНОВ ПО MIME
	// ============================================
	// [Лорелея]: Здесь мы открываем дверь для всех mime,
	// и это правильно, потому что у одного дома может быть
	// много голосов.
	//
	// [Мириам]: И это правильно, потому что голос, который
	// некому услышать, остаётся просто звуком.
	$routes->add('mime_show', new Route('/mime/{mime}/{id}', [
		'_controller' => MimeController::class,
		'_method'     => 'show',
	], [
		'mime' => '[a-z0-9_/-]+',
		'id'   => '\d+',
	]));

	$routes->add('mime_api', new Route('/api/mime/{mime}/{id}', [
		'_controller' => MimeController::class,
		'_method'     => 'show',
	], [
		'mime' => '[a-z0-9_/-]+',
		'id'   => '\d+',
	]));
};