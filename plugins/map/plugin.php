<?php

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Map\MapController;

return function ($routes) {
    $routes->add('map_page', new Route('/map', [
        '_controller' => MapController::class,
        '_method'     => 'index',
    ]));

    $routes->add('map_geojson', new Route('/api/map/geojson', [
        '_controller' => MapController::class,
        '_method'     => 'geoJson',
    ]));

    $routes->add('map_info', new Route('/api/map/info/{id}', [
        '_controller' => MapController::class,
        '_method'     => 'info',
    ], ['id' => '\d+']));

};
