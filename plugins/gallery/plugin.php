<?php
declare(strict_types=1);

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА "ГАЛЕРЕЯ"
 *
 * Двери прихожей. Тринадцать маршрутов.
 * Все — на GalleryController. Все — для гостя и админа.
 *
 * @package Jan\Trinity\Plugin\Gallery
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Gallery\GalleryController;
use Jan\Trinity\Plugin\Gallery\GalleryDisplayController;

return function ($routes) {

	// ============================================
	// СТРАНИЦА ГАЛЕРЕИ
	// ============================================
	$routes->add('gallery_page', new Route('/gallery', [
		'_controller' => GalleryDisplayController::class,
		'_method'     => 'index',
	]));

	// ============================================
	// API: ОБНОВЛЕНИЕ ITEM (ДОЛЖЕН БЫТЬ ДО ОБЩЕГО)
	// ============================================
	$routes->add('gallery_update_item', new Route('/api/gallery/item/{id}/update', [
		'_controller' => GalleryController::class,
		'_method'     => 'updateItem',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['POST']));

	// ============================================
	// API: ЗАГРУЗКА ФОТО В ITEM (ДО ОБЩЕГО)
	// ============================================
	$routes->add('gallery_upload_to_item', new Route('/api/gallery/item/{id}/upload', [
		'_controller' => GalleryController::class,
		'_method'     => 'uploadToItem',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['POST']));

	// ============================================
	// API: УДАЛЕНИЕ ФАЙЛА
	// ============================================
	$routes->add('gallery_delete_file', new Route('/api/gallery/file/{id}', [
		'_controller' => GalleryController::class,
		'_method'     => 'deleteFile',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	// ============================================
	// API: УДАЛЕНИЕ ITEM
	// ============================================
	$routes->add('gallery_delete_item', new Route('/api/gallery/item/{id}', [
		'_controller' => GalleryController::class,
		'_method'     => 'deleteItem',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	// ============================================
	// API: ИНФОРМАЦИЯ ОБ ITEM
	// ============================================
	$routes->add('gallery_item', new Route('/api/gallery/item/{id}', [
		'_controller' => GalleryDisplayController::class,
		'_method'     => 'item',
	], ['id' => '\d+']));

	// ============================================
	// API: СОДЕРЖИМОЕ РАЗДЕЛА
	// ============================================
	$routes->add('gallery_section', new Route('/api/gallery/section/{id}', [
		'_controller' => GalleryDisplayController::class,
		'_method'     => 'section',
	], ['id' => '\d+']));

	// ============================================
	// API: УСТАНОВКА ПРЕВЬЮШКИ РАЗДЕЛА
	// ============================================
	$routes->add('gallery_set_thumb', new Route('/api/gallery/section/{id}/set-thumb', [
		'_controller' => GalleryController::class,
		'_method'     => 'setSectionThumb',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['POST']));

	// ============================================
	// API: СОЗДАНИЕ РАЗДЕЛА
	// ============================================
	$routes->add('gallery_create_section', new Route('/api/gallery/section/create', [
		'_controller' => GalleryController::class,
		'_method'     => 'createSection',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));

	// ============================================
	// API: СКАЧИВАНИЕ
	// ============================================
	$routes->add('gallery_download', new Route('/api/gallery/download/{id}', [
		'_controller' => GalleryController::class,
		'_method'     => 'download',
	], ['id' => '\d+']));

	// ============================================
	// API: ЗАГРУЗКА В РАЗДЕЛ
	// ============================================
	$routes->add('gallery_upload', new Route('/api/gallery/upload', [
		'_controller' => GalleryController::class,
		'_method'     => 'upload',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));

	// ============================================
	// API: ИМПОРТ ИЗ ПАПКИ
	// ============================================
	$routes->add('gallery_import', new Route('/api/gallery/import', [
		'_controller' => GalleryController::class,
		'_method'     => 'import',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));
};