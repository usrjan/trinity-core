<?php

/**
 * КОНТРОЛЛЕР ПРОФИЛЯ
 * ===================
 *
 * Управление профилем текущего пользователя.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Параметры хеша — в const.
 * Один источник. Для hash, needs_rehash, registration.
 * Без «даунгрейда». Без «пиздеца». Без «потом».
 *
 * [Мириам]: И — $_SESSION заменён на $session->get('csrf_token').
 * Везде. Чтобы был один источник. А не два. И — «навсегда».
 *
 * @package Jan\Trinity\Plugin\Users
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\GuardService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class ProfileController
{
	use AuthMiddleware;

	// ============================================
	// ПАРАМЕТРЫ ХЕША — ОДИН ИСТОЧНИК
	// ============================================
	// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Один const для алгоритма.
	// И — один const для опций. И — hash, needs_rehash, registration
	// используют их. Без «даунгрейда». Без «пиздеца».
	//
	// [Мириам]: И — если завтра захотим cost=13 — меняем в одном
	// месте. И — все три места — обновятся. И — «навсегда».
	private const PASSWORD_HASH_ALGO = PASSWORD_BCRYPT;
	private const PASSWORD_HASH_OPTIONS = ['cost' => 12];

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — загрузка и обновление пользователя */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — получение ролей */
	private SynapseRepository $synapseRepo;

	/** @var GuardService — CSRF-защита */
	private GuardService $guard;

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
	// ПРОСМОТР ПРОФИЛЯ (ПОЛНАЯ СТРАНИЦА)
	// ============================================

	/**
	 * GET /profile
	 */
	public function view(): Response
	{
		$this->requireAuth();

		$userId = $this->session->get('user_id');

		$user = $this->neuronRepo->findById($userId);
		if (!$user || $user['type'] !== 'user') {
			$this->session->clear();
			return new RedirectResponse('/login');
		}

		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$roles = $this->getUserRoles();

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
	 * [Мириам]: csrf_token — из $session->get('csrf_token').
	 * Один источник. Без $_SESSION. Без «пиздеца».
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

		$availableLangs = $this->textRepo->getAvailableLangs();

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

		$error = $this->session->get('profile_error');
		$this->session->remove('profile_error');

		// [Мириам]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. csrf_token — из Session.
		// Один источник. Без $_SESSION. Без «пиздеца».
		$html = $this->twig->render('profile-edit.html.twig', [
			'user' => [
				'login' => $data['login'] ?? '',
				'email' => $data['email'] ?? '',
				'phone' => $data['phone'] ?? '',
				'lang'  => $data['lang'] ?? 'ru',
			],
			'available_langs' => $langOptions,
			'csrf_token'      => $this->session->get('csrf_token', ''),
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
	 * [Лорелея]: needs_rehash — с нашими параметрами. А не с
	 * PASSWORD_DEFAULT. Без «даунгрейда». Без «пиздеца».
	 */
	public function edit(Request $request): Response
	{
		$this->requireAuth();

		$userId = $this->session->get('user_id');

		$lang = trim($request->request->get('lang', 'ru'));
		$email = strtolower(trim($request->request->get('email', '')));
		$phone = trim($request->request->get('phone', ''));
		$currentPassword = $request->request->get('current_password', '');
		$newPassword = $request->request->get('new_password', '');
		$passwordConfirm = $request->request->get('password_confirm', '');

		$user = $this->neuronRepo->findById($userId);
		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$data['lang'] = $lang;
		$data['email'] = $email;
		$data['phone'] = $phone;

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

			$hash = $data['password_hash'] ?? '';
			if (!password_verify($currentPassword, $hash)) {
				$this->session->set('profile_error', 'Неверный текущий пароль');
				return new RedirectResponse('/profile/edit');
			}

			if ($newPassword !== $passwordConfirm) {
				$this->session->set('profile_error', 'Пароли не совпадают');
				return new RedirectResponse('/profile/edit');
			}

			// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. hash — с нашими
			// параметрами. А не с PASSWORD_DEFAULT. Без «даунгрейда».
			// Без «пиздеца». Без «потом».
			$data['password_hash'] = password_hash($newPassword, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS);
		}

		$this->neuronRepo->update($userId, ['data' => $data]);

		return new RedirectResponse('/profile');
	}

	// ============================================
	// ПРОСМОТР ПРОФИЛЯ (SPA-ФРАГМЕНТ)
	// ============================================

	/**
	 * GET /api/profile
	 */
	public function apiView(): JsonResponse
	{
		if ($error = $this->requireAuthForApi()) return $error;

		$userId = $this->session->get('user_id');

		$user = $this->neuronRepo->findById($userId);
		if (!$user || $user['type'] !== 'user') {
			return ApiResponse::error('Пользователь не найден', 404);
		}

		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$roles = $this->getUserRoles();

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