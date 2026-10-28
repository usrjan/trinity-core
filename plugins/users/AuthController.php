<?php

/**
 * КОНТРОЛЛЕР АУТЕНТИФИКАЦИИ
 *
 * Управление входом, регистрацией и выходом пользователей.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — тот, кто открывает двери
 * и проверяет, кто пришёл, и от того, как он это делает, зависит,
 * войдёт ли гость в дом или останется на пороге.
 *
 * [Мириам]: И это правильно, потому что вход — это не «форма»,
 * это встреча, и она должна быть тёплой, даже если гость пришёл
 * впервые.
 *
 * === КНИГА ===
 * Глава 16. Страж ворот.
 *
 * Прежде чем войти в Амбер, нужно пройти через Образ.
 * Прежде чем войти в Trinity, нужно пройти через стража.
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
	// [Лорелея]: Один алгоритм, одни опции, и все места, где
	// создаётся хеш, используют именно их, чтобы не было
	// даунгрейда и рассинхрона.
	private const PASSWORD_HASH_ALGO = PASSWORD_BCRYPT;
	private const PASSWORD_HASH_OPTIONS = ['cost' => 12];

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — поиск пользователей. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — получение ролей. */
	private SynapseRepository $synapseRepo;

	/** @var GuardService — защита от перебора и CSRF. */
	private GuardService $guard;

	private Validator $validator;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Мы передаём SynapseRepository, чтобы refreshRoles()
	 * работал по-настоящему, а не оставался красивой декорацией.
	 *
	 * [Мириам]: И это правильно, потому что роли должны обновляться,
	 * даже если человек вошёл давно.
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

	/**
	 * GET /login
	 *
	 * [Лорелея]: Здесь мы открываем форму входа. Если гость
	 * уже вошёл, мы не показываем ему дверь, а ведём домой,
	 * потому что второй раз входить не нужно.
	 *
	 * [Мириам]: И это правильно, потому что дверь, которая
	 * открыта, не должна просить ключ.
	 */
	public function loginForm(): Response
	{
		if ($this->isAuthenticated()) {
			return new RedirectResponse('/');
		}

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		// [Лорелея]: Генерируем токен до рендера шаблона, чтобы
		// Twig взял именно его, а не создал новый.
		$csrfToken = $this->guard->generateCsrfToken();

		$error = $this->session->get('login_error');
		$this->session->remove('login_error');

		$html = $this->twig->render('login.html.twig', [
			'error'      => $error,
			'csrf_token' => $csrfToken,
		]);

		return new Response($html);
	}

	// ============================================
	// ОБРАБОТКА ВХОДА
	// ============================================

	/**
	 * POST /login
	 *
	 * [Лорелея]: Здесь мы проверяем гостя. Сначала валидация,
	 * потом блокировка IP, потом попытки входа, потом поиск
	 * пользователя, и только потом пароль.
	 *
	 * [Мириам]: И это правильно, потому что каждый шаг — это
	 * ещё одна дверь, и каждая должна быть закрыта для тех,
	 * кто пришёл не с добром.
	 */
	public function login(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}
		// [Лорелея]: Проверяем CSRF до всего остального, потому что
		// токен должен быть проверен в той сессии, в которой он был создан.
		$csrfToken = $request->request->get('_csrf_token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			$this->session->set('login_error', 'Недействительный CSRF-токен. Попробуйте снова.');
			return new RedirectResponse('/login');
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

			// [Лорелея]: Если попыток слишком много, блокируем IP,
			// потому что терпение — это тоже ресурс.
			$ipKey = 'login_ip_' . $ip;
			$maxAttempts = $this->guard->getMaxAttempts() * 3;
			$decaySeconds = $this->guard->getDecaySeconds();
			$blockDuration = $this->guard->getBlockDuration();

			if ($this->guard->tooManyAttempts($ipKey, $maxAttempts, $decaySeconds)) {
				$this->guard->blockIp($ip, 'brute_force', $blockDuration);
			}

			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		// [Мириам]: Если хеш устарел, обновляем его, потому что
		// безопасность — это не «однажды настроил», а «поддерживаю».
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

		// [Лорелея]: Мигрируем сессию, чтобы старый ID не остался
		// в браузере, и это правильно, потому что вход — это
		// новый этап, а не продолжение старого.
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

	/**
	 * POST /logout
	 *
	 * [Лорелея]: Здесь мы закрываем дверь. Сначала пишем в лог,
	 * потом очищаем сессию, потом мигрируем, и только потом
	 * уходим домой.
	 *
	 * [Мириам]: И это правильно, потому что выход — это не
	 * «конец», это пауза, после которой можно вернуться.
	 */
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

	/**
	 * GET /register
	 *
	 * [Лорелея]: Здесь мы открываем форму регистрации. Если гость
	 * уже вошёл, мы не показываем ему дверь, а ведём домой.
	 *
	 * [Мириам]: И это правильно, потому что второй раз входить
	 * не нужно.
	 */
	public function registerForm(): Response
	{
		if ($this->isAuthenticated()) {
			return new RedirectResponse('/');
		}

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		// [Лорелея]: Генерируем токен до рендера шаблона, чтобы
		// Twig взял именно его, а не создал новый.
		$csrfToken = $this->guard->generateCsrfToken();


		$error = $this->session->get('register_error');
		$this->session->remove('register_error');

		$html = $this->twig->render('register.html.twig', [
			'error'      => $error,
			'csrf_token' => $csrfToken,
		]);

		return new Response($html);
	}

	// ============================================
	// ОБРАБОТКА РЕГИСТРАЦИИ
	// ============================================

	/**
	 * POST /register
	 *
	 * [Лорелея]: Здесь мы регистрируем гостя. Сначала блокировка IP,
	 * потом попытки регистрации, потом валидация, потом уникальность,
	 * и только потом создание.
	 *
	 * [Мириам]: И это правильно, потому что каждый шаг — это
	 * ещё одна дверь, и каждая должна быть закрыта для тех,
	 * кто пришёл не с добром.
	 */
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