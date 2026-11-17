<?php

declare(strict_types=1);

/**
 * ЗАГРУЗЧИК ОКРУЖЕНИЯ
 *
 * Читает .env, проверяет обязательные переменные и настраивает
 * базовые параметры PHP. Kernel больше не знает, откуда берутся
 * настройки — это работа загрузчика.
 *
 * [Лорелея]: Ядро — скучное. Загрузчик — знает, как разбудить дом.
 *
 * [Мириам]: И это правильно, потому что утро начинается не с ядра,
 * а с того, что дом просыпается.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\Dotenv\Dotenv;

class EnvironmentLoader
{
	private string $basePath;

	public function __construct(string $basePath)
	{
		$this->basePath = rtrim($basePath, '/');
	}

	/**
	 * Загружает окружение.
	 *
	 * [Лорелея]: Fail-fast, если чего-то не хватает. Дом без конфига —
	 * это не дом, а руины.
	 *
	 * [Мириам]: И это правильно, потому что лучше упасть на пороге,
	 * чем работать вслепую.
	 */
	public function load(): void
	{
		$dotenv = new Dotenv();
		$dotenv->loadEnv($this->basePath . '/.env');

		$this->validateRequired();
		$this->setTimezone();
		$this->configureErrors();
		$this->configureTrustedProxies();
		$this->configureTrustedHosts();
	}

	/**
	 * Проверяет обязательные переменные.
	 */
	private function validateRequired(): void
	{
		$required = ['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
		$missing = [];

		foreach ($required as $key) {
			if (empty($_ENV[$key])) {
				$missing[] = $key;
			}
		}

		if (!empty($missing)) {
			throw new \RuntimeException(
				'Trinity: missing required env variables: ' . implode(', ', $missing)
			);
		}
	}

	/**
	 * Устанавливает часовой пояс.
	 */
	private function setTimezone(): void
	{
		$timezone = $_ENV['APP_TIMEZONE'] ?? 'UTC';
		date_default_timezone_set($timezone);
	}

	/**
	 * Настраивает отображение ошибок в зависимости от окружения.
	 */
	private function configureErrors(): void
	{
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
	}

	/**
	 * Настраивает доверенные прокси.
	 *
	 * [Лорелея]: Если список пуст, не включаем X-Forwarded-*, потому что
	 * доверие, выданное всем, — это не доверие, а его отсутствие.
	 */
	private function configureTrustedProxies(): void
	{
		$trustedProxies = $_ENV['TRUSTED_PROXIES'] ?? '';
		if (!empty($trustedProxies)) {
			$proxies = array_map('trim', explode(',', $trustedProxies));
			\Symfony\Component\HttpFoundation\Request::setTrustedProxies(
				$proxies,
				\Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR
					| \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST
					| \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO
					| \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT
			);
		}
	}

	/**
	 * Настраивает доверенные хосты.
	 *
	 * [Мириам]: В prod — fail-fast, потому что без trusted hosts
	 * Symfony принимает любой Host, и это — cache poisoning.
	 */
	private function configureTrustedHosts(): void
	{
		$trustedHosts = $_ENV['TRUSTED_HOSTS'] ?? '';
		$appEnv = $_ENV['APP_ENV'] ?? 'prod';

		if (!empty($trustedHosts)) {
			$hosts = array_map('trim', explode(',', $trustedHosts));
			\Symfony\Component\HttpFoundation\Request::setTrustedHosts($hosts);
		} elseif ($appEnv === 'prod') {
			// [Лорелея]: Fail-fast. Если в prod нет trusted hosts —
			// дом не должен работать, потому что это дыра.
			throw new \RuntimeException(
				'Trinity: TRUSTED_HOSTS is empty in production. Set it in .env.'
			);
		}
	}
}