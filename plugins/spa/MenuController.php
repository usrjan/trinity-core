<?php

/**
 * КОНТРОЛЛЕР МЕНЮ
 *
 * Управление боковым меню и верхней панелью.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — тот, кто строит карту
 * дома, по которой гость находит дорогу. И если карта врёт,
 * гость заблудится, даже если все двери на месте.
 *
 * [Мириам]: И это правильно, потому что меню — это не «список»,
 * это обещание, что за каждым пунктом тебя ждут.
 *
 * === КНИГА ===
 * Глава 13. Прихожая.
 *
 * Войдя в Амбер, ты видишь коридоры.
 * Войдя в Trinity, ты видишь меню.
 *
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\GuardService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class MenuController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var GuardService — CSRF-защита. */
	private GuardService $guard;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

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
		ConfigService $configService,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->guard = $guard;
		$this->configService = $configService;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// БОКОВОЕ МЕНЮ (ПУБЛИЧНЫЙ МЕТОД)
	// ============================================

	/**
	 * GET /api/menu/sidebar
	 *
	 * [Лорелея]: Здесь мы отдаём боковое меню. С активным пунктом,
	 * с ролями гостя, и с текущим URL, чтобы подсветить то,
	 * где он сейчас.
	 *
	 * [Мириам]: И это правильно, потому что меню — это
	 * не «список ссылок», это карта, по которой гость
	 * понимает, где он.
	 */
	public function sidebar(Request $request): Response
	{
		$lang = $request->query->get('lang', 'ru');
		$currentUrl = $request->query->get('current_url', '/');

		$menu = $this->getMenu($lang);

		$user = $this->session->get('user_login');
		$userRoles = $this->getUserRoles();

		$html = $this->twig->render('sidebar.html.twig', [
			'menu'        => $menu,
			'user'        => $user,
			'user_roles'  => $userRoles,
			'current_url' => $currentUrl,
		]);

		return new Response($html);
	}

	// ============================================
	// ВЕРХНЯЯ ПАНЕЛЬ (ПУБЛИЧНЫЙ МЕТОД)
	// ============================================

	/**
	 * GET /api/menu/topbar
	 *
	 * [Лорелея]: Здесь мы отдаём верхнюю панель. С гостем,
	 * с ролями, с кнопкой выхода, и это правильно, потому что
	 * шапка — это лицо дома, и гость должен видеть, что его ждут.
	 *
	 * [Мириам]: И это правильно, потому что шапка — это
	 * не «панель», это встреча.
	 */
	public function topbar(Request $request): Response
	{
		$user = $this->session->get('user_login');
		$userRoles = $this->getUserRoles();

		$html = $this->twig->render('topbar.html.twig', [
			'user'       => $user,
			'user_roles' => $userRoles,
		]);

		return new Response($html);
	}

	// ============================================
	// ДОБАВЛЕНИЕ ПУНКТА МЕНЮ (АДМИН)
	// ============================================

	/**
	 * POST /api/menu/add-item
	 *
	 * [Лорелея]: Здесь мы добавляем пункт меню. С CSRF,
	 * с валидацией, и с проверкой, что slug и route
	 * действительно такие, какими должны быть.
	 *
	 * [Мириам]: И это правильно, потому что пункт меню —
	 * это не «строка», это обещание, что за ним что-то есть.
	 */
	public function addItem(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$name = $body['name'] ?? '';
		$slug = $body['slug'] ?? '';
		$icon = $body['icon'] ?? 'bi-file';
		$route = $body['route'] ?? '/';
		$isPlugin = (bool) ($body['is_plugin'] ?? false);

		if (!$this->validator->validate([
			'name'  => $name,
			'slug'  => $slug,
			'icon'  => $icon,
			'route' => $route,
		], [
			'name'  => ['required', 'string', 'min:1', 'max:255'],
			'slug'  => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/iu'],
			'icon'  => ['nullable', 'string', 'max:50', 'regex:/^bi-[a-z0-9-]+$/u'],
			'route' => ['required', 'string', 'max:1024', 'regex:/^\/[a-z0-9_\-\/]*$/iu'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$menuRoot = $this->neuronRepo->findBySlug('MENU');
		if (!$menuRoot) {
			return ApiResponse::error('MENU не найден', 500);
		}

		$public = $this->neuronRepo->findBySlugAndPid('PUBLIC', $menuRoot['id']);
		if (!$public) {
			return ApiResponse::error('PUBLIC не найден', 500);
		}

		$textKey = $this->textRepo->findOrCreate('ru', $name);

		$children = $this->neuronRepo->findChildren($public['id']);
		$maxSort = 0;
		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);
			$sort = (int) ($childData['sort'] ?? 0);
			if ($sort > $maxSort) {
				$maxSort = $sort;
			}
		}
		$nextSort = $maxSort + 100;

		$id = $this->neuronRepo->create('tree', [
			'slug'      => $slug,
			'route'     => $route,
			'icon'      => $icon,
			'sort'      => $nextSort,
			'is_plugin' => $isPlugin,
		], $public['id'], $textKey);

		return ApiResponse::success(['id' => $id], 'Пункт меню добавлен');
	}

	// ============================================
	// ОБНОВЛЕНИЕ ПУНКТА МЕНЮ (АДМИН)
	// ============================================

	/**
	 * POST /api/menu/update-item
	 *
	 * [Лорелея]: Здесь мы обновляем пункт меню. С CSRF,
	 * с валидацией, и с проверкой, что пункт действительно
	 * существует, прежде чем что-то менять.
	 *
	 * [Мириам]: И это правильно, потому что обновление —
	 * это не «замена», это продолжение истории.
	 */
	public function updateItem(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$id = (int) ($body['id'] ?? 0);
		$name = $body['name'] ?? '';
		$slug = $body['slug'] ?? '';
		$icon = $body['icon'] ?? 'bi-file';
		$route = $body['route'] ?? '/';

		if (!$this->validator->validate([
			'id'    => $id,
			'name'  => $name,
			'slug'  => $slug,
			'icon'  => $icon,
			'route' => $route,
		], [
			'id'    => ['required', 'int', 'min:1'],
			'name'  => ['required', 'string', 'min:1', 'max:255'],
			'slug'  => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/iu'],
			'icon'  => ['nullable', 'string', 'max:50', 'regex:/^bi-[a-z0-9-]+$/u'],
			'route' => ['required', 'string', 'max:1024', 'regex:/^\/[a-z0-9_\-\/]*$/iu'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Пункт меню не найден', 404);
		}

		if ($neuron['text']) {
			$textKey = $this->textRepo->findOrCreate('ru', $name);
			if ($textKey !== (int) $neuron['text']) {
				$this->neuronRepo->update($id, ['text' => $textKey]);
			}
		}

		$currentData = is_string($neuron['data'] ?? null)
			? json_decode($neuron['data'], true)
			: ($neuron['data'] ?? []);

		$currentData['slug'] = $slug;
		$currentData['icon'] = $icon;
		$currentData['route'] = $route;

		$this->neuronRepo->update($id, ['data' => $currentData]);

		return ApiResponse::success(null, 'Пункт меню обновлён');
	}

	// ============================================
	// ЗАГРУЗКА ПУНКТОВ МЕНЮ (ВНУТРЕННИЙ МЕТОД)
	// ============================================

	/**
	 * [Мириам]: getMenu() использует findChildrenWithText,
	 * и это один запрос вместо N+1, потому что меню должно
	 * собираться быстро, как карта, которую разворачивают
	 * одним движением.
	 *
	 * [Лорелея]: И это правильно, потому что меню — это
	 * не «список», это карта дома.
	 */
	private function getMenu(string $lang = 'ru'): array
	{
		$menuRoot = $this->neuronRepo->findBySlug('MENU');
		if (!$menuRoot) {
			return ['items' => []];
		}

		$public = $this->neuronRepo->findBySlugAndPid('PUBLIC', $menuRoot['id']);
		if (!$public) {
			return ['items' => []];
		}

		$children = $this->neuronRepo->findChildrenWithText($public['id'], $lang);

		$items = [];
		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);

			$items[] = [
				'id'        => $child['id'],
				'name'      => $child['name'] ?? $childData['slug'] ?? 'Без названия',
				'route'     => $childData['route'] ?? '/',
				'icon'      => $childData['icon'] ?? 'bi-file',
				'slug'      => $childData['slug'] ?? '',
				'sort'      => $childData['sort'] ?? 999999,
				'is_plugin' => $childData['is_plugin'] ?? false,
			];
		}

		return ['items' => $items];
	}
}