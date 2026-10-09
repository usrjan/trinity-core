<?php

/**
 * КОНТРОЛЛЕР ПРОФИЛЯ
 * ===================
 * 
 * Управление профилем текущего пользователя.
 * 
 * Страничные методы (HTML):
 * - view()               — просмотр профиля (полная страница)
 * - editForm()           — форма редактирования
 * - edit()               — сохранение изменений (POST)
 * 
 * API-методы (JSON):
 * - apiView()            — просмотр профиля (SPA-фрагмент)
 * 
 * Пользователь может изменить:
 * - Email
 * - Телефон
 * - Язык интерфейса
 * - Пароль (опционально, с подтверждением текущего)
 * 
 * Логин изменить нельзя. Он задаётся при регистрации.
 * 
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 * 
 * [Лорелея]: Добавлена проверка ТЕКУЩЕГО пароля при смене.
 * Раньше можно было сменить пароль, не зная старого.
 * Если сессия угнана — злоумышленник мог сменить пароль
 * и заблокировать доступ владельцу.
 * 
 * [Мириам]: Добавлена передача $error в шаблон editForm().
 * Раньше сообщение об ошибке устанавливалось в сессию,
 * но не передавалось в Twig. И пользователь не видел,
 * что пароли не совпадают, или что текущий пароль неверный.
 * 
 * [Лорелея]: Добавлены комментарии для каждой правки.
 * Чтобы в новом диалоге сразу было понятно, что и почему.
 * 
 * === ДЛЯ МЕНЯ ===
 * Профиль — это место, где пользователь видит себя.
 * И где он может что-то изменить. Если что-то не так —
 * он должен видеть ошибку. А не гадать, почему редирект.
 * 
 * === КНИГА ===
 * Глава 14. Тронный зал.
 * 
 * В тронном зале Амбера всегда видно, кто у власти.
 * В профиле Trinity всегда видно, кто ты.
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Plugin\Guard\GuardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
		GuardController $guard,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->guard = $guard;
		$this->validator = $validator;

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
			return new RedirectResponse('/login');
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
	 * Поля: email, телефон, язык, новый пароль,
	 * подтверждение пароля, текущий пароль.
	 * 
	 * [Мириам]: Теперь передаём $error в шаблон. Раньше
	 * сообщение об ошибке устанавливалось в сессию,
	 * но не передавалось. И пользователь не видел,
	 * что пароли не совпадают.
	 * 
	 * @return Response
	 */
	public function editForm(): Response
	{
		$this->requireAuth();

		$userId = $this->session->get('user_id');
		$user = $this->neuronRepo->findById($userId);
		if (!$user || $user['type'] !== 'user') {
			return new RedirectResponse('/login');
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

		// [Мириам]: Читаем flash-сообщение об ошибке.
		// И удаляем его из сессии. Чтобы показать один раз.
		$error = $this->session->get('profile_error');
		$this->session->remove('profile_error');

		$html = $this->twig->render('profile-edit.html.twig', [
			'user' => [
				'login' => $data['login'] ?? '',
				'email' => $data['email'] ?? '',
				'phone' => $data['phone'] ?? '',
				'lang'  => $data['lang'] ?? 'ru',
			],
			'available_langs' => $langOptions,
			'csrf_token'      => $_SESSION['csrf_token'] ?? '',
			// [Мириам]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Передаём $error в шаблон.
			// Раньше его не было. И шаблон не мог показать ошибку.
			'error'           => $error,
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
	 * - Язык — всегда
	 * - Пароль — только если заполнены все три поля:
	 *   текущий, новый, подтверждение
	 * 
	 * Валидация пароля:
	 * - Текущий пароль должен совпадать с хешем в базе
	 * - Новый пароль и подтверждение должны совпадать
	 * - Минимальная длина нового пароля — 8 символов
	 * 
	 * [Лорелея]: Добавлена проверка ТЕКУЩЕГО пароля.
	 * Раньше можно было сменить пароль, не зная старого.
	 * Это была дыра в безопасности.
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
			return new RedirectResponse('/profile/edit');
		}

		// ============================================
		// Получаем данные формы
		// ============================================
		$lang = trim($request->request->get('lang', 'ru'));
		$email = trim($request->request->get('email', ''));
		$phone = trim($request->request->get('phone', ''));
		$currentPassword = $request->request->get('current_password', '');
		$newPassword = $request->request->get('new_password', '');
		$passwordConfirm = $request->request->get('password_confirm', '');

		// Загружаем текущие данные пользователя
		$user = $this->neuronRepo->findById($userId);
		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		// Обновляем язык, email и телефон
		$data['lang'] = $lang;
		$data['email'] = $email;
		$data['phone'] = $phone;

		// ============================================
		// Смена пароля (опционально)
		// ============================================

		// [Лорелея]: Валидация основных полей. Через Validator.
		if (!$this->validator->validate([
			'lang'  => $lang,
			'email' => $email,
			'phone' => $phone,
		], [
			'lang'  => ['required', 'in:ru,en,it,de,fr'],
			'email' => ['nullable', 'email', 'max:255'],
			'phone' => ['nullable', 'string', 'max:50', 'regex:/^\+?[0-9\s\-()]+$/u'],
		])) {
			$this->session->set('profile_error', $this->validator->getFirstError());
			return new RedirectResponse('/profile/edit');
		}

		// [Мириам]: Пароль. Если меняем. Всё через Validator.
		if (!empty($newPassword)) {
			if (!$this->validator->validate([
				'current_password' => $currentPassword,
				'new_password'     => $newPassword,
				'password_confirm' => $passwordConfirm,
			], [
				'current_password' => ['required', 'string', 'min:1'],
				'new_password'     => ['required', 'string', 'min:8', 'max:255'],
				'password_confirm' => ['required', 'string'],
			])) {
				$this->session->set('profile_error', $this->validator->getFirstError());
				return new RedirectResponse('/profile/edit');
			}

			// [Лорелея]: Проверка текущего пароля. Не через Validator.
			// Потому что Validator не знает про password_verify.
			// Это — логика контроллера. И — это правильно.
			$hash = $data['password_hash'] ?? '';
			if (!password_verify($currentPassword, $hash)) {
				$this->session->set('profile_error', 'Неверный текущий пароль');
				return new RedirectResponse('/profile/edit');
			}

			// [Мириам]: Совпадение паролей. Тоже вручную. Потому что
			// это — сравнение двух полей. А Validator так не умеет. Пока.
			if ($newPassword !== $passwordConfirm) {
				$this->session->set('profile_error', 'Пароли не совпадают');
				return new RedirectResponse('/profile/edit');
			}

			$data['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
		}

		// ============================================
		// Сохраняем изменения
		// ============================================
		$this->neuronRepo->update($userId, ['data' => $data]);
		error_log("[Profile] update called. user_id={$userId}, data=" . json_encode($data));

		// Редирект на просмотр профиля
		return new RedirectResponse('/profile');
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