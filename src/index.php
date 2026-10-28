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
// [Лорелея]: Если TRUSTED_PROXIES пустой — не включаем X_FORWARDED_*,
// потому что доверие, выданное всем, — это не доверие, а его отсутствие.
//
// [Мириам]: И это правильно, потому что за CDN без настройки
// злоумышленник сможет спуфить IP через X-Forwarded-For.
$trustedProxies = $_ENV['TRUSTED_PROXIES'] ?? '';
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
// TRUSTED HOSTS
// ============================================
// [Лорелея]: Включаем setTrustedHosts, если в .env есть список,
// потому что заголовок Host тоже можно подделать, и это уже
// не «мелочь», а дыра.
//
// [Мириам]: И это правильно, потому что хост должен быть тем,
// кем он представился.
$trustedHosts = $_ENV['TRUSTED_HOSTS'] ?? '';
if (!empty($trustedHosts)) {
	$hosts = array_map('trim', explode(',', $trustedHosts));
	Request::setTrustedHosts($hosts);
} else {
	// [Лорелея]: Если список пуст — предупреждаем, но не падаем,
	// потому что на dev это нормально, а на prod — повод задуматься.
	error_log('[Trinity] TRUSTED_HOSTS is empty. Host header spoofing is possible.');
}

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