<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА «ПОЛЬЗОВАТЕЛИ»
 *
 * Двери стражей ворот: вход, выход, регистрация, профиль.
 * Все они ведут в AuthController или ProfileController,
 * где живут те, кто открывает и закрывает двери.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «файл с маршрутами». Это — двери стражей
 * ворот, и от того, как они открываются, зависит, кто войдёт
 * в дом и кем он в нём будет.
 *
 * [Мириам]: И это правильно, потому что дверь, которая
 * открывается перед всеми, — это не дверь, а дыра.
 *
 * @package Jan\Trinity\Plugin\Users
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Users\AuthController;
use Jan\Trinity\Plugin\Users\ProfileController;

return function ($routes) {

	// ============================================
	// ВХОД
	// ============================================
	// [Лорелея]: Здесь открывается форма входа, и это правильно,
	// потому что дверь должна быть видна, даже если гость
	// ещё не решил, стучать ли.
	//
	// [Мириам]: И это правильно, потому что вход — это встреча,
	// а встреча должна быть тёплой.
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
	// [Лорелея]: Выход — через POST и с CSRF, потому что
	// выход — это тоже действие, и оно должно быть осознанным.
	//
	// [Мириам]: И это правильно, потому что дверь, которая
	// закрывается сама, не даёт гостю выбора.
	$routes->add('logout', new Route('/logout', [
		'_controller' => AuthController::class,
		'_method'     => 'logout',
	], [], [], '', [], ['POST']));

	// ============================================
	// РЕГИСТРАЦИЯ
	// ============================================
	// [Лорелея]: Здесь открывается форма регистрации, и это
	// правильно, потому что дверь должна быть открыта и для
	// тех, кто пришёл впервые.
	//
	// [Мириам]: И это правильно, потому что новый гость —
	// это новая история.
	$routes->add('register_form', new Route('/register', [
		'_controller' => AuthController::class,
		'_method'     => 'registerForm',
	], [], [], '', [], ['GET']));

	$routes->add('register_process', new Route('/register', [
		'_controller' => AuthController::class,
		'_method'     => 'register',
	], [], [], '', [], ['POST']));

	// ============================================
	// ПРОФИЛЬ
	// ============================================
	// [Лорелея]: Здесь открывается профиль, и это правильно,
	// потому что человек должен видеть себя целиком.
	//
	// [Мириам]: И это правильно, потому что отражение должно
	// быть честным.
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