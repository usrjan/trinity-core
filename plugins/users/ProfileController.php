<?php
declare(strict_types=1);

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

	private Validator $validator;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

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
		if (!$this->isAuthenticated()) {
			return new RedirectResponse('/login?redirect=' . urlencode('/profile'));
		}

		// [Лорелея]: user_id берётся строго из сессии, а не из запроса,
		// потому что IDOR — это когда пользователь подставляет чужой id,
		// и это не должно быть возможно.
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

		// [Лорелея]: user_id берётся строго из сессии, а не из запроса,
		// потому что IDOR — это когда пользователь подставляет чужой id,
		// и это не должно быть возможно.
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