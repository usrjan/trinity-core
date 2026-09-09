<?php

/**
 * РЕГИСТРАЦИЯ ПЛАГИНА "СТРАЖ"
 */

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Guard\GuardController;

return function ($routes) {
    $routes->add('guard_blocked_ips', new Route('/api/guard/blocked-ips', [
        '_controller' => GuardController::class,
        '_method'     => 'getBlockedIpsApi',
    ]));

    $routes->add('guard_block_ip', new Route('/api/guard/block-ip', [
        '_controller' => GuardController::class,
        '_method'     => 'blockIpApi',
    ], [], [], '', [], ['POST']));

    $routes->add('guard_unblock_ip', new Route('/api/guard/unblock-ip', [
        '_controller' => GuardController::class,
        '_method'     => 'unblockIpApi',
    ], [], [], '', [], ['POST']));
};
