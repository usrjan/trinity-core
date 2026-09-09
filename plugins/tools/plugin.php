<?php

use Symfony\Component\Routing\Route;
use Jan\Trinity\Plugin\Tools\ToolsController;

return function ($routes) {
    $routes->add('tools_page', new Route('/tools', [
        '_controller' => ToolsController::class,
        '_method'     => 'index',
    ]));

    $routes->add('tools_report_social', new Route('/api/tools/report-social', [
        '_controller' => ToolsController::class,
        '_method'     => 'reportSocial',
    ], [], [], '', [], ['POST']));
};
