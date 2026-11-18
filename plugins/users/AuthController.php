<?php

declare(strict_types=1);

/**
 * КОНТРОЛЛЕР АУТЕНТИФИКАЦИИ
 *
 * Управление входом, регистрацией и выходом пользователей.
 *
 * @package Jan\Trinity\Plugin\Users
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\CsrfService;
use Jan\Trinity\Core\Service\RateLimiter;
use Jan\Trinity\Core\Service\IpBlocker;
use Jan\Trinity\Core\Service\CaptchaService;
use Jan\Trinity\Core\Service\LoginAttemptLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class AuthController
{
	use AuthMiddleware;

	// ============================================
	// ПАРАМЕТРЫ ХЕША — ОДИН ИСТОЧНИК
	// ============================================
	private const PASSWORD_HASH_ALGO = PASSWORD_BCRYPT;
	private const PASSWORD_HASH_OPTIONS = ['cost' => 12];

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/**
	 * @var Session — сессия.
	 */

	/** @var NeuronRepository — поиск пользователей. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — получение ролей. */
	private SynapseRepository $synapseRepo;

	/** @var CsrfService — CSRF-защита. */
	private CsrfService $csrfService;

	/** @var RateLimiter — rate limiting. */
	private RateLimiter $rateLimiter;

	/** @var IpBlocker — блокировка IP. */
	private IpBlocker $ipBlocker;

	/** @var CaptchaService — капча. */
	private CaptchaService $captchaService;

	/** @var LoginAttemptLimiter — лимитер попыток входа. */
	private LoginAttemptLimiter $loginAttemptLimiter;

	private Validator $validator;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		RateLimiter $rateLimiter,
		IpBlocker $ipBlocker,
		CsrfService $csrfService,
		CaptchaService $captchaService,
		LoginAttemptLimiter $loginAttemptLimiter,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->rateLimiter = $rateLimiter;
		$this->ipBlocker = $ipBlocker;
		$this->csrfService = $csrfService;
		$this->captchaService = $captchaService;
		$this->loginAttemptLimiter = $loginAttemptLimiter;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// ФОРМА ВХОДА
	// ============================================

	/**
	 * GET /login
	 */
	public function loginForm(Request $request): Response
	{
		if ($this->isAuthenticated()) {
			return new RedirectResponse('/');
		}

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$error = $this->session->get('login_error');
		$this->session->remove('login_error');

		$captchaQuestion = $this->session->get('captcha_question', null);

		$redirect = $request->query->get('redirect', '');
		if (!$this->isLocalPath($redirect)) {
			$redirect = '';
		}

		$html = $this->twig->render('login.html.twig', [
			'error'            => $error,
			'captcha_question' => $captchaQuestion,
			'redirect'         => $redirect,
		]);

		return new Response($html);
	}

	// ============================================
	// ОБРАБОТКА ВХОДА
	// ============================================

	/**
	 * POST /login
	 */
	public function login(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$login = trim($request->request->get('login') ?? '');
		$password = $request->request->get('password') ?? '';
		$ip = $request->getClientIp();

		if (!$this->validator->validate([
			'login'    => $login,
			'password' => $password,
		], [
			'login'    => ['required', 'string', 'min:1', 'max:255'],
			'password' => ['required', 'string', 'min:1', 'max:255'],
		])) {
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		if ($this->ipBlocker->isBlocked($ip)) {
			$this->session->set('login_error', 'Ваш IP-адрес заблокирован. Попробуйте позже.');
			return new RedirectResponse('/login');
		}

		$captchaRequired = (bool) $this->session->get('captcha_required', false);
		$captchaSolved = false;

		if ($captchaRequired) {
			$captchaAnswer = trim($request->request->get('captcha') ?? '');
			$captchaExpected = $this->session->get('captcha_expected', '');

			if (!$this->captchaService->validate($captchaAnswer, $captchaExpected)) {
				$this->session->set('login_error', 'Неверный ответ на капчу.');
				return new RedirectResponse('/login');
			}

			$captchaSolved = true;
			$this->session->remove('captcha_required');
			$this->session->remove('captcha_expected');
			$this->session->remove('captcha_question');
		}

		$check = $this->loginAttemptLimiter->checkLoginAttempt($login, $ip, $captchaSolved);
		if (!$check['success']) {
			if (!empty($check['captcha'])) {
				$captcha = $this->captchaService->generate();
				$this->session->set('captcha_required', true);
				$this->session->set('captcha_expected', $captcha['expected']);
				$this->session->set('captcha_question', $captcha['question']);
			}

			$this->session->set('login_error', $check['message']);
			return new RedirectResponse('/login');
		}

		$user = $this->neuronRepo->findByLoginOrEmail($login);

		if (!$user) {
			$this->loginAttemptLimiter->recordFailedLogin($login, $ip);
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		$userData = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$authMethod = $userData['auth_method'] ?? 'local';
		if ($authMethod !== 'local') {
			$this->loginAttemptLimiter->recordFailedLogin($login, $ip);
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		$hash = $user['hash'] ?? '';
		if (!password_verify($password, $hash)) {
			$this->loginAttemptLimiter->recordFailedLogin($login, $ip);

			if ($captchaSolved) {
				$ipKey = 'login_ip_' . $ip;
				$maxAttempts = $this->rateLimiter->getMaxAttempts() * 3;
				$decaySeconds = $this->rateLimiter->getDecaySeconds();
				$blockDuration = $this->rateLimiter->getBlockDuration();

				if ($this->rateLimiter->tooManyAttempts($ipKey, $maxAttempts, $decaySeconds)) {
					$this->ipBlocker->block($ip, 'brute_force', $blockDuration);
				}
			}

			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		if (password_needs_rehash($hash, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS)) {
			$newHash = password_hash($password, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS);
			$userData['password_hash'] = $newHash;
			$this->neuronRepo->update($user['id'], ['data' => $userData]);
		}

		$status = $userData['status'] ?? 'active';
		if ($status === 'pending') {
			$this->session->set('login_error', 'Ваш аккаунт ожидает одобрения администратора.');
			return new RedirectResponse('/login');
		}

		$this->loginAttemptLimiter->clearLoginAttempts($login, $ip);

		$this->session->migrate(true);

		$this->csrfService->generateToken();

		$this->session->set('user_id', $user['id']);
		$this->session->set('user_login', $userData['login'] ?? 'user');
		$this->session->set('user_roles', $this->synapseRepo->findRolesByUser($user['id']));

		$redirect = $request->request->get('redirect', '');
		if ($this->isLocalPath($redirect)) {
			return new RedirectResponse($redirect);
		}

		return new RedirectResponse('/');
	}

	// ============================================
	// ВЫХОД
	// ============================================

	/**
	 * POST /logout
	 */
	public function logout(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$this->session->migrate(true);

		$this->session->clear();

		$this->csrfService->generateToken();

		return new RedirectResponse('/');
	}

	private function isLocalPath(string $path): bool
	{
		if ($path === '') return false;
		if ($path[0] !== '/') return false;
		if (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) return false;
		return true;
	}
}