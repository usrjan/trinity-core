<?php

/**
 * КОНТРОЛЛЕР АУТЕНТИФИКАЦИИ
 * ==========================
 *
 * Управление входом, регистрацией и выходом пользователей.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Удалён LoggerInterface.
 * Потому что — он не используется. А — GuardService и
 * NeuronRepository уже логируют. Без «пиздеца». Без «потом».
 *
 * [Мириам]: И — это правильно. Потому что неиспользуемая
 * зависимость — это «остаток старой инъекции». А мы — не
 * хотим остатков. Мы хотим — чистоту. И — «навсегда».
 *
 * @package Jan\Trinity\Plugin\Users
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\GuardService;
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

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — поиск пользователей */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — получение ролей */
	private SynapseRepository $synapseRepo;

	/** @var GuardService — защита от перебора и CSRF */
	private GuardService $guard;

	private Validator $validator;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: LoggerInterface — удалён. Потому что — не используется.
	 * А — GuardService и NeuronRepository уже логируют. Без «пиздеца».
	 *
	 * [Мириам]: И — это правильно. Потому что неиспользуемая
	 * зависимость — это «остаток старой инъекции». И — «навсегда».
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		GuardService $guard,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->guard = $guard;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// ФОРМА ВХОДА
	// ============================================

	public function loginForm(): Response
	{
		if ($this->isAuthenticated()) {
			return new RedirectResponse('/');
		}

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$this->guard->generateCsrfToken();

		$error = $this->session->get('login_error');
		$this->session->remove('login_error');

		$html = $this->twig->render('login.html.twig', [
			'error'      => $error,
			'csrf_token' => $this->session->get('csrf_token', ''),
		]);

		return new Response($html);
	}

	// ============================================
	// ОБРАБОТКА ВХОДА
	// ============================================

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

		if ($this->guard->isIpBlocked($ip)) {
			$this->session->set('login_error', 'Ваш IP-адрес заблокирован. Попробуйте позже.');
			return new RedirectResponse('/login');
		}

		$check = $this->guard->checkLoginAttempt($login, $ip);
		if (!$check['success']) {
			$this->session->set('login_error', $check['message']);
			return new RedirectResponse('/login');
		}

		$user = $this->neuronRepo->findByLoginOrEmail($login);

		if (!$user) {
			$this->guard->recordFailedLogin($login, $ip);
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		$userData = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$authMethod = $userData['auth_method'] ?? 'local';
		if ($authMethod !== 'local') {
			$this->guard->recordFailedLogin($login, $ip);
			$this->session->set('login_error', 'Используйте вход через ' . $authMethod);
			return new RedirectResponse('/login');
		}

		$hash = $userData['password_hash'] ?? '';
		if (!password_verify($password, $hash)) {
			$this->guard->recordFailedLogin($login, $ip);

			$ipKey = 'login_ip_' . $ip;
			if ($this->guard->tooManyAttempts($ipKey, 15, 300)) {
				$this->guard->blockIp($ip, 'brute_force', 3600);
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

		$this->guard->clearLoginAttempts($login, $ip);

		$this->session->migrate(true);

		$this->guard->generateCsrfToken();

		$this->session->set('user_id', $user['id']);
		$this->session->set('user_login', $userData['login'] ?? 'user');
		$this->session->set('user_roles', $this->synapseRepo->findRolesByUser($user['id']));

		$this->neuronRepo->logAdminAction('user_login', ['login' => $login]);

		return new RedirectResponse('/');
	}

	// ============================================
	// ВЫХОД
	// ============================================

	public function logout(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$login = $this->session->get('user_login');
		if ($login) {
			$this->neuronRepo->logAdminAction('user_logout', ['login' => $login]);
		}

		$this->session->clear();

		$this->session->migrate(true);

		$this->guard->generateCsrfToken();

		return new RedirectResponse('/');
	}

	// ============================================
	// ФОРМА РЕГИСТРАЦИИ
	// ============================================

	public function registerForm(): Response
	{
		if ($this->isAuthenticated()) {
			return new RedirectResponse('/');
		}

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$this->guard->generateCsrfToken();

		$error = $this->session->get('register_error');
		$this->session->remove('register_error');

		$html = $this->twig->render('register.html.twig', [
			'error'      => $error,
			'csrf_token' => $this->session->get('csrf_token', ''),
		]);

		return new Response($html);
	}

	// ============================================
	// ОБРАБОТКА РЕГИСТРАЦИИ
	// ============================================

	public function register(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$ip = $request->getClientIp();
		if ($this->guard->isIpBlocked($ip)) {
			$this->session->set('register_error', 'Регистрация недоступна с вашего IP.');
			return new RedirectResponse('/register');
		}

		$registerKey = 'register_ip_' . $ip;
		if ($this->guard->tooManyAttempts($registerKey, 5, 120)) {
			$this->session->set('register_error', 'Слишком много попыток регистрации. Попробуйте через 10 минут.');
			return new RedirectResponse('/register');
		}

		$this->guard->hit($registerKey, 120);

		$login = trim($request->request->get('login') ?? '');
		$email = trim($request->request->get('email') ?? '');
		$password = $request->request->get('password') ?? '';

		$email = strtolower($email);

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

		if (!$this->validator->validate(['login' => $login], [
			'login' => ['unique:neuron,login'],
		])) {
			$this->session->set('register_error', 'Логин уже занят');
			return new RedirectResponse('/register');
		}

		if (!$this->validator->validate(['email' => $email], [
			'email' => ['unique:neuron,email'],
		])) {
			$this->session->set('register_error', 'Email уже занят');
			return new RedirectResponse('/register');
		}

		$this->neuronRepo->create('user', [
			'login'         => $login,
			'email'         => $email,
			'password_hash' => password_hash($password, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS),
			'auth_method'   => 'local',
			'status'        => 'pending',
			'ip'            => $request->getClientIp(),
			'registered_at' => date('Y-m-d H:i:s'),
		]);

		return new RedirectResponse('/login');
	}
}