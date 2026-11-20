<?php

declare(strict_types=1);

/**
 * КОНТРОЛЛЕР РЕГИСТРАЦИИ
 *
 * Управление регистрацией новых пользователей.
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Service\CsrfService;
use Jan\Trinity\Core\Service\RateLimiter;
use Jan\Trinity\Core\Service\IpBlocker;
use Jan\Trinity\Core\Service\PasswordHasher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class RegisterController
{
	// ============================================
	// ПАРАМЕТРЫ ХЕША — ОДИН ИСТОЧНИК
	// ============================================
	private PasswordHasher $passwordHasher;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var Session — сессия. */
	private Session $session;

	/** @var NeuronRepository — создание и поиск пользователей. */
	private NeuronRepository $neuronRepo;

	/** @var RateLimiter — rate limiting. */
	private RateLimiter $rateLimiter;

	/** @var IpBlocker — блокировка IP. */
	private IpBlocker $ipBlocker;

	/** @var CsrfService — CSRF-защита. Один источник токена. */
	private CsrfService $csrfService;

	private Validator $validator;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		NeuronRepository $neuronRepo,
		RateLimiter $rateLimiter,
		IpBlocker $ipBlocker,
		CsrfService $csrfService,
		PasswordHasher $passwordHasher,
		Validator $validator,
		LoggerInterface $logger
	) {
		$this->twig = $twig;
		$this->session = $session;
		$this->neuronRepo = $neuronRepo;
		$this->rateLimiter = $rateLimiter;
		$this->ipBlocker = $ipBlocker;
		$this->csrfService = $csrfService;
		$this->passwordHasher = $passwordHasher;
		$this->validator = $validator;
		$this->logger = $logger;
	}

	/**
	 * GET /register
	 */
	public function registerForm(): Response
	{
		if ($this->session->get('user_id') !== null) {
			return new RedirectResponse('/');
		}

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$csrfToken = $this->csrfService->getOrCreateToken();
		$error = $this->session->get('register_error');
		$this->session->remove('register_error');

		$html = $this->twig->render('register.html.twig', [
			'error'      => $error,
			'csrf_token' => $csrfToken,
		]);

		return new Response($html);
	}

	/**
	 * POST /register
	 */
	public function register(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$csrfToken = $request->request->get('_csrf_token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			$this->session->set('register_error', 'Недействительный CSRF-токен');
			return new RedirectResponse('/register');
		}

		$ip = $request->getClientIp();
		if ($this->ipBlocker->isBlocked($ip)) {
			$this->session->set('register_error', 'Регистрация недоступна с вашего IP.');
			return new RedirectResponse('/register');
		}

		$registerKey = 'register_ip_' . $ip;
		if ($this->rateLimiter->tooManyAttempts($registerKey, 5, 120)) {
			$this->session->set('register_error', 'Слишком много попыток регистрации. Попробуйте через 10 минут.');
			return new RedirectResponse('/register');
		}

		$this->rateLimiter->hit($registerKey, 120);

		$login = trim($request->request->get('login') ?? '');
		$email = strtolower(trim($request->request->get('email') ?? ''));
		$password = $request->request->get('password') ?? '';

		if (!$this->validator->validate([
			'login'    => $login,
			'email'    => $email,
			'password' => $password,
		], [
			'login'    => ['required', 'string', 'min:3', 'max:255'],
			'email'    => ['required', 'email', 'max:255'],
			'password' => ['required', 'string', 'min:8', 'max:255'],
		])) {
			$this->session->set('register_error', $this->validator->getFirstError());
			return new RedirectResponse('/register');
		}

		if (!$this->validator->validate(['login' => $login], ['login' => ['unique:neuron,login']])) {
			$this->session->set('register_error', 'Логин уже занят');
			return new RedirectResponse('/register');
		}

		if (!$this->validator->validate(['email' => $email], ['email' => ['unique:neuron,email']])) {
			$this->session->set('register_error', 'Email уже занят');
			return new RedirectResponse('/register');
		}

		if (!$this->isPasswordStrong($password)) {
			$this->session->set('register_error', 'Пароль слишком простой. Используйте буквы, цифры и символы.');
			return new RedirectResponse('/register');
		}

		try {
			$this->neuronRepo->create('user', [
				'login'         => $login,
				'email'         => $email,
				'auth_method'   => 'local',
				'status'        => 'pending',
				'ip'            => $request->getClientIp(),
				'registered_at' => date('Y-m-d H:i:s'),
			], null, null, null, $this->passwordHasher->hash($password));
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
			$this->session->set('register_error', 'Логин или email уже занят');
			return new RedirectResponse('/register');
		} catch (\Throwable $e) {
			$this->logger->error('registration failed', [
				'message' => $e->getMessage(),
				'file'    => $e->getFile() . ':' . $e->getLine(),
			]);
			$this->session->set('register_error', 'Ошибка регистрации. Попробуйте позже.');
			return new RedirectResponse('/register');
		}

		return new RedirectResponse('/login');
	}

	/**
	 * Проверяет, достаточно ли сложный пароль.
	 */
	private function isPasswordStrong(string $password): bool
	{
		$blacklist = ['password', '12345678', 'qwerty123', 'aaaaaaaa', 'admin123'];
		if (in_array(strtolower($password), $blacklist, true)) {
			return false;
		}

		$classes = 0;
		if (preg_match('/[a-z]/', $password)) $classes++;
		if (preg_match('/[A-Z]/', $password)) $classes++;
		if (preg_match('/[0-9]/', $password)) $classes++;
		if (preg_match('/[^a-zA-Z0-9]/', $password)) $classes++;

		return $classes >= 3;
	}
}