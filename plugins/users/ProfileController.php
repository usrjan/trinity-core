<?php

/**
 * КОНТРОЛЛЕР ПРОФИЛЯ
 *
 * Управление профилем текущего пользователя.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — тот, кто показывает
 * гостю его собственное отражение, и от того, как он это делает,
 * зависит, узнает ли гость себя в этом отражении.
 *
 * [Мириам]: И это правильно, потому что профиль — это не «анкета»,
 * это место, где человек встречается с собой.
 *
 * === КНИГА ===
 * Глава 14. Тронный зал.
 *
 * В тронном зале Амбера всегда видно, кто у власти.
 * В профиле Trinity всегда видно, кто ты.
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
	// [Лорелея]: Один алгоритм, одни опции, и все места, где
	// создаётся хеш, используют именно их, чтобы не было
	// даунгрейда и рассинхрона.
	private const PASSWORD_HASH_ALGO = PASSWORD_BCRYPT;
	private const PASSWORD_HASH_OPTIONS = ['cost' => 12];

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — загрузка и обновление пользователя. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — получение ролей. */
	private SynapseRepository $synapseRepo;

	/** @var GuardService — CSRF-защита. */
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
	// ПРОСМОТР ПРОФИЛЯ (ПОЛНАЯ СТРАНИЦА)
	// ============================================

	/**
	 * GET /profile
	 *
	 * [Лорелея]: Здесь мы показываем профиль целиком. Если
	 * пользователя нет или он не тот, за кого себя выдаёт,
	 * мы очищаем сессию и ведём его к двери.
	 *
	 * [Мириам]: И это правильно, потому что отражение должно
	 * быть честным, даже если оно кому-то не нравится.
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
	 * [Лорелея]: Здесь мы открываем форму редактирования.
	 * csrf_token берём из сессии, потому что один источник
	 * лучше, чем два.
	 *
	 * [Мириам]: И это правильно, потому что доверие начинается
	 * с того, что всё в одном месте.
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
	 * [Лорелея]: Здесь мы сохраняем изменения. Сначала валидация,
	 * потом пароль, если его меняют, и только потом обновление,
	 * потому что изменения должны быть осознанными.
	 *
	 * [Мириам]: И это правильно, потому что профиль — это
	 * не «настройки», это то, как человек видит себя.
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

			// [Лорелея]: Новый хеш — с нашими параметрами, а не
			// с PASSWORD_DEFAULT, потому что даунгрейд — это
			// не «мелочь», а дыра.
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
	 *
	 * [Лорелея]: Здесь мы отдаём профиль как фрагмент, для SPA.
	 * Если пользователя нет — говорим об этом через ApiResponse,
	 * потому что API не должно падать.
	 *
	 * [Мириам]: И это правильно, потому что даже отражение
	 * должно уметь отвечать.
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