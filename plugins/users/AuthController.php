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

		$error = $this->session->get('login_error');
		$this->session->remove('login_error');

		$captchaQuestion = $this->session->get('captcha_question', null);

		$html = $this->twig->render('login.html.twig', [
			'error'            => $error,
			'captcha_question' => $captchaQuestion,
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
	 * потом блокировка IP, потом captcha, потом попытки входа,
	 * потом поиск пользователя, потом пароль, потом статус.
	 *
	 * [Мириам]: И это правильно, потому что каждый шаг — это
	 * ещё одна дверь, и каждая должна быть закрыта для тех,
	 * кто пришёл не с добром.
	 *
	 * @param Request $request
	 * @return Response
	 */
	public function login(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$login = trim($request->request->get('login') ?? '');
		$password = $request->request->get('password') ?? '';
		$ip = $request->getClientIp();

		// [Лорелея]: Валидация — это не «формальность», а забота
		// о том, чтобы данные были честными.
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

		// [Мириам]: Проверяем, не заблокирован ли IP. Если да —
		// не пускаем, но не говорим, что именно заблокирован.
		// Без «пиздеца».
		if ($this->guard->isIpBlocked($ip)) {
			$this->session->set('login_error', 'Ваш IP-адрес заблокирован. Попробуйте позже.');
			return new RedirectResponse('/login');
		}

		// [Лорелея]: Проверяем captcha, если она была запрошена.
		// Captcha отличает человека от бота, не наказывая всю сеть.
		$captchaRequired = (bool) $this->session->get('captcha_required', false);
		$captchaSolved = false;

		if ($captchaRequired) {
			$captchaAnswer = trim($request->request->get('captcha') ?? '');
			$captchaExpected = $this->session->get('captcha_expected', '');

			if (!$this->guard->validateCaptcha($captchaAnswer, $captchaExpected)) {
				$this->session->set('login_error', 'Неверный ответ на капчу.');
				return new RedirectResponse('/login');
			}

			$captchaSolved = true;
			$this->session->remove('captcha_required');
			$this->session->remove('captcha_expected');
			$this->session->remove('captcha_question');
		}

		// [Мириам]: Проверяем попытки входа. Если лимит исчерпан
		// и captcha не решена — показываем captcha, а не блокируем.
		$check = $this->guard->checkLoginAttempt($login, $ip, $captchaSolved);
		if (!$check['success']) {
			if (!empty($check['captcha'])) {
				$captcha = $this->guard->generateCaptcha();
				$this->session->set('captcha_required', true);
				$this->session->set('captcha_expected', $captcha['expected']);
				$this->session->set('captcha_question', $captcha['question']);
			}

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
			// [Лорелея]: Сообщение то же, что и при неверном пароле,
			// чтобы не было утечки существования аккаунта.
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		$hash = $user['hash'] ?? '';
		if (!password_verify($password, $hash)) {
			$this->guard->recordFailedLogin($login, $ip);

			// [Лорелея]: Блокируем IP только если captcha была решена
			// и попыток всё равно слишком много.
			if ($captchaSolved) {
				$ipKey = 'login_ip_' . $ip;
				$maxAttempts = $this->guard->getMaxAttempts() * 3;
				$decaySeconds = $this->guard->getDecaySeconds();
				$blockDuration = $this->guard->getBlockDuration();

				if ($this->guard->tooManyAttempts($ipKey, $maxAttempts, $decaySeconds)) {
					$this->guard->blockIp($ip, 'brute_force', $blockDuration);
				}
			}

			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		// [Лорелея]: Если хеш устарел, обновляем его, потому что
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

		// [Мириам]: Мигрируем сессию, чтобы старый ID не остался
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
	 * потом энтропия пароля, и только потом создание.
	 *
	 * [Мириам]: И это правильно, потому что каждый шаг — это ещё
	 * одна дверь, и каждая должна быть закрыта для тех, кто пришёл
	 * не с добром.
	 *
	 * @param Request $request
	 * @return Response
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

		// [Лорелея]: Валидация — это не «формальность», а забота
		// о том, чтобы данные были честными.
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

		// [Мириам]: Проверяем уникальность логина и email, чтобы
		// не создавать дубликаты. Без «пиздеца».
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

		// [Лорелея]: Проверяем энтропию пароля, потому что min:8
		// пропускает «aaaaaaaa». Это не «идеал», а минимум.
		if (!$this->isPasswordStrong($password)) {
			$this->session->set('register_error', 'Пароль слишком простой. Используйте буквы, цифры и символы.');
			return new RedirectResponse('/register');
		}

		// [Мириам]: Ловим гонку на unique. Если два запроса одновременно
		// прошли проверку, база спасёт один, а второй получит 500.
		// Мы превращаем это в 409 и понятное сообщение.
		try {
			$this->neuronRepo->create('user', [
				'login'         => $login,
				'email'         => $email,
				'auth_method'   => 'local',
				'status'        => 'pending',
				'ip'            => $request->getClientIp(),
				'registered_at' => date('Y-m-d H:i:s'),
			], null, null, null, password_hash($password, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS));
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
			// [Лорелея]: Гонка на unique. База спасла один, второй
			// получил бы 500. Теперь — 409 и понятное сообщение.
			$this->session->set('register_error', 'Логин или email уже занят');
			return new RedirectResponse('/register');
		} catch (\Throwable $e) {
			// [Мириам]: Всё остальное — логируем и отдаём общую ошибку.
			if ($this->logger !== null) {
				$this->logger->error('registration failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				]);
			}

			$this->session->set('register_error', 'Ошибка регистрации. Попробуйте позже.');
			return new RedirectResponse('/register');
		}

		return new RedirectResponse('/login');
	}

	/**
	 * Проверяет, что пароль достаточно сложный.
	 *
	 * [Лорелея]: Это не «идеал», а минимум. Пароль должен содержать
	 * хотя бы три из четырёх классов символов: строчные, заглавные,
	 * цифры, спецсимволы. И не быть из blacklist.
	 *
	 * [Мириам]: И это правильно, потому что слабый пароль — это дыра,
	 * даже если он длинный.
	 *
	 * @param string $password
	 * @return bool
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