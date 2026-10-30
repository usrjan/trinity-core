<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА «АДМИН»
 *
 * Двери тронного зала. Все — для админа.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Каждый маршрут объявляет _roles, потому что защита
 * должна быть там, где объявлены маршруты, а не там, где их
 * обрабатывают.
 *
 * [Мириам]: И это правильно, потому что дверь должна быть закрыта
 * в одном месте, а не в пяти.
 *
 * @package Jan\Trinity\Plugin\Admin
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Admin\AdminController;

return function ($routes) {

	// ============================================
	// СТРАНИЦА АДМИНКИ
	// ============================================
	$routes->add('admin_page', new Route('/admin', [
		'_controller' => AdminController::class,
		'_method'     => 'index',
		'_roles'      => ['role_admin'],
	]));

	// ============================================
	// ДАШБОРД
	// ============================================
	$routes->add('admin_dashboard', new Route('/api/admin/dashboard', [
		'_controller' => AdminController::class,
		'_method'     => 'dashboard',
		'_roles'      => ['role_admin'],
	]));

	// ============================================
	// ДОЧЕРНИЕ НЕЙРОНЫ (ЛЕНИВАЯ ЗАГРУЗКА)
	// ============================================
	$routes->add('admin_children', new Route('/api/admin/children/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'children',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+']));

	// ============================================
	// ПРОСМОТР НЕЙРОНА
	// ============================================
	$routes->add('admin_neuron_get', new Route('/api/admin/neuron/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'get',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['GET']));

	// ============================================
	// СОЗДАНИЕ НЕЙРОНА
	// ============================================
	$routes->add('admin_neuron_create', new Route('/api/admin/neuron', [
		'_controller' => AdminController::class,
		'_method'     => 'create',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));

	// ============================================
	// ОБНОВЛЕНИЕ НЕЙРОНА
	// ============================================
	$routes->add('admin_neuron_update', new Route('/api/admin/neuron/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'update',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['POST']));

	// ============================================
	// УДАЛЕНИЕ НЕЙРОНА
	// ============================================
	$routes->add('admin_neuron_delete', new Route('/api/admin/neuron/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'delete',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	// ============================================
	// ТИПЫ НЕЙРОНОВ
	// ============================================
	$routes->add('admin_neuron_types', new Route('/api/admin/neuron-types', [
		'_controller' => AdminController::class,
		'_method'     => 'getTypes',
		'_roles'      => ['role_admin'],
	]));

	// ============================================
	// ОДОБРЕНИЕ ПОЛЬЗОВАТЕЛЯ
	// ============================================
	$routes->add('admin_user_approve', new Route('/api/admin/user/{id}/approve', [
		'_controller' => AdminController::class,
		'_method'     => 'approveUser',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['POST']));

	// ============================================
	// УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЯ
	// ============================================
	$routes->add('admin_user_delete', new Route('/api/admin/user/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'deleteUser',
		'_roles'      => ['role_admin'],
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	// ============================================
	// ИМПОРТ ИЗ EXCEL
	// ============================================
	$routes->add('admin_import', new Route('/api/admin/import', [
		'_controller' => AdminController::class,
		'_method'     => 'import',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));
};