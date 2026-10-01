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
 * 
 * Зависимости:
 * - TextRepository    — работа с текстами
 * - NeuronRepository  — поиск пользователей
 * - SynapseRepository — получение ролей
 * - GuardController   — защита от перебора и CSRF
 * - AuthMiddleware    — проверка прав доступа
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Plugin\Guard\GuardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

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
		GuardController $guard
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->guard = $guard;

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
			return new Response('', 302, ['Location' => '/']);
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
			return new Response('', 302, ['Location' => '/login']);
		}

		$login = trim($request->request->get('login'));
		$password = $request->request->get('password');
		$ip = $request->getClientIp();

		// ============================================
		// ШАГ 2: Проверка блокировки IP
		// ============================================
		if ($this->guard->isIpBlocked($ip)) {
			$this->session->set('login_error', 'Ваш IP-адрес заблокирован. Попробуйте позже.');
			return new Response('', 302, ['Location' => '/login']);
		}

		// ============================================
		// ШАГ 3: Rate limiting
		// ============================================
		$check = $this->guard->checkLoginAttempt($login, $ip);
		if (!$check['success']) {
			$this->session->set('login_error', $check['message']);
			return new Response('', 302, ['Location' => '/login']);
		}

		// ============================================
		// ШАГ 4: Поиск пользователя
		// ============================================
		$user = $this->neuronRepo->findByLoginOrEmail($login);

		if (!$user) {
			// Записываем неудачную попытку
			$this->guard->recordFailedLogin($login, $ip);
			$this->session->set('login_error', 'Пользователь не найден');
			return new Response('', 302, ['Location' => '/login']);
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
			return new Response('', 302, ['Location' => '/login']);
		}

		// ============================================
		// ШАГ 6: Проверка пароля
		// ============================================
		$hash = $userData['password_hash'] ?? '';
		if (!password_verify($password, $hash)) {
			// Записываем неудачную попытку
			$this->guard->recordFailedLogin($login, $ip);

			// Блокируем IP при превышении общего лимита
			$ipKey = 'login_ip_' . $ip;
			if ($this->guard->tooManyAttempts($ipKey, 15, 300)) {
				$this->guard->blockIp($ip, 'brute_force', 3600);
			}

			$this->session->set('login_error', 'Неверный пароль');
			return new Response('', 302, ['Location' => '/login']);
		}

		// ============================================
		// ШАГ 7: Успешный вход
		// ============================================

		// Очищаем попытки
		$this->guard->clearLoginAttempts($login, $ip);

		// Генерируем новый CSRF-токен
		$this->guard->generateCsrfToken();

		// Сохраняем пользователя в сессию
		$this->session->set('user_id', $user['id']);
		$this->session->set('user_login', $userData['login'] ?? 'user');
		$this->session->set('user_roles', $this->synapseRepo->findRolesByUser($user['id']));

		$this->neuronRepo->logAdminAction('user_login', ['login' => $login]);
		
		// Редирект на главную
		return new Response('', 302, ['Location' => '/']);
	}

	// ============================================
	// ВЫХОД
	// ============================================

	/**
	 * GET /logout
	 * 
	 * Очищает сессию и перенаправляет на главную.
	 * 
	 * @return Response
	 */
	public function logout(): Response
	{
		// Логируем выход (пока сессия ещё жива)
		$login = $this->session->get('user_login');
		if ($login) {
			$this->neuronRepo->logAdminAction('user_logout', ['login' => $login]);
		}

		$this->session->clear();
		return new Response('', 302, ['Location' => '/']);
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
			return new Response('', 302, ['Location' => '/']);
		}

		// Генерируем CSRF-токен если нет
		if (empty($_SESSION['csrf_token'])) {
			$this->guard->generateCsrfToken();
		}

		// Flash-сообщение об ошибке
		$error = $this->session->get('register_error');
		$this->session->remove('register_error');

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
			return new Response('', 302, ['Location' => '/register']);
		}

		// Проверка блокировки IP
		$ip = $request->getClientIp();
		if ($this->guard->isIpBlocked($ip)) {
			$this->session->set('register_error', 'Регистрация недоступна с вашего IP.');
			return new Response('', 302, ['Location' => '/register']);
		}

		// ============================================
		// Получаем данные формы
		// ============================================
		$login = trim($request->request->get('login'));
		$email = trim($request->request->get('email'));
		$password = $request->request->get('password');

		// ============================================
		// Валидация
		// ============================================
		if (empty($login) || empty($email) || empty($password)) {
			$this->session->set('register_error', 'Все поля обязательны');
			return new Response('', 302, ['Location' => '/register']);
		}

		if (strlen($password) < 8) {
			$this->session->set('register_error', 'Пароль должен быть не менее 8 символов');
			return new Response('', 302, ['Location' => '/register']);
		}

		// Проверка уникальности логина
		$exists = $this->neuronRepo->findByLoginOrEmail($login);
		if ($exists) {
			$this->session->set('register_error', 'Логин уже занят');
			return new Response('', 302, ['Location' => '/register']);
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
		return new Response('', 302, ['Location' => '/login']);
	}
}