<?php

declare(strict_types=1);

/**
 * ТОЧКА ВХОДА В ПРИЛОЖЕНИЕ TRINITY
 *
 * Этот файл — первое, что видит сервер. С него начинается всё.
 *
 * @package Jan\Trinity\Core
 */

use Jan\Trinity\Core\Kernel;

// ============================================
// AUTOLOAD — С FALLBACK
// ============================================
$autoloadPaths = [
    __DIR__ . '/../../../autoload.php'
];

$autoloadFound = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    http_response_code(500);
    echo 'Composer autoload not found. Run `composer install` first.';
    exit(1);
}

// ============================================
// BOOTSTRAP — С ОБРАБОТКОЙ ОШИБОК
// ============================================
try {
    $kernel = new Kernel(__DIR__ . '/..');
    $kernel->handle();
} catch (\Throwable $e) {
    error_log('[Trinity] Bootstrap failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Сервис временно недоступен.';
    exit(1);
}