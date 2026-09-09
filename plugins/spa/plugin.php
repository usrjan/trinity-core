<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА "SPA" — МОРДА САЙТА
 * =========================================
 * 
 * Объединяет:
 * - Боковое меню и верхнюю панель (MenuController)
 * - Контентные страницы и секции (PageController)
 * - SPA-навигацию без перезагрузки
 * 
 * Маршруты:
 * - /api/menu/*     — управление меню
 * - /api/page/*     — управление страницами и секциями
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Spa\MenuController;
use Jan\Trinity\Plugin\Spa\PageController;

return function ($routes) {

	// ============================================
	// МЕНЮ
	// ============================================

	// HTML бокового меню
	$routes->add('menu_sidebar', new Route('/api/menu/sidebar', [
		'_controller' => MenuController::class,
		'_method'     => 'sidebar',
	]));

	// HTML верхней панели
	$routes->add('menu_topbar', new Route('/api/menu/topbar', [
		'_controller' => MenuController::class,
		'_method'     => 'topbar',
	]));

	// Добавление пункта меню
	$routes->add('menu_add_item', new Route('/api/menu/add-item', [
		'_controller' => MenuController::class,
		'_method'     => 'addItem',
	], [], [], '', [], ['POST']));

	// Обновление пункта меню
	$routes->add('menu_update_item', new Route('/api/menu/update-item', [
		'_controller' => MenuController::class,
		'_method'     => 'updateItem',
	], [], [], '', [], ['POST']));

	// ============================================
	// СТРАНИЦЫ
	// ============================================

	$routes->add('page_delete_sub', new Route('/api/page/sub/{id}', [
		'_controller' => PageController::class,
		'_method'     => 'deleteSubPage',
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	// Подстраница по ID
	$routes->add('page_sub', new Route('/api/page/sub/{id}', [
		'_controller' => PageController::class,
		'_method'     => 'subPage',
	], ['id' => '\d+']));

	// Тестовый метод
	$routes->add('test_page', new Route('/api/page/test', [
		'_controller' => PageController::class,
		'_method'     => 'test',
	]));

	// Добавление секции на страницу
	$routes->add('page_add_section', new Route('/api/page/{slug}/section', [
		'_controller' => PageController::class,
		'_method'     => 'addSection',
	], ['slug' => '.+'], [], '', [], ['POST']));

	// Добавление подстраницы
	$routes->add('page_add_subpage', new Route('/api/page/{slug}/subpage', [
		'_controller' => PageController::class,
		'_method'     => 'addSubPage',
	], ['slug' => '.+'], [], '', [], ['POST']));

	// Обновление секции полностью (все тексты)
	$routes->add('page_update_section_full', new Route('/api/page/section/{id}/update-full', [
		'_controller' => PageController::class,
		'_method'     => 'updateSectionFull',
	], ['id' => '\d+'], [], '', [], ['POST']));

	// Обновление секции (старый метод)
	$routes->add('page_update_section', new Route('/api/page/section/{id}', [
		'_controller' => PageController::class,
		'_method'     => 'updateSection',
	], ['id' => '\d+'], [], '', [], ['POST']));

	// Удаление секции
	$routes->add('page_delete_section', new Route('/api/page/section/{id}/delete', [
		'_controller' => PageController::class,
		'_method'     => 'deleteSection',
	], ['id' => '\d+'], [], '', [], ['POST']));

	// Добавление секции по ID страницы
	$routes->add('page_add_section_by_id', new Route('/api/page/section/{pageId}/add', [
		'_controller' => PageController::class,
		'_method'     => 'addSectionById',
	], ['pageId' => '\d+'], [], '', [], ['POST']));

	// Добавление подстраницы по ID родителя
	$routes->add('page_add_subpage_by_id', new Route('/api/page/{pageId}/subpage-by-id', [
		'_controller' => PageController::class,
		'_method'     => 'addSubPageById',
	], ['pageId' => '\d+'], [], '', [], ['POST']));

	// Просмотр страницы (SPA) — ДОЛЖЕН БЫТЬ ПОСЛЕДНИМ
	$routes->add('page_show', new Route('/api/page/{slug}', [
		'_controller' => PageController::class,
		'_method'     => 'show',
	], ['slug' => '^(?!test|section).+']));
};
