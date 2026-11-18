<?php
declare(strict_types=1);

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА «ИНСТРУМЕНТЫ»
 *
 * Двери в мастерскую: страница инструментов и отчёт по соцсетям.
 * Обе ведут в ToolsController, где живёт мастер.
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Tools\ToolsController;

return function ($routes) {

	// ============================================
	// СТРАНИЦА ИНСТРУМЕНТОВ
	// ============================================
	$routes->add('tools_page', new Route('/tools', [
		'_controller' => ToolsController::class,
		'_method'     => 'index',
	]));

	// ============================================
	// ОТЧЁТ ПО СОЦСЕТЯМ
	// ============================================
	$routes->add('tools_report_social', new Route('/api/tools/report-social', [
		'_controller' => ToolsController::class,
		'_method'     => 'reportSocial',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));
};