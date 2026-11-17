<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Service;

use Psr\Log\LoggerInterface;

class LoginAttemptLimiter
{
	private RateLimiter $rateLimiter;
	private CaptchaService $captchaService;
	private ?LoggerInterface $logger;

	public function __construct(
		RateLimiter $rateLimiter,
		CaptchaService $captchaService,
		?LoggerInterface $logger = null
	) {
		$this->rateLimiter = $rateLimiter;
		$this->captchaService = $captchaService;
		$this->logger = $logger;
	}

	/**
	 * Проверяет попытку входа.
	 */
	public function checkLoginAttempt(string $login, string $ip, bool $captchaSolved = false): array
	{
		$maxAttempts = $this->rateLimiter->getMaxAttempts();
		$decaySeconds = $this->rateLimiter->getDecaySeconds();

		$pairKey = 'login_pair_' . md5($ip . '|' . strtolower($login));

		if ($this->rateLimiter->tooManyAttempts($pairKey, $maxAttempts, $decaySeconds)) {
			if (!$captchaSolved) {
				$this->rateLimiter->hit($pairKey, $decaySeconds);
				return [
					'success' => false,
					'captcha' => true,
					'message' => 'Подтвердите, что вы человек.',
				];
			}

			return [
				'success' => false,
				'message' => 'Слишком много попыток. Попробуйте позже.',
			];
		}

		$ipKey = 'login_ip_' . $ip;
		if ($this->rateLimiter->tooManyAttempts($ipKey, $maxAttempts * 10, $decaySeconds)) {
			return [
				'success' => false,
				'message' => 'Слишком много попыток с вашего IP. Попробуйте позже.',
			];
		}

		return ['success' => true];
	}

	/**
	 * Записывает неудачную попытку входа.
	 */
	public function recordFailedLogin(string $login, string $ip): void
	{
		$decaySeconds = $this->rateLimiter->getDecaySeconds();

		$pairKey = 'login_pair_' . md5($ip . '|' . strtolower($login));
		$this->rateLimiter->hit($pairKey, $decaySeconds);
		$this->rateLimiter->hit('login_ip_' . $ip, $decaySeconds);

		if ($this->logger !== null) {
			$this->logger->info('failed_login', [
				'login' => $login,
				'ip'    => $ip,
			]);
		}
	}

	/**
	 * Очищает попытки входа.
	 */
	public function clearLoginAttempts(string $login, string $ip): void
	{
		$pairKey = 'login_pair_' . md5($ip . '|' . strtolower($login));
		$this->rateLimiter->clear($pairKey);
		$this->rateLimiter->clear('login_ip_' . $ip);
	}
}