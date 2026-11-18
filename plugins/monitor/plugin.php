<?php

declare(strict_types=1);

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА «МОНИТОР»
 *
 * @package Jan\Trinity\Plugin\Monitor
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Monitor\JsErrorController;
use Jan\Trinity\Plugin\Monitor\LogsController;
use Jan\Trinity\Plugin\Monitor\CspReportController;

return function ($routes) {

	// ============================================
	// СБОР JS-ОШИБОК
	// ============================================
	$routes->add('monitor_js_error', new Route('/api/monitor/js-error', [
		'_controller' => JsErrorController::class,
		'_method'     => 'collect',
		'_csrf'       => false,
	], [], [], '', [], ['POST']));

	// ============================================
	// ПРОСМОТР ЛОГОВ
	// ============================================
	$routes->add('monitor_logs', new Route('/api/monitor/logs', [
		'_controller' => LogsController::class,
		'_method'     => 'getLogs',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['GET']));

	// ============================================
	// ОЧИСТКА ЛОГОВ
	// ============================================
	$routes->add('monitor_clear_logs', new Route('/api/monitor/logs', [
		'_controller' => LogsController::class,
		'_method'     => 'clearLogs',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['DELETE']));

	$routes->add('monitor_csp_report', new Route('/api/monitor/csp', [
		'_controller' => CspReportController::class,
		'_method'     => 'collect',
		'_csrf'       => false,
	], [], [], '', [], ['POST']));
};