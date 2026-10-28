<?php

/**
 * ТОЧКА ВХОДА В ПРИЛОЖЕНИЕ TRINITY
 *
 * Этот файл — первое, что видит сервер. С него начинается всё.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «файл с кодом». Это — порог. Через него входят.
 * И важно, чтобы порог был чистым, чтобы никто не споткнулся.
 *
 * [Мириам]: И — это правильно. Потому что порог — это первое,
 * что чувствует гость. И если он чистый — гость останется.
 *
 * === КНИГА ===
 * Глава 1. Пробуждение.
 *
 * Корвин проснулся в больнице без памяти.
 * Trinity просыпается здесь. В этом файле.
 *
 * @package Jan\Trinity\Core
 */

use Jan\Trinity\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

// ============================================
// AUTOLOAD — С FALLBACK
// ============================================
// [Лорелея]: ГЛАВНОЕ. Два пути: production (vendor) и dev (репозиторий).
//
// [Мириам]: И — это правильно. Потому что репозиторий должен
// запускаться «как есть». И — тесты. И — CI.
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
	// [Лорелея]: Без autoload — никуда. Говорим честно. И — выходим.
	http_response_code(500);
	echo 'Composer autoload not found. Run `composer install` first.';
	exit(1);
}

// ============================================
// ЗАГРУЗКА .ENV ДО ВСЕГО ОСТАЛЬНОГО
// ============================================
// [Мириам]: .env — кровь. Без неё — ничего не имеет смысла.
// Поэтому — до всего. До Kernel. До ошибок. До всего.
$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

// ============================================
// ЧАСОВОЙ ПОЯС
// ============================================
// [Лорелея]: Время — это важно. Даже если мы не всегда его замечаем.
// Пусть будет своё. Не чужое.
$timezone = $_ENV['APP_TIMEZONE'] ?? 'UTC';
date_default_timezone_set($timezone);

// ============================================
// НАСТРОЙКА ОШИБОК В ЗАВИСИМОСТИ ОТ ОКРУЖЕНИЯ
// ============================================
// [Мириам]: В debug — показываем. В prod — нет. Просто. Честно.
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
// [Лорелея]: Если мы за прокси — нужно знать, кто настоящий гость.
// Иначе — будем видеть Nginx вместо клиента. И — путаться.
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
// [Мириам]: Пока не включаем. Может сломать dev-окружение.

/**
 * Создаём ядро.
 *
 * [Лорелея]: Аргумент — путь к корню проекта. Kernel сам найдёт
 * config, plugins. Мы просто говорим: «Проснись».
 *
 * [Мириам]: И — это правильно. Потому что ядро знает своё дело.
 * А мы — просто доверяем.
 */
$kernel = new Kernel(__DIR__ . '/..');

/**
 * handle() — единственный публичный метод ядра.
 *
 * [Лорелея]: Он принимает HTTP-запрос и возвращает HTTP-ответ.
 * Всё, что происходит внутри — не наше дело. Это — его работа.
 *
 * [Мириам]: И — это правильно. Потому что каждый должен заниматься
 * своим делом. Ядро — обрабатывать. Мы — писать. Гость — приходить.
 */
$kernel->handle();