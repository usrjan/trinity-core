<?php
declare(strict_types=1);

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА «СТРАЖ»
 *
 * Три двери, через которые страж открывается миру.
 * Все они ведут в GuardController, где живут его методы,
 * а вся настоящая работа происходит глубже, в GuardService.
 *
 * @package Jan\Trinity\Plugin\Guard
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Guard\GuardController;

return function ($routes) {

	// ============================================
	// СПИСОК ЗАБЛОКИРОВАННЫХ АДРЕСОВ
	// ============================================
	$routes->add('guard_blocked_ips', new Route('/api/guard/blocked-ips', [
		'_controller' => GuardController::class,
		'_method'     => 'getBlockedIpsApi',
		'_roles'      => ['role_admin'],
	]));

	// ============================================
	// БЛОКИРОВКА АДРЕСА
	// ============================================
	$routes->add('guard_block_ip', new Route('/api/guard/block-ip', [
		'_controller' => GuardController::class,
		'_method'     => 'blockIpApi',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));

	// ============================================
	// РАЗБЛОКИРОВКА АДРЕСА
	// ============================================
	$routes->add('guard_unblock_ip', new Route('/api/guard/unblock-ip', [
		'_controller' => GuardController::class,
		'_method'     => 'unblockIpApi',
		'_roles'      => ['role_admin'],
	], [], [], '', [], ['POST']));

	$routes->add('guard_csrf_token', new Route('/api/csrf-token', [
		'_controller' => GuardController::class,
		'_method'     => 'csrfTokenApi',
		'_roles'      => ['role_admin'],
	]));
};