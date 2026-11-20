<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР ПРОФИЛЯ
 *
 * Управление профилем текущего пользователя.
 *
 * [Лорелея]: Потому что ProfileController — это не «редактор».
 * Это — «отражатель». Он показывает. А не меняет. Меняет —
 * ProfileEditController. А этот — только читает и рендерит.
 *
 * [Мириам]: И потому что «меньше зависимостей» — это не
 * «красиво». Это — «быстрее создаётся контейнером». И —
 * «меньше поводов сломаться». Потому что каждая зависимость —
 * это ещё один шанс, что её не передадут. И тогда — 500.
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class ProfileController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var NeuronRepository — загрузка пользователя. */
	private NeuronRepository $neuronRepo;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		NeuronRepository $neuronRepo
	) {
		$this->twig = $twig;
		$this->neuronRepo = $neuronRepo;

		$this->initAuth($session);
	}

	// ============================================
	// ПРОСМОТР ПРОФИЛЯ (ПОЛНАЯ СТРАНИЦА)
	// ============================================

	/**
	 * GET /profile
	 */
	public function view(): Response
	{
		if (!$this->isAuthenticated()) {
			return new RedirectResponse('/login?redirect=' . urlencode('/profile'));
		}

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