<?php

declare(strict_types=1);

/**
 * РЕДАКТОР ПРОФИЛЯ
 *
 * Управление редактированием профиля текущего пользователя.
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Service\CsrfService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class ProfileEditController
{
	// ============================================
	// ПАРАМЕТРЫ ХЕША — ОДИН ИСТОЧНИК
	// ============================================
	private const PASSWORD_HASH_ALGO = PASSWORD_BCRYPT;
	private const PASSWORD_HASH_OPTIONS = ['cost' => 12];

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var Session — сессия. */
	private Session $session;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — загрузка и обновление пользователя. */
	private NeuronRepository $neuronRepo;

	/** @var CsrfService — CSRF-защита. Один источник токена. */
	private CsrfService $csrfService;

	private Validator $validator;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		CsrfService $csrfService,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->session = $session;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->csrfService = $csrfService;
		$this->validator = $validator;
	}

	/**
	 * GET /profile/edit
	 */
	public function editForm(): Response
	{
		$userId = $this->session->get('user_id');
		if (!$userId) {
			return new RedirectResponse('/login?redirect=' . urlencode('/profile/edit'));
		}

		$user = $this->neuronRepo->findById($userId);
		if (!$user || $user['type'] !== 'user') {
			return new RedirectResponse('/login');
		}

		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$availableLangs = $this->textRepo->getAvailableLangs();
		$langNames = ['ru' => 'Русский', 'en' => 'English', 'it' => 'Italiano', 'de' => 'Deutsch', 'fr' => 'Français'];
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
			'csrf_token'      => $this->csrfService->getOrCreateToken(),
			'error'           => $error,
		]);

		return new Response($html);
	}

	/**
	 * POST /profile/edit
	 */
	public function edit(Request $request): Response
	{
		$userId = $this->session->get('user_id');
		if (!$userId) {
			return new RedirectResponse('/login?redirect=' . urlencode('/profile/edit'));
		}

		$csrfToken = $request->request->get('_csrf_token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			$this->session->set('profile_error', 'Недействительный CSRF-токен');
			return new RedirectResponse('/profile/edit');
		}

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
			'phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-()]+$/u'],
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

			$data['password_hash'] = password_hash($newPassword, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS);
		}

		$this->neuronRepo->update($userId, ['data' => $data]);

		return new RedirectResponse('/profile');
	}
}