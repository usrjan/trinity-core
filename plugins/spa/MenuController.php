<?php

/**
 * КОНТРОЛЛЕР МЕНЮ
 * ================
 * 
 * Управление боковым меню и верхней панелью.
 * 
 * Публичные методы (доступны всем):
 * - sidebar()       — HTML бокового меню
 * - topbar()        — HTML верхней панели
 * 
 * Админские методы (только для role_admin):
 * - addItem()       — добавление пункта меню
 * - updateItem()    — обновление пункта меню
 * 
 * Пункты меню хранятся в нейронах type='tree' внутри MENU → PUBLIC.
 * Сортировка через data.sort (шаг 100, как в Битриксе).
 * 
 * Зависимости:
 * - TextRepository    — работа с текстами (названия пунктов)
 * - NeuronRepository  — работа с нейронами (поиск MENU, PUBLIC, children)
 * - GuardController   — CSRF-защита для админских методов
 * - AuthMiddleware    — проверка прав доступа
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Plugin\Guard\GuardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class MenuController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	/** @var GuardController — CSRF-защита */
	private GuardController $guard;

	/** @var ConfigService — сервис конфигурации */
	private ConfigService $configService;

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
		ConfigService $configService,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->guard = $guard;
		$this->configService = $configService;
		$this->validator = $validator;

		// Инициализация middleware авторизации
		$this->initAuth($session);
	}

	// ============================================
	// БОКОВОЕ МЕНЮ (ПУБЛИЧНЫЙ МЕТОД)
	// ============================================

	/**
	 * GET /api/menu/sidebar
	 * 
	 * Возвращает HTML бокового меню.
	 * Принимает параметры:
	 * - lang — язык названий (по умолчанию 'ru')
	 * - current_url — текущий URL для подсветки активного пункта
	 * 
	 * Доступен всем пользователям (включая гостей).
	 * 
	 * @param Request $request
	 * @return Response — HTML фрагмент для вставки в #sidebar
	 */
	public function sidebar(Request $request): Response
	{
		$lang = $request->query->get('lang', 'ru');
		$currentUrl = $request->query->get('current_url', '/');

		// Загружаем пункты меню из базы
		$menu = $this->getMenu($lang);

		// Данные пользователя для отображения
		$user = $this->session->get('user_login');
		$userRoles = $this->getUserRoles();

		// Рендерим шаблон
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
	 * Возвращает HTML верхней панели с информацией о пользователе.
	 * Для гостей показывает кнопку «Войти».
	 * Для авторизованных — имя, роль, выпадающее меню.
	 * 
	 * @param Request $request
	 * @return Response — HTML фрагмент для вставки в #topBar
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
	 * Добавляет новый пункт меню.
	 * Принимает JSON:
	 * - name      — название пункта
	 * - slug      — текстовый идентификатор
	 * - icon      — иконка Bootstrap (bi-house, bi-map...)
	 * - route     — URL-путь (/about, /map...)
	 * - is_plugin — true для полного перехода, false для SPA
	 * 
	 * Пункт добавляется в MENU → PUBLIC с автоматическим sort.
	 * 
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function addItem(Request $request): JsonResponse
	{
		// Проверка прав администратора
		if ($error = $this->requireAdminForApi()) return $error;

		// Извлекаем данные из запроса
		$rawBody = $request->getContent();
		$body = json_decode($rawBody, true);

		// [Лорелея]: Проверка JSON. Если битый — 400. Не 500.
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ApiResponse::error('Невалидный JSON', 400);
		}

		// [Мириам]: Если не массив — 400. Потому что дальше — $body['...'].
		if (!is_array($body)) {
			return ApiResponse::error('Тело запроса должно быть JSON-объектом', 400);
		}

		$name = $body['name'] ?? '';
		$slug = $body['slug'] ?? '';
		$icon = $body['icon'] ?? 'bi-file';
		$route = $body['route'] ?? '/';
		$isPlugin = (bool) ($body['is_plugin'] ?? false);

		// [Мириам]: Валидация. Через Validator. И name. И slug. И icon. И route.
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

		// Находим корень MENU
		$menuRoot = $this->neuronRepo->findBySlug('MENU');
		if (!$menuRoot) {
			return ApiResponse::error('MENU не найден', 500);
		}

		// Находим PUBLIC внутри MENU
		$public = $this->neuronRepo->findBySlugAndPid('PUBLIC', $menuRoot['id']);
		if (!$public) {
			return ApiResponse::error('PUBLIC не найден', 500);
		}

		// Создаём текст для названия пункта
		$textKey = $this->textRepo->findOrCreate('ru', $name);

		// Вычисляем следующий sort (максимальный + 100)
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

		// Создаём нейрон пункта меню
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
	 * Обновляет существующий пункт меню.
	 * Принимает JSON: id, name, slug, icon, route.
	 * 
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function updateItem(Request $request): JsonResponse
	{
		// Проверка прав администратора
		if ($error = $this->requireAdminForApi()) return $error;

		$rawBody = $request->getContent();
		$body = json_decode($rawBody, true);

		// [Лорелея]: Проверка JSON. Если битый — 400. Не 500.
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ApiResponse::error('Невалидный JSON', 400);
		}

		// [Мириам]: Если не массив — 400. Потому что дальше — $body['...'].
		if (!is_array($body)) {
			return ApiResponse::error('Тело запроса должно быть JSON-объектом', 400);
		}

		$id = (int) ($body['id'] ?? 0);
		$name = $body['name'] ?? '';
		$slug = $body['slug'] ?? '';
		$icon = $body['icon'] ?? 'bi-file';
		$route = $body['route'] ?? '/';

		// [Лорелея]: Валидация. Через Validator. Всё. В одном месте.
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

		// Проверяем существование пункта
		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Пункт меню не найден', 404);
		}

		// Обновляем текст (если изменилось название)
		if ($neuron['text']) {
			$textKey = $this->textRepo->findOrCreate('ru', $name);
			if ($textKey !== (int) $neuron['text']) {
				$this->neuronRepo->update($id, ['text' => $textKey]);
			}
		}

		// Обновляем data нейрона
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
	 * Загружает пункты меню из базы данных.
	 * 
	 * Структура: MENU → PUBLIC → пункты (type='tree').
	 * Для каждого пункта подтягивается название из таблицы text.
	 * Сортировка по data.sort, затем по id.
	 * 
	 * @param string $lang — язык названий (по умолчанию 'ru')
	 * @return array — ['items' => [...]]
	 */
	private function getMenu(string $lang = 'ru'): array
	{
		// Находим корень MENU
		$menuRoot = $this->neuronRepo->findBySlug('MENU');
		if (!$menuRoot) {
			return ['items' => []];
		}

		// Находим PUBLIC внутри MENU
		$public = $this->neuronRepo->findBySlugAndPid('PUBLIC', $menuRoot['id']);
		if (!$public) {
			return ['items' => []];
		}

		// Загружаем пункты меню с названиями
		$children = $this->neuronRepo->findChildrenWithText($public['id'], $lang);

		// Формируем результат
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