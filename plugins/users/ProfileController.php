<?php

/**
 * КОНТРОЛЛЕР ПРОФИЛЯ
 * ===================
 * 
 * Управление профилем текущего пользователя.
 * 
 * Страничные методы (HTML):
 * - view()               — просмотр профиля (полная страница)
 * - editForm()            — форма редактирования
 * - edit()                — сохранение изменений (POST)
 * 
 * API-методы (JSON):
 * - apiView()             — просмотр профиля (SPA-фрагмент)
 * 
 * Пользователь может изменить:
 * - Email
 * - Телефон
 * - Пароль (опционально, с подтверждением)
 * 
 * Логин изменить нельзя — он задаётся при регистрации.
 * 
 * Зависимости:
 * - TextRepository    — работа с текстами
 * - NeuronRepository  — загрузка и обновление пользователя
 * - GuardController   — CSRF-защита
 * - AuthMiddleware    — проверка прав доступа
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Plugin\Guard\GuardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class ProfileController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — загрузка и обновление пользователя */
	private NeuronRepository $neuronRepo;

	/** @var GuardController — CSRF-защита */
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
		GuardController $guard
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->guard = $guard;

		// Инициализация middleware авторизации
		$this->initAuth($session);
	}

	// ============================================
	// ПРОСМОТР ПРОФИЛЯ (ПОЛНАЯ СТРАНИЦА)
	// ============================================

	/**
	 * GET /profile
	 * 
	 * Показывает профиль текущего пользователя.
	 * Требует авторизацию.
	 * Если пользователь не найден — очищает сессию и перенаправляет на /login.
	 * 
	 * @return Response
	 */
	public function view(): Response
	{
		// Требуем авторизацию
		$this->requireAuth();

		$userId = $this->session->get('user_id');

		// Загружаем нейрон пользователя
		$user = $this->neuronRepo->findById($userId);
		if (!$user || $user['type'] !== 'user') {
			// Пользователь не найден — очищаем сессию
			$this->session->clear();
			return new Response('', 302, ['Location' => '/login']);
		}

		// Извлекаем данные из JSON
		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		// Роли пользователя
		$roles = $this->getUserRoles();

		// Рендерим полную страницу профиля
		$html = $this->twig->render('profile-full.html.twig', [
			'user' => [
				'id'          => $user['id'],
				'login'       => $data['login'] ?? 'user',
				'lang'        => $data['lang'] ?? 'ru',
				'email'       => $data['email'] ?? '',
				'phone'       => $data['phone'] ?? '',
				'auth_method' => $data['auth_method'] ?? 'local',
				'created_at'  => $user['date'] ?? '',
			],
			'roles' => $roles,
		]);

		return new Response($html);
	}

	// ============================================
	// ФОРМА РЕДАКТИРОВАНИЯ ПРОФИЛЯ
	// ============================================

	/**
	 * GET /profile/edit
	 * 
	 * Показывает форму редактирования профиля.
	 * Поля: email, телефон, новый пароль, подтверждение пароля.
	 * 
	 * @return Response
	 */
	public function editForm(): Response
	{
		$this->requireAuth();

		$userId = $this->session->get('user_id');
		$user = $this->neuronRepo->findById($userId);
		if (!$user || $user['type'] !== 'user') {
			return new Response('', 302, ['Location' => '/login']);
		}

		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		// Получаем список доступных языков из таблицы text
		$availableLangs = $this->textRepo->getAvailableLangs();
		
		// Формируем массив [код => название] для select
		$langNames = [
			'ru' => 'Русский',
			'en' => 'English',
			'it' => 'Italiano',
			'de' => 'Deutsch',
			'fr' => 'Français',
		];
		
		$langOptions = [];
		foreach ($availableLangs as $code) {
			$langOptions[$code] = $langNames[$code] ?? strtoupper($code);
		}

		// Получаем CSRF-токен через GuardController
		$csrfToken = $this->guard->getCsrfToken();
		if ($csrfToken === null) {
			$csrfToken = $this->guard->generateCsrfToken();
		}

		$html = $this->twig->render('profile-edit.html.twig', [
			'user' => [
				'login' => $data['login'] ?? '',
				'email' => $data['email'] ?? '',
				'phone' => $data['phone'] ?? '',
				'lang'  => $data['lang'] ?? 'ru',
			],
			'available_langs' => $langOptions,
			'csrf_token'      => $csrfToken,
		]);

		return new Response($html);
	}

	// ============================================
	// СОХРАНЕНИЕ ИЗМЕНЕНИЙ ПРОФИЛЯ
	// ============================================

	/**
	 * POST /profile/edit
	 * 
	 * Сохраняет изменения профиля.
	 * 
	 * Можно изменить:
	 * - Email и телефон — всегда
	 * - Пароль — только если заполнены оба поля (новый + подтверждение)
	 * 
	 * Валидация пароля:
	 * - Новый пароль и подтверждение должны совпадать
	 * - Минимальная длина — 8 символов
	 * 
	 * @param Request $request
	 * @return Response — редирект на /profile или обратно на форму
	 */
	public function edit(Request $request): Response
	{
		// Требуем авторизацию
		$this->requireAuth();

		$userId = $this->session->get('user_id');

		// ============================================
		// CSRF-защита
		// ============================================
		$token = $request->request->get('_csrf_token', '');
		if (!$this->guard->validateCsrfToken($token)) {
			$this->session->set('profile_error', 'Недействительный токен безопасности.');
			return new Response('', 302, ['Location' => '/profile/edit']);
		}

		// ============================================
		// Получаем данные формы
		// ============================================
		$lang = trim($request->request->get('lang', 'ru'));
		$email = trim($request->request->get('email', ''));
		$phone = trim($request->request->get('phone', ''));
		$newPassword = $request->request->get('new_password', '');
		$passwordConfirm = $request->request->get('password_confirm', '');

		// Загружаем текущие данные пользователя
		$user = $this->neuronRepo->findById($userId);
		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		// Обновляем email и телефон
		$data['lang'] = $lang;
		$data['email'] = $email;
		$data['phone'] = $phone;

		// ============================================
		// Смена пароля (опционально)
		// ============================================
		if (!empty($newPassword)) {
			// Проверка совпадения паролей
			if ($newPassword !== $passwordConfirm) {
				$this->session->set('profile_error', 'Пароли не совпадают');
				return new Response('', 302, ['Location' => '/profile/edit']);
			}

			// Проверка минимальной длины
			if (strlen($newPassword) < 8) {
				$this->session->set('profile_error', 'Пароль должен быть не менее 8 символов');
				return new Response('', 302, ['Location' => '/profile/edit']);
			}

			// Хешируем новый пароль
			$data['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
		}

		// ============================================
		// Сохраняем изменения
		// ============================================
		$this->neuronRepo->update($userId, ['data' => $data]);

		// Редирект на просмотр профиля
		return new Response('', 302, ['Location' => '/profile']);
	}

	// ============================================
	// ПРОСМОТР ПРОФИЛЯ (SPA-ФРАГМЕНТ)
	// ============================================

	/**
	 * GET /api/profile
	 * 
	 * Возвращает HTML-фрагмент профиля для SPA-движка.
	 * Загружается асинхронно без перезагрузки страницы.
	 * 
	 * @return JsonResponse
	 */
	public function apiView(): JsonResponse
	{
		// Требуем авторизацию
		if ($error = $this->requireAuthForApi()) return $error;

		$userId = $this->session->get('user_id');

		// Загружаем пользователя
		$user = $this->neuronRepo->findById($userId);
		if (!$user || $user['type'] !== 'user') {
			return ApiResponse::error('Пользователь не найден', 404);
		}

		// Извлекаем данные
		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$roles = $this->getUserRoles();

		// Рендерим фрагмент (без боковых панелей)
		$html = $this->twig->render('profile-view.html.twig', [
			'user' => [
				'id'          => $user['id'],
				'login'       => $data['login'] ?? 'user',
				'lang'        => $data['lang'] ?? 'ru',
				'email'       => $data['email'] ?? '',
				'phone'       => $data['phone'] ?? '',
				'auth_method' => $data['auth_method'] ?? 'local',
				'created_at'  => $user['date'] ?? '',
			],
			'roles' => $roles,
		]);

		return ApiResponse::success(['html' => $html]);
	}
}