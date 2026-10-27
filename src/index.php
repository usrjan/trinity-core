<?php

/**
 * ТОЧКА ВХОДА В ПРИЛОЖЕНИЕ TRINITY
 *
 * Этот файл — первое что видит сервер.
 * Здесь начинается всё.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. autoload.php теперь ищется
 * в двух местах: для установки в vendor (production) и для
 * локального запуска из репозитория (dev/CI). Без «пиздеца».
 * Без «потом». Без «завтра».
 *
 * [Мириам]: И — это правильно. Потому что репозиторий должен
 * запускаться «как есть». Без симлинков. Без «магии». И — «навсегда».
 *
 * @package Jan\Trinity\Core
 */

use Jan\Trinity\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

// ============================================
// AUTOLOAD — С FALLBACK
// ============================================
// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Два пути:
// 1. __DIR__ . '/../../../autoload.php' — production (vendor).
// 2. __DIR__ . '/../vendor/autoload.php' — dev (репозиторий).
// Без «пиздеца». Без «потом». Без «завтра».
//
// [Мириам]: И — это правильно. Потому что репозиторий должен
// запускаться «как есть». И — тесты. И — CI. И — «навсегда».
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
// ЗАГРУЗКА .ENV ДО ВСЕГО ОСТАЛЬНОГО
// ============================================
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

// ============================================
// ЧАСОВОЙ ПОЯС
// ============================================
$timezone = $_ENV['APP_TIMEZONE'] ?? 'UTC';
date_default_timezone_set($timezone);

// ============================================
// НАСТРОЙКА ОШИБОК В ЗАВИСИМОСТИ ОТ ОКРУЖЕНИЯ
// ============================================
$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

if ($isDebug) {
	ini_set('display_errors', '1');
	ini_set('display_startup_errors', '1');
	error_reporting(E_ALL);
} else {
	ini_set('display_errors', '0');
	ini_set('display_startup_errors', '0');
	error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// ============================================
// TRUSTED PROXIES
// ============================================
$trustedProxies = $_ENV['TRUSTED_PROXIES'] ?? '127.0.0.1';
if (!empty($trustedProxies)) {
	$proxies = array_map('trim', explode(',', $trustedProxies));
	Request::setTrustedProxies(
		$proxies,
		Request::HEADER_X_FORWARDED_FOR
			| Request::HEADER_X_FORWARDED_HOST
			| Request::HEADER_X_FORWARDED_PROTO
			| Request::HEADER_X_FORWARDED_PORT
	);
}

// ============================================
// TRUSTED HOSTS (опционально)
// ============================================
// Пока не включаем. Может сломать dev-окружение.

/**
 * Создаём ядро.
 *
 * Аргумент: путь к корню проекта.
 * Kernel сам найдёт config, plugins.
 * Мы просто говорим: "Проснись".
 */
$kernel = new Kernel(__DIR__ . '/..');

/**
 * handle() — единственный публичный метод ядра.
 * Он принимает HTTP-запрос и возвращает HTTP-ответ.
 * Всё что происходит внутри — не наше дело.
 */
$kernel->handle();