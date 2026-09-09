<?php

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Gallery\GalleryController;

return function ($routes) {
    // Страница галереи
    $routes->add('gallery_page', new Route('/gallery', [
        '_controller' => GalleryController::class,
        '_method'     => 'index',
    ]));

    // API: обновление item (должен быть до общего /api/gallery/item/{id})
    $routes->add('gallery_update_item', new Route('/api/gallery/item/{id}/update', [
        '_controller' => GalleryController::class,
        '_method'     => 'updateItem',
    ], ['id' => '\d+'], [], '', [], ['POST']));

    // API: загрузка фото в item
    $routes->add('gallery_upload_to_item', new Route('/api/gallery/item/{id}/upload', [
        '_controller' => GalleryController::class,
        '_method'     => 'uploadToItem',
    ], ['id' => '\d+'], [], '', [], ['POST']));

    $routes->add('gallery_delete_file', new Route('/api/gallery/file/{id}', [
        '_controller' => GalleryController::class,
        '_method'     => 'deleteFile',
    ], ['id' => '\d+'], [], '', [], ['DELETE']));

    $routes->add('gallery_delete_item', new Route('/api/gallery/item/{id}', [
        '_controller' => GalleryController::class,
        '_method'     => 'deleteItem',
    ], ['id' => '\d+'], [], '', [], ['DELETE']));

    // API: информация об item
    $routes->add('gallery_item', new Route('/api/gallery/item/{id}', [
        '_controller' => GalleryController::class,
        '_method'     => 'item',
    ], ['id' => '\d+']));

    // API: содержимое раздела
    $routes->add('gallery_section', new Route('/api/gallery/section/{id}', [
        '_controller' => GalleryController::class,
        '_method'     => 'section',
    ], ['id' => '\d+']));

    $routes->add('gallery_set_thumb', new Route('/api/gallery/section/{id}/set-thumb', [
        '_controller' => GalleryController::class,
        '_method'     => 'setSectionThumb',
    ], ['id' => '\d+'], [], '', [], ['POST']));

    $routes->add('gallery_create_section', new Route('/api/gallery/section/create', [
        '_controller' => GalleryController::class,
        '_method'     => 'createSection',
    ], [], [], '', [], ['POST']));

    // API: скачивание
    $routes->add('gallery_download', new Route('/api/gallery/download/{id}', [
        '_controller' => GalleryController::class,
        '_method'     => 'download',
    ], ['id' => '\d+']));

    // API: загрузка в раздел
    $routes->add('gallery_upload', new Route('/api/gallery/upload', [
        '_controller' => GalleryController::class,
        '_method'     => 'upload',
    ], [], [], '', [], ['POST']));

    // API: импорт из папки
    $routes->add('gallery_import', new Route('/api/gallery/import', [
        '_controller' => GalleryController::class,
        '_method'     => 'import',
    ], [], [], '', [], ['POST']));
};
