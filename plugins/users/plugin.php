<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА "ПОЛЬЗОВАТЕЛИ"
 * 
 * Добавляет маршруты для входа, регистрации и выхода.
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Users\AuthController;
use Jan\Trinity\Plugin\Users\ProfileController;

return function ($routes) {
	$routes->add('login_form', new Route('/login', [
		'_controller' => AuthController::class,
		'_method'     => 'loginForm',
	], [], [], '', [], ['GET']));

	$routes->add('login_process', new Route('/login', [
		'_controller' => AuthController::class,
		'_method'     => 'login',
	], [], [], '', [], ['POST']));

	$routes->add('logout', new Route('/logout', [
		'_controller' => AuthController::class,
		'_method'     => 'logout',
	]));

	$routes->add('register_form', new Route('/register', [
		'_controller' => AuthController::class,
		'_method'     => 'registerForm',
	], [], [], '', [], ['GET']));

	$routes->add('register_process', new Route('/register', [
		'_controller' => AuthController::class,
		'_method'     => 'register',
	], [], [], '', [], ['POST']));

	$routes->add('profile_view', new Route('/profile', [
		'_controller' => ProfileController::class,
		'_method'     => 'view',
	]));

	$routes->add('profile_edit_form', new Route('/profile/edit', [
		'_controller' => ProfileController::class,
		'_method'     => 'editForm',
	], [], [], '', [], ['GET']));

	$routes->add('profile_edit', new Route('/profile/edit', [
		'_controller' => ProfileController::class,
		'_method'     => 'edit',
	], [], [], '', [], ['POST']));

	$routes->add('profile_api', new Route('/api/profile', [
		'_controller' => ProfileController::class,
		'_method'     => 'apiView',
	]));
};
