<?php

/**
 * КОНТРОЛЛЕР СТРАНИЦ
 * ===================
 * 
 * Управление контентными страницами и их секциями.
 * Страницы загружаются через SPA-движок асинхронно.
 * 
 * Публичные методы:
 * - show(slug)          — просмотр страницы (JSON с HTML)
 * 
 * Админские методы (только для role_admin):
 * - addSection(slug)    — добавление секции на страницу
 * - updateSection(id)   — обновление секции
 * - deleteSection(id)   — удаление секции (мягкое)
 * 
 * Структура:
 * PAGES (tree) → страница (tree) → секции (item)
 * Каждая секция связана с текстом через text.key.
 * 
 * Зависимости:
 * - TextRepository    — работа с текстами секций
 * - NeuronRepository  — работа с нейронами страниц и секций
 * - GuardController   — CSRF-защита для админских методов
 * - AuthMiddleware    — проверка прав доступа
 */

namespace Jan\Trinity\Plugin\Spa;

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

class PageController
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
	// ПРОСМОТР СТРАНИЦЫ (ПУБЛИЧНЫЙ МЕТОД)
	// ============================================

	/**
	 * GET /api/page/{slug}
	 * 
	 * Возвращает JSON с HTML-контентом страницы для SPA-движка.
	 * 
	 * Алгоритм:
	 * 1. Находит PAGES (корень контентных страниц)
	 * 2. Внутри PAGES ищет страницу по slug
	 * 3. Загружает дочерние нейроны (секции)
	 * 4. Для каждой секции подтягивает текст из таблицы text
	 * 5. Поддерживает HTML-контент (data.mime = 'text/html')
	 * 
	 * @param string $slug — slug страницы
	 * @param Request $request — содержит ?lang=ru
	 * @return Response — JSON {success, data: {title, html}}
	 */
	public function show(string $slug, Request $request): Response
	{
		$lang = $request->query->get('lang', 'ru');
		$allLangs = $request->query->get('all_langs') === '1';

		// Находим корень PAGES
		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			return ApiResponse::error('PAGES не найден', 500);
		}

		// Ищем страницу внутри PAGES
		$page = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$page) {
			return ApiResponse::error('Страница не найдена', 404);
		}

		// Собираем хлебные крошки (поднимаемся по pid к корню PAGES)
		$breadcrumbs = [];
		$current = $page;
		while ($current) {
			$name = 'Без названия';
			if ($current['text']) {
				// Сначала ищем на нужном языке
				$text = $this->textRepo->findByKeyAndLang($current['text'], $lang);
				
				// Если нет — ищем любой доступный язык
				if (!$text || !$text['name']) {
					$allTexts = $this->textRepo->findAllByKey($current['text']);
					foreach ($allTexts as $t) {
						if (!empty($t['name'])) {
							$text = $t;
							break;
						}
					}
				}
				
				if ($text && $text['name']) {
					$name = $text['name'];
				}
			}
			if (!$name || $name === 'Без названия') {
				$currentData = is_string($current['data'] ?? null) ? json_decode($current['data'], true) : ($current['data'] ?? []);
				$name = $currentData['slug'] ?? 'Без названия';
			}
			
			array_unshift($breadcrumbs, [
				'name' => $name,
				'slug' => $currentData['slug'] ?? '',
			]);
			
			if ($current['pid']) {
				$current = $this->neuronRepo->findById($current['pid']);
				// Останавливаемся на PAGES (не показываем его)
				$currentData = is_string($current['data'] ?? null) ? json_decode($current['data'], true) : ($current['data'] ?? []);
				if (($currentData['slug'] ?? '') === 'PAGES') break;
			} else {
				break;
			}
		}

		// Загружаем дочерние нейроны (секции страницы)
		$children = $this->neuronRepo->findChildren($page['id']);

		// Собираем секции (item'ы с текстом)
		$sections = [];
		// Собираем подстраницы (tree)
		$subPages = [];

		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);

			if ($child['type'] === 'tree') {
				// Подстраница
				$subPages[] = [
					'id'   => $child['id'],
					'name' => $child['name'] ?? $childData['slug'] ?? 'Без названия',
					'slug' => $childData['slug'] ?? '',
				];
			} else {
				// Секция (item)
				// Загружаем текст секции
				if ($child['text']) {
					if ($allLangs) {
						// Админ — все языки
						$allTexts = $this->textRepo->findAllByKey($child['text']);
					} else {
						// Обычный пользователь — фильтруем по языку
						$allTexts = $this->textRepo->findAllByKeyAndLang($child['text'], $lang);
						
						// Если на этом языке нет — берём любой
						if (empty($allTexts)) {
							$allTexts = $this->textRepo->findAllByKey($child['text']);
						}
					}
					
					// Группируем тексты
					$texts = [];
					foreach ($allTexts as $t) {
						$texts[] = [
							'text_id' => $t['id'],
							'lang'    => $t['lang'] ?? 'ru',
							'name'    => $t['name'] ?? '',
							'text'    => $t['text'] ?? '',
						];
					}
					
					$sections[] = [
						'id'      => $child['id'],
						'sort'    => $childData['sort'] ?? 999999,
						'is_html' => ($childData['mime'] ?? '') === 'text/html',
						'texts'   => $texts,
					];
				}
			}
		}

		// Определяем заголовок страницы
		$pageData = is_string($page['data'] ?? null)
			? json_decode($page['data'], true)
			: ($page['data'] ?? []);

		$pageTitle = $pageData['title'] ?? $slug;

		// Если у страницы есть текст — используем его название
		if ($page['text']) {
			$titleText = $this->textRepo->findByKeyAndLang($page['text'], $lang);
			if ($titleText && $titleText['name']) {
				$pageTitle = $titleText['name'];
			}
		}

		// Проверяем права администратора (для inline-редактора)
		$isAdmin = $this->isAdmin();

		// Рендерим HTML страницы
		$html = $this->twig->render('page.html.twig', [
			'title'    => $pageTitle,
			'sections' => $sections,
			'sub_pages' => $subPages,
			'is_admin' => $isAdmin,
			'page_id'   => $page['id'],
		]);

		return ApiResponse::success([
			'title' => $pageTitle,
			'html'  => $html,
			'breadcrumbs' => $breadcrumbs,
		]);
	}

	// ============================================
	// ДОБАВЛЕНИЕ СЕКЦИИ (АДМИН)
	// ============================================

	/**
	 * POST /api/page/{slug}/section
	 * 
	 * Добавляет новую секцию на страницу.
	 * Принимает JSON: sort, lang, name, text.
	 * 
	 * Создаёт запись в таблице text и нейрон type='item'.
	 * 
	 * @param string $slug — slug страницы
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function addSection(string $slug, Request $request): JsonResponse
	{
		// Проверка прав администратора
		if ($error = $this->requireAdminForApi()) return $error;

		// CSRF-защита
		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		// Извлекаем данные
		$body = json_decode($request->getContent(), true);
		$sort = (int) ($body['sort'] ?? 100);
		$lang = $body['lang'] ?? 'ru';
		$name = trim($body['name'] ?? '');
		$text = trim($body['text'] ?? '');

		// Валидация: хотя бы одно поле должно быть заполнено
		if (empty($name) && empty($text)) {
			return ApiResponse::error('Название или текст обязательны', 400);
		}

		// Находим корень PAGES
		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			return ApiResponse::error('PAGES не найден', 500);
		}

		// Находим страницу
		$page = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$page) {
			return ApiResponse::error('Страница не найдена', 404);
		}

		// Создаём текст для секции
		$textKey = $this->textRepo->findOrCreate($lang, $name ?: null, $text ?: null);

		// Создаём нейрон секции (item внутри страницы)
		$sectionId = $this->neuronRepo->create('item', [
			'sort' => $sort,
		], $page['id'], $textKey);

		return ApiResponse::success([
			'id'   => $sectionId,
			'sort' => $sort,
			'lang' => $lang,
			'name' => $name,
			'text' => $text,
		]);
	}

	// ============================================
	// ОБНОВЛЕНИЕ СЕКЦИИ (АДМИН)
	// ============================================

	/**
	 * POST /api/page/section/{id}
	 * 
	 * Обновляет существующую секцию.
	 * Принимает JSON: sort, lang, name, text.
	 * 
	 * @param int $id — id нейрона секции
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function updateSection(int $id, Request $request): JsonResponse
	{
		// Проверка прав администратора
		if ($error = $this->requireAdminForApi()) return $error;

		// CSRF-защита
		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = json_decode($request->getContent(), true);

		// Проверяем существование секции
		$section = $this->neuronRepo->findById($id);
		if (!$section) {
			return ApiResponse::error('Секция не найдена', 404);
		}

		// Обновляем текст (если есть изменения)
		if ($section['text'] && isset($body['name'])) {
			$this->textRepo->findOrCreate(
				$body['lang'] ?? 'ru',
				$body['name'],
				$body['text'] ?? null
			);
		}

		// Обновляем sort в data нейрона
		if (isset($body['sort'])) {
			$currentData = is_string($section['data'] ?? null)
				? json_decode($section['data'], true)
				: ($section['data'] ?? []);
			$currentData['sort'] = (int) $body['sort'];
			$this->neuronRepo->update($id, ['data' => $currentData]);
		}

		return ApiResponse::success(['id' => $id]);
	}

	/**
	 * POST /api/page/section/{id}/update-full
	 * Обновляет секцию полностью: sort и все тексты.
	 */
	public function updateSectionFull(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$section = $this->neuronRepo->findById($id);
		if (!$section) {
			return ApiResponse::error('Секция не найдена', 404);
		}

		$body = json_decode($request->getContent(), true);
		$sort = (int) ($body['sort'] ?? 100);
		$texts = $body['texts'] ?? [];

		// Обновляем sort
		$currentData = is_string($section['data'] ?? null)
			? json_decode($section['data'], true)
			: ($section['data'] ?? []);
		$currentData['sort'] = $sort;
		$this->neuronRepo->update($id, ['data' => $currentData]);

		// Обновляем тексты: удаляем старые, создаём новые с тем же key
		$textKey = $section['text'];
		if ($textKey) {
			// Помечаем старые тексты как неактивные
			$conn = $this->neuronRepo->getConnection();
			$conn->executeStatement(
				'UPDATE text SET is_active = 0 WHERE `key` = ?',
				[$textKey]
			);

			// Создаём новые тексты
			foreach ($texts as $t) {
				$lang = $t['lang'] ?? 'ru';
				$name = !empty($t['name']) ? trim($t['name']) : null;
				$text = !empty($t['text']) ? trim($t['text']) : null;

				if ($name || $text) {
					$this->textRepo->addLang($lang);
					$conn->executeStatement(
						'INSERT INTO text (`key`, lang, name, text) VALUES (?, ?, ?, ?)',
						[$textKey, $lang, $name, $text]
					);
				}
			}
		}

		return ApiResponse::success(['id' => $id]);
	}

	// ============================================
	// УДАЛЕНИЕ СЕКЦИИ (АДМИН)
	// ============================================

	/**
	 * DELETE /api/page/section/{id}/delete
	 * 
	 * Мягкое удаление секции (deleted_at в data).
	 * Физически запись не удаляется.
	 * 
	 * @param int $id — id нейрона секции
	 * @param Request $request — для CSRF-проверки
	 * @return JsonResponse
	 */
	public function deleteSection(int $id, Request $request): JsonResponse
	{
		// Проверка прав администратора
		if ($error = $this->requireAdminForApi()) return $error;

		// CSRF-защита
		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		// Проверяем существование секции
		$section = $this->neuronRepo->findById($id);
		if (!$section) {
			return ApiResponse::error('Секция не найдена', 404);
		}

		// Мягкое удаление (без удаления синапсов)
		$this->neuronRepo->delete($id, false);

		return ApiResponse::success(['id' => $id]);
	}

	/**
	 * POST /api/page/{slug}/subpage
	 * Создаёт дочернюю подстраницу (tree) внутри текущей страницы.
	 * Slug опциональный — если не указан, подстраница доступна только через список.
	 */
	public function addSubPage(string $slug, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$body = json_decode($request->getContent(), true);
		$name = $body['name'] ?? '';
		$subSlug = $body['slug'] ?? '';

		if (empty($name)) {
			return ApiResponse::error('Название обязательно');
		}

		// Находим PAGES
		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			return ApiResponse::error('PAGES не найден', 500);
		}

		// Находим родительскую страницу
		$parentPage = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$parentPage) {
			return ApiResponse::error('Родительская страница не найдена', 404);
		}

		// Создаём текст
		$textKey = $this->textRepo->findOrCreate('ru', $name);

		// Данные подстраницы
		$subPageData = [];
		if (!empty($subSlug)) {
			$subPageData['slug'] = $subSlug;
			$subPageData['route'] = '/' . $subSlug;
		}

		// Создаём подстраницу
		$id = $this->neuronRepo->create('tree', $subPageData, $parentPage['id'], $textKey);

		return ApiResponse::success(['id' => $id], 'Подстраница создана');
	}

	/**
	 * GET /api/page/sub/{id}
	 * Загружает подстраницу по ID нейрона (для подстраниц без slug).
	 */
	public function subPage(int $id): JsonResponse
	{
		$page = $this->neuronRepo->findById($id);
		if (!$page) {
			return ApiResponse::error('Подстраница не найдена', 404);
		}

		$lang = 'ru';

		// Собираем хлебные крошки (поднимаемся по pid к корню PAGES)
		$breadcrumbs = [];
		$current = $page;
		while ($current) {
			$name = 'Без названия';
			if ($current['text']) {
				// Сначала ищем на нужном языке
				$text = $this->textRepo->findByKeyAndLang($current['text'], $lang);
				
				// Если нет — ищем любой доступный язык
				if (!$text || !$text['name']) {
					$allTexts = $this->textRepo->findAllByKey($current['text']);
					foreach ($allTexts as $t) {
						if (!empty($t['name'])) {
							$text = $t;
							break;
						}
					}
				}
				
				if ($text && $text['name']) {
					$name = $text['name'];
				}
			}
			if (!$name || $name === 'Без названия') {
				$currentData = is_string($current['data'] ?? null) ? json_decode($current['data'], true) : ($current['data'] ?? []);
				$name = $currentData['slug'] ?? 'Без названия';
			}
			
			array_unshift($breadcrumbs, [
				'name' => $name,
				'slug' => $currentData['slug'] ?? '',
			]);
			
			if ($current['pid']) {
				$current = $this->neuronRepo->findById($current['pid']);
				// Останавливаемся на PAGES (не показываем его)
				$currentData = is_string($current['data'] ?? null) ? json_decode($current['data'], true) : ($current['data'] ?? []);
				if (($currentData['slug'] ?? '') === 'PAGES') break;
			} else {
				break;
			}
		}

		// Загружаем дочерние нейроны
		$children = $this->neuronRepo->findChildren($page['id']);

		// Собираем секции и подстраницы
		$sections = [];
		$subPages = [];

		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);

			if ($child['type'] === 'tree') {
				$subPages[] = [
					'id'   => $child['id'],
					'name' => $child['name'] ?? $childData['slug'] ?? 'Без названия',
					'slug' => $childData['slug'] ?? '',
				];
			} else {
				if ($child['text']) {
					$texts = $this->textRepo->findAllByKeyAndLang($child['text'], $lang);
					foreach ($texts as $text) {
						$sections[] = [
							'id'      => $child['id'],
							'text_id' => $text['id'],
							'sort'    => $childData['sort'] ?? 999999,
							'lang'    => $text['lang'] ?? 'ru',
							'name'    => $text['name'] ?? '',
							'text'    => $text['text'] ?? '',
							'is_html' => ($childData['mime'] ?? '') === 'text/html',
						];
					}
				}
			}
		}

		// Заголовок
		$pageData = is_string($page['data'] ?? null)
			? json_decode($page['data'], true)
			: ($page['data'] ?? []);

		$pageTitle = $pageData['title'] ?? ($pageData['slug'] ?? 'Подстраница');

		if ($page['text']) {
			$titleText = $this->textRepo->findByKeyAndLang($page['text'], $lang);
			if ($titleText && $titleText['name']) {
				$pageTitle = $titleText['name'];
			}
		}

		$isAdmin = $this->isAdmin();

		$html = $this->twig->render('page.html.twig', [
			'title'     => $pageTitle,
			'sections'  => $sections,
			'sub_pages' => $subPages,
			'is_admin'  => $isAdmin,
			'page_id'   => $page['id'],
		]);

		return ApiResponse::success([
			'html' => $html,
			'breadcrumbs' => $breadcrumbs,
		]);
	}

	/**
	 * POST /api/page/section/{pageId}/add
	 * Добавляет секцию на страницу по ID страницы.
	 */
	public function addSectionById(int $pageId, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = json_decode($request->getContent(), true);
		$sort = (int) ($body['sort'] ?? 100);
		$texts = $body['texts'] ?? [];

		// Валидация: хотя бы один текст
		if (empty($texts)) {
			return ApiResponse::error('Добавьте хотя бы один текст', 400);
		}

		$page = $this->neuronRepo->findById($pageId);
		if (!$page) {
			return ApiResponse::error('Страница не найдена', 404);
		}

		// Создаём один text key для всех текстов
		$textKey = $this->textRepo->getNextKey();
		$hasAny = false;

		foreach ($texts as $t) {
			$lang = $t['lang'] ?? 'ru';
			$name = !empty($t['name']) ? trim($t['name']) : null;
			$text = !empty($t['text']) ? trim($t['text']) : null;

			if ($name || $text) {
				// Добавляем язык в ENUM если его там нет
				$this->textRepo->addLang($lang);
				
				$conn = $this->neuronRepo->getConnection();
				$conn->executeStatement(
					'INSERT INTO text (`key`, lang, name, text) VALUES (?, ?, ?, ?)',
					[$textKey, $lang, $name, $text]
				);
				$hasAny = true;
			}
		}

		if (!$hasAny) {
			return ApiResponse::error('Нет данных для сохранения', 400);
		}

		$sectionId = $this->neuronRepo->create('item', ['sort' => $sort], $page['id'], $textKey);

		return ApiResponse::success([
			'id'   => $sectionId,
			'sort' => $sort,
			'text_key' => $textKey,
		]);
	}

	/**
	 * POST /api/page/{pageId}/subpage-by-id
	 * Создаёт подстраницу по ID родителя.
	 */
	public function addSubPageById(int $pageId, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// CSRF-защита
		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = json_decode($request->getContent(), true);
		$name = $body['name'] ?? '';
		$subSlug = $body['slug'] ?? '';

		if (empty($name)) {
			return ApiResponse::error('Название обязательно');
		}

		// Проверяем существование родительской страницы
		$parentPage = $this->neuronRepo->findById($pageId);
		if (!$parentPage) {
			return ApiResponse::error('Родительская страница не найдена', 404);
		}

		// Генерируем slug если не указан
		if (empty($subSlug)) {
			$subSlug = $this->slugify($name);
		}

		$textKey = $this->textRepo->findOrCreate('ru', $name);

		$id = $this->neuronRepo->create('tree', [
			'slug'  => $subSlug,
			'route' => '/' . $subSlug,
		], $parentPage['id'], $textKey);

		return ApiResponse::success(['id' => $id, 'slug' => $subSlug], 'Подстраница создана');
	}

	/**
	 * Генерирует slug из строки.
	 */
	private function slugify(string $text): string
	{
		$text = mb_strtolower($text);
		$text = preg_replace('/[^a-z0-9а-яё]+/u', '-', $text);
		$text = trim($text, '-');
		return $text ?: 'page-' . time();
	}

	/**
	 * DELETE /api/page/sub/{id}
	 * Удаляет подстраницу со всем содержимым.
	 */
	public function deleteSubPage(int $id): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$page = $this->neuronRepo->findById($id);
		if (!$page) {
			return ApiResponse::error('Подстраница не найдена', 404);
		}

		// Рекурсивно удаляем все дочерние нейроны
		$this->deleteChildren($page['id']);
		
		// Удаляем саму подстраницу
		$this->neuronRepo->delete($id, false);

		return ApiResponse::success(['id' => $id], 'Подстраница удалена');
	}

	/**
	 * Рекурсивно удаляет все дочерние нейроны.
	 */
	private function deleteChildren(int $parentId): void
	{
		$children = $this->neuronRepo->findChildren($parentId);
		foreach ($children as $child) {
			// Рекурсивно удаляем внуков
			$this->deleteChildren($child['id']);
			// Удаляем сам нейрон
			$this->neuronRepo->delete($child['id'], false);
		}
	}
	
	// ============================================
	// ТЕСТОВЫЙ МЕТОД
	// ============================================

	/**
	 * GET /api/page/test
	 * 
	 * Диагностический метод для проверки работоспособности контроллера.
	 * 
	 * @return JsonResponse
	 */
	public function test(): JsonResponse
	{
		return ApiResponse::success(['test' => 'ok']);
	}
}