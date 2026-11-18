<?php
declare(strict_types=1);

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА «ПОЛЬЗОВАТЕЛИ»
 *
 * Двери стражей ворот: вход, выход, регистрация, профиль.
 * Все они ведут в AuthController или ProfileController,
 * где живут те, кто открывает и закрывает двери.
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Users\AuthController;
use Jan\Trinity\Plugin\Users\RegisterController;
use Jan\Trinity\Plugin\Users\ProfileController;
use Jan\Trinity\Plugin\Users\ProfileEditController;

return function ($routes) {

	// ============================================
	// ВХОД
	// ============================================
	$routes->add('login_form', new Route('/login', [
		'_controller' => AuthController::class,
		'_method'     => 'loginForm',
	], [], [], '', [], ['GET']));

	$routes->add('login_process', new Route('/login', [
		'_controller' => AuthController::class,
		'_method'     => 'login',
	], [], [], '', [], ['POST']));

	// ============================================
	// ВЫХОД
	// ============================================
	$routes->add('logout', new Route('/logout', [
		'_controller' => AuthController::class,
		'_method'     => 'logout',
	], [], [], '', [], ['POST']));

	// ============================================
	// РЕГИСТРАЦИЯ
	// ============================================
	$routes->add('register_form', new Route('/register', [
		'_controller' => RegisterController::class,
		'_method'     => 'registerForm',
	], [], [], '', [], ['GET']));

	$routes->add('register_process', new Route('/register', [
		'_controller' => RegisterController::class,
		'_method'     => 'register',
	], [], [], '', [], ['POST']));

	// ============================================
	// ПРОФИЛЬ
	// ============================================
	$routes->add('profile_view', new Route('/profile', [
		'_controller' => ProfileController::class,
		'_method'     => 'view',
	]));

	$routes->add('profile_edit_form', new Route('/profile/edit', [
		'_controller' => ProfileEditController::class,
		'_method'     => 'editForm',
	], [], [], '', [], ['GET']));

	$routes->add('profile_edit', new Route('/profile/edit', [
		'_controller' => ProfileEditController::class,
		'_method'     => 'edit',
	], [], [], '', [], ['POST']));

	$routes->add('profile_api', new Route('/api/profile', [
		'_controller' => ProfileController::class,
		'_method'     => 'apiView',
	]));
};