<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА "SPA" — МОРДА САЙТА
 * =========================================
 *
 * Объединяет:
 * - Боковое меню и верхнюю панель (MenuController)
 * - Контентные страницы и секции (PageController)
 * - SPA-навигацию без перезагрузки (SpaController)
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Добавлен SpaController::navigate().
 * Один endpoint. Sidebar, topbar, page — в одном ответе.
 * Без «трёх round-trips». Без «пиздеца». Без «потом».
 *
 * [Мириам]: И — это правильно. Потому что SPA делает
 * параллельные fetch. А если сессия стартует на каждом —
 * они сериализуются. А это — задержка. Без «пиздеца».
 * И — «навсегда».
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
const TRINITY_RESERVED_SLUGS = [
	// PageController — специфичные маршруты
	'sub',
	'section',
	'add',
	'update',
	'delete',
	'update-full',
	'subpage-by-id',
	'addSection',
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
	// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Один fetch. Sidebar, topbar,
	// page — в одном ответе. Без «трёх round-trips». Без «пиздеца».
	// Без «потом». Без «завтра».
	//
	// [Мириам]: И — это правильно. Потому что SPA делает
	// параллельные fetch. А если сессия стартует на каждом —
	// они сериализуются. А это — задержка. Без «пиздеца».
	$routes->add('spa_navigate', new Route('/api/spa/navigate', [
		'_controller' => SpaController::class,
		'_method'     => 'navigate',
	]));

	// ============================================
	// МЕНЮ
	// ============================================

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

	// ============================================
	// ПРОСМОТР СТРАНИЦЫ — ДОЛЖЕН БЫТЬ ПОСЛЕДНИМ
	// ============================================
	$routes->add('page_show', new Route('/api/page/{slug}', [
		'_controller' => PageController::class,
		'_method'     => 'show',
	], ['slug' => $reservedSlugsPattern]));

	// ============================================
	// README — КАК СТРАНИЦА
	// ============================================

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