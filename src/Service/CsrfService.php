<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Service;

use Symfony\Component\HttpFoundation\Session\Session;

class CsrfService
{
	private Session $session;

	public function __construct(Session $session)
	{
		$this->session = $session;
	}

	/**
	 * Генерирует новый CSRF-токен.
	 */
	public function generateToken(): string
	{
		$token = bin2hex(random_bytes(32));

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$this->session->set('csrf_token', $token);

		return $token;
	}

	/**
	 * Проверяет CSRF-токен.
	 */
	public function validateToken(string $token): bool
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$storedToken = $this->session->get('csrf_token');

		if ($storedToken === null || $token === null) {
			return false;
		}

		return hash_equals($storedToken, $token);
	}

	/**
	 * Возвращает существующий CSRF-токен или создаёт новый.
	 */
	public function getOrCreateToken(): string
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$token = $this->session->get('csrf_token');

		if ($token === null || $token === '') {
			$token = bin2hex(random_bytes(32));
			$this->session->set('csrf_token', $token);
		}

		return $token;
	}
}