<?php

declare(strict_types=1);

/**
 * ЗАГРУЗЧИК ОКРУЖЕНИЯ
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
			error_reporting(E_ALL & ~E_DEPRECATED);
		}
	}

	/**
	 * Настраивает доверенные прокси.
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
	 */
	private function configureTrustedHosts(): void
	{
		$trustedHosts = $_ENV['TRUSTED_HOSTS'] ?? '';
		$appEnv = $_ENV['APP_ENV'] ?? 'prod';

		if (!empty($trustedHosts)) {
			$hosts = array_map('trim', explode(',', $trustedHosts));
			\Symfony\Component\HttpFoundation\Request::setTrustedHosts($hosts);
			return;
		}

		if ($appEnv === 'prod') {
			$message = 'Trinity: TRUSTED_HOSTS is empty in production. Set it in .env.';
			error_log('[Trinity] ' . $message);
			throw new \RuntimeException($message);
		}

		error_log('[Trinity] TRUSTED_HOSTS is empty. Host header is not validated.');
	}
}