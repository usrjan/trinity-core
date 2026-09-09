<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА "МОНИТОР"
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Monitor\MonitorController;

return function ($routes) {
	$routes->add('monitor_js_error', new Route('/api/monitor/js-error', [
		'_controller' => MonitorController::class,
		'_method'     => 'collectJsError',
	], [], [], '', [], ['POST']));

	$routes->add('monitor_logs', new Route('/api/monitor/logs', [
		'_controller' => MonitorController::class,
		'_method'     => 'getLogs',
	]));

	$routes->add('monitor_clear_logs', new Route('/api/monitor/logs', [
		'_controller' => MonitorController::class,
		'_method'     => 'clearLogs',
	], [], [], '', [], ['DELETE']));
};
