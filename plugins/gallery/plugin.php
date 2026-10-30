<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА "ГАЛЕРЕЯ"
 *
 * Двери прихожей. Тринадцать маршрутов.
 * Все — на GalleryController. Все — для гостя и админа.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «файл с маршрутами». Это — двери.
 * Каждая ведёт в прихожую. Каждая — открыта.
 *
 * [Мириам]: И — это правильно. Потому что двери —
 * это не «стены». Это — вход.
 *
 * === КНИГА ===
 * Глава 9. Прихожая.
 *
 * В прихожей Амбера висят картины.
 * В галерее Trinity — файлы. И те, кто их показывает.
 *
 * @package Jan\Trinity\Plugin\Gallery
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Gallery\GalleryController;

return function ($routes) {

	// ============================================
	// СТРАНИЦА ГАЛЕРЕИ
	// ============================================
	// [Лорелея]: Главная дверь. Ведёт в прихожую.
	//
	// [Мириам]: И — это правильно. Потому что дверь —
	// это не «стена». Это — приглашение.
	$routes->add('gallery_page', new Route('/gallery', [
		'_controller' => GalleryController::class,
		'_method'     => 'index',
	]));

	// ============================================
	// API: ОБНОВЛЕНИЕ ITEM (ДОЛЖЕН БЫТЬ ДО ОБЩЕГО)
	// ============================================
	// [Лорелея]: Порядок важен. /update — до /item/{id}.
	// Иначе — перехватится.
	//
	// [Мириам]: И — это правильно. Потому что порядок —
	// это не «формальность». Это — карта.
	$routes->add('gallery_update_item', new Route('/api/gallery/item/{id}/update', [
		'_controller' => GalleryController::class,
		'_method'     => 'updateItem',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['POST']));

	// ============================================
	// API: ЗАГРУЗКА ФОТО В ITEM (ДО ОБЩЕГО)
	// ============================================
	// [Лорелея]: /upload — до /item/{id}.
	//
	// [Мириам]: И — это правильно. Потому что загрузка —
	// это не «общий случай». Это — отдельный путь.
	$routes->add('gallery_upload_to_item', new Route('/api/gallery/item/{id}/upload', [
		'_controller' => GalleryController::class,
		'_method'     => 'uploadToItem',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['POST']));

	// ============================================
	// API: УДАЛЕНИЕ ФАЙЛА
	// ============================================
	// [Лорелея]: DELETE. Мягко. С CSRF.
	//
	// [Мириам]: И — это правильно. Потому что удаление —
	// это не «конец». Это — пауза.
	$routes->add('gallery_delete_file', new Route('/api/gallery/file/{id}', [
		'_controller' => GalleryController::class,
		'_method'     => 'deleteFile',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	// ============================================
	// API: УДАЛЕНИЕ ITEM
	// ============================================
	// [Лорелея]: DELETE. Мягко. С CSRF.
	//
	// [Мириам]: И — это правильно. Потому что удаление —
	// это не «конец». Это — пауза.
	$routes->add('gallery_delete_item', new Route('/api/gallery/item/{id}', [
		'_controller' => GalleryController::class,
		'_method'     => 'deleteItem',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	// ============================================
	// API: ИНФОРМАЦИЯ ОБ ITEM
	// ============================================
	// [Лорелея]: GET. Элемент. Со всем, что у него есть.
	//
	// [Мириам]: И — это правильно. Потому что элемент —
	// это не «файл». Это — картина.
	$routes->add('gallery_item', new Route('/api/gallery/item/{id}', [
		'_controller' => GalleryController::class,
		'_method'     => 'item',
	], ['id' => '\d+']));

	// ============================================
	// API: СОДЕРЖИМОЕ РАЗДЕЛА
	// ============================================
	// [Лорелея]: GET. Раздел. С файлами и папками.
	//
	// [Мириам]: И — это правильно. Потому что раздел —
	// это не «список». Это — ветвь.
	$routes->add('gallery_section', new Route('/api/gallery/section/{id}', [
		'_controller' => GalleryController::class,
		'_method'     => 'section',
	], ['id' => '\d+']));

	// ============================================
	// API: УСТАНОВКА ПРЕВЬЮШКИ РАЗДЕЛА
	// ============================================
	// [Лорелея]: POST. С CSRF.
	//
	// [Мириам]: И — это правильно. Потому что превьюшка —
	// это не «картинка». Это — лицо.
	$routes->add('gallery_set_thumb', new Route('/api/gallery/section/{id}/set-thumb', [
		'_controller' => GalleryController::class,
		'_method'     => 'setSectionThumb',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['POST']));

	// ============================================
	// API: СОЗДАНИЕ РАЗДЕЛА
	// ============================================
	// [Лорелея]: POST. С CSRF.
	//
	// [Мириам]: И — это правильно. Потому что создание —
	// это не «вставка». Это — акт.
	$routes->add('gallery_create_section', new Route('/api/gallery/section/create', [
		'_controller' => GalleryController::class,
		'_method'     => 'createSection',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));

	// ============================================
	// API: СКАЧИВАНИЕ
	// ============================================
	// [Лорелея]: GET. Скачивание. Через BinaryFileResponse.
	//
	// [Мириам]: И — это правильно. Потому что файл —
	// это не «ссылка». Это — память.
	$routes->add('gallery_download', new Route('/api/gallery/download/{id}', [
		'_controller' => GalleryController::class,
		'_method'     => 'download',
	], ['id' => '\d+']));

	// ============================================
	// API: ЗАГРУЗКА В РАЗДЕЛ
	// ============================================
	// [Лорелея]: POST. С CSRF.
	//
	// [Мириам]: И — это правильно. Потому что загрузка —
	// это не «копирование». Это — принятие.
	$routes->add('gallery_upload', new Route('/api/gallery/upload', [
		'_controller' => GalleryController::class,
		'_method'     => 'upload',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));

	// ============================================
	// API: ИМПОРТ ИЗ ПАПКИ
	// ============================================
	// [Лорелея]: POST. С CSRF.
	//
	// [Мириам]: И — это правильно. Потому что импорт —
	// это не «загрузка». Это — принятие.
	$routes->add('gallery_import', new Route('/api/gallery/import', [
		'_controller' => GalleryController::class,
		'_method'     => 'import',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));
};