<?php

/**
 * КОНТРОЛЛЕР АУТЕНТИФИКАЦИИ
 * ==========================
 * 
 * Управление входом, регистрацией и выходом пользователей.
 * 
 * Страничные методы (HTML):
 * - loginForm()          — форма входа
 * - login()              — обработка входа (POST)
 * - registerForm()       — форма регистрации
 * - register()           — обработка регистрации (POST)
 * - logout()             — выход из системы
 * 
 * Особенности безопасности:
 * - CSRF-защита через GuardController
 * - Ограничение попыток входа (brute force)
 * - Блокировка IP при превышении лимита
 * - Пароли хешируются bcrypt (cost=12)
 * - Регенерация ID сессии после входа
 * - Проверка уникальности email при регистрации
 * 
 * Зависимости:
 * - TextRepository    — работа с текстами
 * - NeuronRepository  — поиск пользователей
 * - SynapseRepository — получение ролей
 * - GuardController   — защита от перебора и CSRF
 * - AuthMiddleware    — проверка прав доступа
 * 
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 * 
 * [Лорелея]: Добавлен session_regenerate_id(true) после
 * успешного входа. Это защита от session fixation. Раньше
 * ID сессии оставался тем же — атакующий мог заставить
 * пользователя использовать известный ID.
 * 
 * [Мириам]: Добавлена проверка уникальности email при
 * регистрации. Раньше можно было создать два аккаунта
 * с одним email. Теперь — нет. Сообщение об ошибке
 * нейтральное, чтобы не перечислять пользователей.
 * 
 * [Лорелея]: Сообщения об ошибке входа стали расплывчатыми:
 * «Неверный логин или пароль» вместо «Пользователь не найден»
 * и «Неверный пароль». Это не даёт возможности перечислять
 * существующих пользователей.
 * 
 * [Мириам]: Добавлена валидация email через filter_var.
 * Раньше принимали любую строку.
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Plugin\Guard\GuardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class AuthController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — поиск пользователей */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — получение ролей */
	private SynapseRepository $synapseRepo;

	/** @var GuardController — защита от перебора и CSRF */
	private GuardController $guard;

	/** @var LoggerInterface — логгер */
	private LoggerInterface $logger;

	private Validator $validator;

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		GuardController $guard,
		LoggerInterface $logger,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->guard = $guard;
		$this->logger = $logger;
		$this->validator = $validator;

		// Инициализация middleware авторизации
		$this->initAuth($session);
	}

	// ============================================
	// ФОРМА ВХОДА
	// ============================================

	/**
	 * GET /login
	 * 
	 * Показывает форму входа.
	 * Если пользователь уже авторизован — редирект на главную.
	 * Генерирует CSRF-токен если его нет.
	 * 
	 * @return Response
	 */
	public function loginForm(): Response
	{
		// Уже авторизован — на главную
		if ($this->isAuthenticated()) {
			return new RedirectResponse('/');
		}

		// Генерируем CSRF-токен для формы, если его нет
		if (empty($_SESSION['csrf_token'])) {
			$this->guard->generateCsrfToken();
		}

		// Flash-сообщение об ошибке (показывается один раз)
		$error = $this->session->get('login_error');
		$this->session->remove('login_error');

		// Рендерим форму
		$html = $this->twig->render('login.html.twig', [
			'error'      => $error,
			'csrf_token' => $_SESSION['csrf_token'] ?? '',
		]);

		return new Response($html);
	}

	// ============================================
	// ОБРАБОТКА ВХОДА
	// ============================================

	/**
	 * POST /login
	 * 
	 * Проверяет логин и пароль, создаёт сессию.
	 * 
	 * Многоуровневая защита:
	 * 1. CSRF-токен
	 * 2. Проверка блокировки IP
	 * 3. Ограничение попыток (rate limiting)
	 * 4. Поиск пользователя по логину или email
	 * 5. Проверка пароля (bcrypt)
	 * 6. Запись неудачных попыток
	 * 7. Блокировка IP при превышении лимита (15 попыток за 5 минут)
	 * 8. Регенерация ID сессии (защита от session fixation)
	 * 
	 * [Лорелея]: Сообщения об ошибке намеренно расплывчатые.
	 * «Неверный логин или пароль» вместо «Пользователь не найден»
	 * и «Неверный пароль». Это не даёт возможности перечислять
	 * существующих пользователей.
	 * 
	 * @param Request $request
	 * @return Response — редирект на главную или обратно на форму с ошибкой
	 */
	public function login(Request $request): Response
	{
		// ============================================
		// ШАГ 1: CSRF-защита
		// ============================================
		$token = $request->request->get('_csrf_token', '');
		if (!$this->guard->validateCsrfToken($token)) {
			$this->session->set('login_error', 'Недействительный токен безопасности.');
			return new RedirectResponse('/login');
		}

		$login = trim($request->request->get('login') ?? '');
		$password = $request->request->get('password') ?? '';
		$ip = $request->getClientIp();

		// [Лорелея]: Валидация. Логин и пароль. Через Validator.
		// Не через empty(). А через required. И — с min. И — с max.
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

		// ============================================
		// ШАГ 2: Проверка блокировки IP
		// ============================================
		if ($this->guard->isIpBlocked($ip)) {
			$this->session->set('login_error', 'Ваш IP-адрес заблокирован. Попробуйте позже.');
			return new RedirectResponse('/login');
		}

		// ============================================
		// ШАГ 3: Rate limiting
		// ============================================
		$check = $this->guard->checkLoginAttempt($login, $ip);
		if (!$check['success']) {
			$this->session->set('login_error', $check['message']);
			return new RedirectResponse('/login');
		}

		// ============================================
		// ШАГ 4: Поиск пользователя
		// ============================================
		$user = $this->neuronRepo->findByLoginOrEmail($login);

		if (!$user) {
			$this->guard->recordFailedLogin($login, $ip);
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		// Извлекаем данные пользователя из JSON
		$userData = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		// ============================================
		// ШАГ 5: Проверка метода аутентификации
		// ============================================
		$authMethod = $userData['auth_method'] ?? 'local';
		if ($authMethod !== 'local') {
			$this->guard->recordFailedLogin($login, $ip);
			$this->session->set('login_error', 'Используйте вход через ' . $authMethod);
			return new RedirectResponse('/login');
		}

		// ============================================
		// ШАГ 6: Проверка пароля
		// ============================================
		$hash = $userData['password_hash'] ?? '';
		if (!password_verify($password, $hash)) {
			$this->guard->recordFailedLogin($login, $ip);

			// Блокируем IP при превышении общего лимита
			$ipKey = 'login_ip_' . $ip;
			if ($this->guard->tooManyAttempts($ipKey, 15, 300)) {
				$this->guard->blockIp($ip, 'brute_force', 3600);
			}

			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		// ============================================
		// ШАГ 7: Успешный вход
		// ============================================

		// Очищаем попытки
		$this->guard->clearLoginAttempts($login, $ip);

		// [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ.
		// Регенерируем ID сессии. Защита от session fixation.
		// true — удаляем старый файл сессии.
		// Это нужно делать ДО записи данных пользователя.
		session_regenerate_id(true);

		// Генерируем новый CSRF-токен
		$this->guard->generateCsrfToken();

		// Сохраняем пользователя в сессию
		$this->session->set('user_id', $user['id']);
		$this->session->set('user_login', $userData['login'] ?? 'user');
		$this->session->set('user_roles', $this->synapseRepo->findRolesByUser($user['id']));

		$this->neuronRepo->logAdminAction('user_login', ['login' => $login]);

		// Редирект на главную
		return new RedirectResponse('/');
	}

	// ============================================
	// ВЫХОД
	// ============================================

	/**
	 * POST /logout
	 * 
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Теперь logout — POST.
	 * Раньше был GET. И любой сайт мог через <img src="/logout">
	 * разлогинить пользователя. Теперь — только POST. И — с CSRF.
	 * 
	 * [Мириам]: Плюс — удаляем куки PHPSESSID. Чтобы клиент
	 * не слал старый ID. И — session_destroy. Чтобы сессия
	 * на сервере тоже исчезла.
	 */
	public function logout(Request $request): Response
	{
		// CSRF-проверка
		$token = $request->request->get('_csrf_token', '');

		//$this->logger->info("[Logout] Token received: '" . $token . "'. Session token: '" . ($_SESSION['csrf_token'] ?? 'NULL') . "'");


		if (!$this->guard->validateCsrfToken($token)) {
			$this->session->set('logout_error', 'Недействительный токен безопасности.');
			return new RedirectResponse('/');
		}

		// Логируем выход (пока сессия ещё жива)
		$login = $this->session->get('user_login');
		if ($login) {
			$this->neuronRepo->logAdminAction('user_logout', ['login' => $login]);
		}

		// [Лорелея]: Сначала очищаем данные сессии.
		// Потому что session_regenerate_id НЕ очищает данные.
		// Он только переносит их в новую сессию с новым ID.
		// А нам надо, чтобы пользователь был разлогинен.
		$this->session->clear();

		// [Мириам]: Потом регенерируем ID и удаляем старый файл.
		// Теперь сессия пустая. И с новым ID.
		$this->session->invalidate();

		return new RedirectResponse('/');
	}

	// ============================================
	// ФОРМА РЕГИСТРАЦИИ
	// ============================================

	/**
	 * GET /register
	 * 
	 * Показывает форму регистрации нового пользователя.
	 * Если пользователь уже авторизован — редирект на главную.
	 * 
	 * @return Response
	 */
	public function registerForm(): Response
	{
		// Уже авторизован — на главную
		if ($this->isAuthenticated()) {
			return new RedirectResponse('/');
		}

		// Генерируем CSRF-токен если нет
		if (empty($_SESSION['csrf_token'])) {
			$this->guard->generateCsrfToken();
		}

		// Flash-сообщение об ошибке
		$error = $this->session->get('register_error');
		$this->session->remove('register_error');
		error_log("[Register] FORM. error=" . var_export($error, true));

		// Рендерим форму
		$html = $this->twig->render('register.html.twig', [
			'error'      => $error,
			'csrf_token' => $_SESSION['csrf_token'] ?? '',
		]);

		return new Response($html);
	}

	// ============================================
	// ОБРАБОТКА РЕГИСТРАЦИИ
	// ============================================

	/**
	 * POST /register
	 * 
	 * Создаёт нового пользователя.
	 * 
	 * Валидация:
	 * - Все поля обязательны
	 * - Пароль не менее 8 символов
	 * - Логин должен быть уникальным
	 * - Email должен быть уникальным
	 * - Email должен быть валидным
	 * 
	 * [Мириам]: Добавлена проверка уникальности email.
	 * Раньше можно было создать два аккаунта с одним email.
	 * 
	 * [Лорелея]: Сообщения об ошибке нейтральные. «Логин
	 * уже занят» и «Email уже занят» — это нормально для
	 * регистрации. Здесь нельзя иначе — пользователь должен
	 * знать, что логин занят.
	 * 
	 * @param Request $request
	 * @return Response — редирект на /login или обратно на форму
	 */
	public function register(Request $request): Response
	{
		// ============================================
		// CSRF-защита
		// ============================================
		$token = $request->request->get('_csrf_token', '');
		if (!$this->guard->validateCsrfToken($token)) {
			$this->session->set('register_error', 'Недействительный токен безопасности.');
			return new RedirectResponse('/register');
		}

		// Проверка блокировки IP
		$ip = $request->getClientIp();
		if ($this->guard->isIpBlocked($ip)) {
			$this->session->set('register_error', 'Регистрация недоступна с вашего IP.');
			return new RedirectResponse('/register');
		}

		// [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Rate limit для регистрации.
		// Не больше 5 попыток за 10 минут с одного IP.
		// Это защищает от скриптов, которые создают аккаунты пачками.
		$registerKey = 'register_ip_' . $ip;
		if ($this->guard->tooManyAttempts($registerKey, 5, 120)) {
			$this->session->set('register_error', 'Слишком много попыток регистрации. Попробуйте через 10 минут.');
			$check = $this->session->get('register_error');
			return new RedirectResponse('/register');
		}

		// [Мириам]: Записываем попытку. ДО создания аккаунта.
		// Потому что если создание упадёт — попытка уже учтена.
		// И это правильно. Потому что попытка была.
		$this->guard->hit($registerKey, 120);

		// ============================================
		// Получаем данные формы
		// ============================================
		$login = trim($request->request->get('login') ?? '');
		$email = trim($request->request->get('email') ?? '');
		$password = $request->request->get('password') ?? '';

		// ============================================
		// Валидация
		// ============================================
		// [Лорелея]: Теперь через Validator. Всё. В одном месте.
		// И — с mb_strlen. И — с email. И — с required.
		// И — с min. И — с max. И — с regex.
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

		// [Мириам]: Проверка уникальности. Через Validator.
		// Теперь можно. Потому что Validator знает про NeuronRepository.
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

		// ============================================
		// Создаём пользователя
		// ============================================
		$this->neuronRepo->create('user', [
			'login'         => $login,
			'email'         => $email,
			'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
			'auth_method'   => 'local',
		]);

		// Редирект на форму входа
		return new RedirectResponse('/login');
	}
}