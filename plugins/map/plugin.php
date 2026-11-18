<?php
declare(strict_types=1);

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА «КАРТА»
 *
 * Три двери, через которые карта открывается миру.
 * Все они ведут в MapController, где живёт проводник,
 * а за ним — мир, который он показывает.
 *
 * @package Jan\Trinity\Plugin\Map
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Map\MapController;
use Jan\Trinity\Plugin\Map\MapGeoJsonController;

return function ($routes) {

	// ============================================
	// СТРАНИЦА КАРТЫ
	// ============================================
	$routes->add('map_page', new Route('/map', [
		'_controller' => MapController::class,
		'_method'     => 'index',
	]));

	// ============================================
	// GEOJSON API
	// ============================================
	$routes->add('map_geojson', new Route('/api/map/geojson', [
		'_controller' => MapGeoJsonController::class,
		'_method'     => 'geoJson',
	]));

	// ============================================
	// ИНФОРМАЦИЯ ОБ ОБЪЕКТЕ
	// ============================================
	$routes->add('map_info', new Route('/api/map/info/{id}', [
		'_controller' => MapInfoController::class,
		'_method'     => 'info',
	], ['id' => '\d+']));
};