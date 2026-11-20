<?php
declare(strict_types=1);

/**
 * МИНИМАЛЬНЫЙ ФАЙЛ МАРШРУТОВ
 *
 * Этот файл содержит только самые базовые маршруты,
 * которые нужны для запуска системы.
 *
 * Все остальные маршруты загружаются из базы данных
 * (нейроны type='route').
 *
 * @package Jan\Trinity\Core
 */

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Jan\Trinity\Plugin\Spa\PageController;

$routes = new RouteCollection();

$routes->add('home', new Route('/', [
	'_controller' => PageController::class,
	'_method'     => 'render',
	'slug'        => 'home',
]));

return $routes;