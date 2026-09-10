<?php

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Admin\AdminController;

return function ($routes) {
	$routes->add('admin_page', new Route('/admin', [
		'_controller' => AdminController::class,
		'_method'     => 'index',
	]));

	$routes->add('admin_tree', new Route('/api/admin/tree', [
		'_controller' => AdminController::class,
		'_method'     => 'tree',
	]));

	$routes->add('admin_dashboard', new Route('/api/admin/dashboard', [
		'_controller' => AdminController::class,
		'_method'     => 'dashboard',
	]));

	$routes->add('admin_children', new Route('/api/admin/children/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'children',
	], ['id' => '\d+']));

	$routes->add('admin_neuron_delete', new Route('/api/admin/neuron/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'delete',
	], ['id' => '\d+'], [], '', [], ['DELETE']));

	$routes->add('admin_neuron_get', new Route('/api/admin/neuron/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'get',
	], ['id' => '\d+'], [], '', [], ['GET']));

	$routes->add('admin_neuron_update', new Route('/api/admin/neuron/{id}', [
		'_controller' => AdminController::class,
		'_method'     => 'update',
	], ['id' => '\d+'], [], '', [], ['POST']));

	$routes->add('admin_neuron_create', new Route('/api/admin/neuron', [
		'_controller' => AdminController::class,
		'_method'     => 'create',
	], [], [], '', [], ['POST']));

	$routes->add('admin_neuron_types', new Route('/api/admin/neuron-types', [
		'_controller' => AdminController::class,
		'_method'     => 'getTypes',
	]));

	$routes->add('admin_import', new Route('/api/admin/import', [
		'_controller' => AdminController::class,
		'_method'     => 'import',
	], [], [], '', [], ['POST']));

	$routes->add('admin_import_status', new Route('/api/admin/import-status/{jobId}', [
		'_controller' => AdminController::class,
		'_method'     => 'importStatus',
	], ['jobId' => '\\d+'], [], '', [], ['GET']));
};
