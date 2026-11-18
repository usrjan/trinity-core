<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР ПРОФИЛЯ
 *
 * Управление профилем текущего пользователя.
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class ProfileController
{
	use AuthMiddleware;

	// ============================================
	// ПАРАМЕТРЫ ХЕША — ОДИН ИСТОЧНИК
	// ============================================
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

	private Validator $validator;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		Validator $validator,
		LoggerInterface $logger
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->validator = $validator;
		$this->logger = $logger;

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