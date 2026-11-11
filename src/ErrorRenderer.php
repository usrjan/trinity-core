<?php

declare(strict_types=1);

/**
 * РЕНДЕРЕР ОШИБОК
 *
 * Превращает исключение в ответ. Знает про debug, про безопасные
 * сообщения и про то, кому можно показывать стек.
 *
 * [Лорелея]: Kernel больше не знает, как выглядит ошибка.
 * Это — работа рендерера. Ядро — скучное.
 *
 * [Мириам]: И это правильно, потому что ошибка — это не «стыд»,
 * это информация. Но информация — для тех, кому можно.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ErrorRenderer
{
	private ErrorHandlerInterface $errorHandler;
	private ?LoggerInterface $logger;
	private bool $debug;

	public function __construct(
		ErrorHandlerInterface $errorHandler,
		bool $debug,
		?LoggerInterface $logger = null
	) {
		$this->errorHandler = $errorHandler;
		$this->debug = $debug;
		$this->logger = $logger;
	}

	/**
	 * Превращает исключение в ответ.
	 *
	 * [Лорелея]: Логируем оригинал всегда, до render. Если
	 * MonitorController упадёт — стек уже в логе.
	 *
	 * [Мириам]: И это правильно, потому что ошибка, которую
	 * никто не услышал, остаётся загадкой.
	 */
	public function render(\Throwable $e, int $code, Request $request): Response
	{
		$message = $this->safeErrorMessage($e, $code, $request);

		if ($this->logger !== null) {
			$logMethod = $code >= 500 ? 'error' : 'warning';
			$this->logger->$logMethod('unhandled exception', [
				'code'    => $code,
				'message' => $e->getMessage(),
				'file'    => $e->getFile() . ':' . $e->getLine(),
				'trace'   => $e->getTraceAsString(),
				'url'     => $_SERVER['REQUEST_URI'] ?? 'unknown',
				'method'  => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
				'ip'      => $request->getClientIp(),
			]);
		}

		try {
			$user = 'guest';
			$session = null;
			try {
				$session = $request->getSession();
			} catch (\Throwable $e) {
				// [Лорелея]: Session has not been set — это не ошибка.
				// Просто сессия не была создана. Оставляем guest.
			}

			if ($session !== null && $session->isStarted()) {
				$login = $session->get('user_login');
				if ($login) {
					$user = $login;
				}
			}

			return $this->errorHandler->showError($code, $message, [
				'file'             => $e->getFile() . ':' . $e->getLine(),
				'trace'            => $e->getTraceAsString(),
				'user'             => $user,
				'url'              => $_SERVER['REQUEST_URI'] ?? '/',
				'time'             => date('Y-m-d H:i:s'),
				'original_message' => $e->getMessage(),
			]);
		} catch (\Throwable $inner) {
			if ($this->logger !== null) {
				$this->logger->error('renderError failed', [
					'message' => $inner->getMessage(),
					'file'    => $inner->getFile() . ':' . $inner->getLine(),
				]);
			}

			return new Response($this->safeErrorMessage($e, $code, $request), $code);
		}
	}

	/**
	 * Безопасное сообщение для клиента.
	 *
	 * [Лорелея]: В prod — только «Внутренняя ошибка сервера».
	 * В debug — детали. Но — только для debug-заголовка / IP.
	 *
	 * [Мириам]: Гость — всегда «Внутренняя ошибка сервера».
	 * Даже в dev.
	 */
	public function safeErrorMessage(\Throwable $e, int $code, ?Request $request = null): string
	{
		if (!$this->debug || !$this->isDebugAllowed($request)) {
			return $this->defaultErrorMessage($code);
		}

		$message = $e->getMessage();

		$message = preg_replace('#(?:[A-Z]:)?/[^\s]+#', '[path]', $message);
		$message = preg_replace('/SQLSTATE\[[^\]]+\][^;]*/i', '[sql]', $message);
		$message = preg_replace('/\b[0-9a-f]{32,}\b/i', '[token]', $message);

		if (mb_strlen($message) > 500) {
			$message = mb_substr($message, 0, 500) . '…';
		}

		return $message ?: $this->defaultErrorMessage($code);
	}

	/**
	 * Сообщение по умолчанию для HTTP-кода.
	 */
	public function defaultErrorMessage(int $code): string
	{
		return match ($code) {
			401 => 'Требуется авторизация',
			403 => 'Доступ запрещён',
			404 => 'Страница не найдена',
			405 => 'Метод не поддерживается',
			419 => 'Недействительный CSRF-токен',
			429 => 'Слишком много запросов',
			503 => 'Сервис временно недоступен',
			default => 'Внутренняя ошибка сервера',
		};
	}

	/**
	 * Детали ошибки — только для debug-заголовка или debug-IP.
	 */
	private function isDebugAllowed(?Request $request): bool
	{
		if ($request === null) {
			return false;
		}

		$debugToken = $_ENV['DEBUG_TOKEN'] ?? '';
		if ($debugToken !== '' && $request->headers->get('X-Debug') === $debugToken) {
			return true;
		}

		$debugIp = $_ENV['DEBUG_IP'] ?? '';
		if ($debugIp !== '' && $request->getClientIp() === $debugIp) {
			return true;
		}

		return false;
	}
}