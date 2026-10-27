<?php

/**
 * КОНТРОЛЛЕР СТРАНИЦ
 * ===================
 *
 * Управление контентными страницами и их секциями.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. RuntimeException заменён на
 * ResourceNotFoundException. Чтобы Kernel ловил как 404. А не 500.
 * Без «пиздеца». Без «потом». Без «завтра».
 *
 * [Мириам]: И — initAuth теперь получает SynapseRepository.
 * Чтобы refreshRoles() работал. Без «декорации». Без «потом».
 *
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Trait\BreadcrumbsTrait;
use Jan\Trinity\Core\Service\GuardService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Twig\Environment;

class PageController
{
	use AuthMiddleware;
	use BreadcrumbsTrait;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	/** @var GuardService — CSRF-защита */
	private GuardService $guard;

	private Validator $validator;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: initAuth теперь получает SynapseRepository.
	 * Чтобы refreshRoles() работал. Без «декорации». Без «потом».
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
		$this->guard = $guard;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// ПРОСМОТР СТРАНИЦЫ (ПУБЛИЧНЫЙ МЕТОД)
	// ============================================

	/**
	 * GET /api/page/{slug}
	 *
	 * [Лорелея]: ResourceNotFoundException вместо RuntimeException.
	 * Чтобы Kernel ловил как 404. А не 500. Без «пиздеца».
	 */
	public function show(string $slug, Request $request): Response
	{
		$lang = $request->query->get('lang', 'ru');
		$allLangs = $request->query->get('all_langs') === '1';

		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			return ApiResponse::error('PAGES не найден', 500);
		}

		$page = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$page) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		$breadcrumbs = $this->buildBreadcrumbs($page['id'], 'PAGES', $lang);

		// [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Один запрос вместо N+1.
		// findChildrenWithText возвращает и name, и text. Без «пиздеца».
		$children = $this->neuronRepo->findChildrenWithText($page['id'], $lang);

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
					// [Мириам]: Один текст из JOIN. Без N+1. Без «пиздеца».
					$sections[] = [
						'id'      => $child['id'],
						'sort'    => $childData['sort'] ?? 999999,
						'is_html' => ($childData['mime'] ?? '') === 'text/html',
						'texts'   => [[
							'text_id' => $child['text'],
							'lang'    => $lang,
							'name'    => $child['name'] ?? '',
							'text'    => $child['text_content'] ?? '',
						]],
					];
				}
			}
		}

		$pageData = is_string($page['data'] ?? null)
			? json_decode($page['data'], true)
			: ($page['data'] ?? []);

		$pageTitle = $pageData['title'] ?? $slug;

		if ($page['text']) {
			$titleText = $this->textRepo->findByKeyAndLang($page['text'], $lang);
			if ($titleText && $titleText['name']) {
				$pageTitle = $titleText['name'];
			}
		}

		$isAdmin = $this->isAdmin();

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

	public function addSection(string $slug, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$sort = (int) ($body['sort'] ?? 100);
		$lang = $body['lang'] ?? 'ru';
		$name = trim($body['name'] ?? '');
		$text = trim($body['text'] ?? '');

		if (!$this->validator->validate([
			'sort' => $sort,
			'lang' => $lang,
		], [
			'sort' => ['int', 'min:0'],
			'lang' => ['required', 'string', 'max:5', 'regex:/^[a-z]{2}$/i'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		if (empty($name) && empty($text)) {
			return ApiResponse::error('Название или текст обязательны', 400);
		}

		if (!empty($name) && !$this->validator->validate(['name' => $name], [
			'name' => ['string', 'max:255'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			return ApiResponse::error('PAGES не найден', 500);
		}

		$page = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$page) {
			return ApiResponse::error('Страница не найдена', 404);
		}

		$textKey = $this->textRepo->findOrCreate($lang, $name ?: null, $text ?: null);

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

	public function updateSection(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$section = $this->neuronRepo->findById($id);
		if (!$section) {
			return ApiResponse::error('Секция не найдена', 404);
		}

		if ($section['text'] && isset($body['name'])) {
			$this->textRepo->findOrCreate(
				$body['lang'] ?? 'ru',
				$body['name'],
				$body['text'] ?? null
			);
		}

		if (isset($body['sort'])) {
			$currentData = is_string($section['data'] ?? null)
				? json_decode($section['data'], true)
				: ($section['data'] ?? []);
			$currentData['sort'] = (int) $body['sort'];
			$this->neuronRepo->update($id, ['data' => $currentData]);
		}

		return ApiResponse::success(['id' => $id]);
	}

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

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$sort = (int) ($body['sort'] ?? 100);
		$texts = $body['texts'] ?? [];

		$currentData = is_string($section['data'] ?? null)
			? json_decode($section['data'], true)
			: ($section['data'] ?? []);
		$currentData['sort'] = $sort;
		$this->neuronRepo->update($id, ['data' => $currentData]);

		$textKey = $section['text'];
		if ($textKey) {
			$conn = $this->neuronRepo->getConnection();
			$conn->executeStatement(
				'UPDATE text SET is_active = 0 WHERE `key` = ?',
				[$textKey]
			);

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

	public function deleteSection(int $id, Request $request): JsonResponse
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

		$this->neuronRepo->delete($id, false);

		return ApiResponse::success(['id' => $id]);
	}

	public function addSubPage(string $slug, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$name = $body['name'] ?? '';
		$subSlug = $body['slug'] ?? '';

		if (!$this->validator->validate([
			'name' => $name,
			'slug' => $subSlug,
		], [
			'name' => ['required', 'string', 'min:1', 'max:255'],
			'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/iu'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			return ApiResponse::error('PAGES не найден', 500);
		}

		$parentPage = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$parentPage) {
			return ApiResponse::error('Родительская страница не найдена', 404);
		}

		$textKey = $this->textRepo->findOrCreate('ru', $name);

		$subPageData = [];
		if (!empty($subSlug)) {
			$subPageData['slug'] = $subSlug;
			$subPageData['route'] = '/' . $subSlug;
		}

		$id = $this->neuronRepo->create('tree', $subPageData, $parentPage['id'], $textKey);

		return ApiResponse::success(['id' => $id], 'Подстраница создана');
	}

	/**
	 * GET /api/page/sub/{id}
	 *
	 * [Мириам]: ResourceNotFoundException вместо RuntimeException.
	 * Чтобы Kernel ловил как 404. А не 500. Без «пиздеца».
	 */
	public function subPage(int $id): JsonResponse
	{
		$page = $this->neuronRepo->findById($id);
		if (!$page) {
			throw new ResourceNotFoundException('Подстраница не найдена');
		}

		$lang = 'ru';
		$breadcrumbs = $this->buildBreadcrumbs($page['id'], 'PAGES', $lang);

		// [Лорелея]: Один запрос вместо N+1. findChildrenWithText.
		// Без «пиздеца». Без «потом». Без «завтра».
		$children = $this->neuronRepo->findChildrenWithText($page['id'], $lang);

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
					$sections[] = [
						'id'      => $child['id'],
						'sort'    => $childData['sort'] ?? 999999,
						'lang'    => $lang,
						'name'    => $child['name'] ?? '',
						'text'    => $child['text_content'] ?? '',
						'is_html' => ($childData['mime'] ?? '') === 'text/html',
					];
				}
			}
		}

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

	public function addSectionById(int $pageId, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$sort = (int) ($body['sort'] ?? 100);
		$texts = $body['texts'] ?? [];

		if (empty($texts)) {
			return ApiResponse::error('Добавьте хотя бы один текст', 400);
		}

		$page = $this->neuronRepo->findById($pageId);
		if (!$page) {
			return ApiResponse::error('Страница не найдена', 404);
		}

		$textKey = $this->textRepo->getNextKey();
		$hasAny = false;

		foreach ($texts as $t) {
			$lang = $t['lang'] ?? 'ru';
			$name = !empty($t['name']) ? trim($t['name']) : null;
			$text = !empty($t['text']) ? trim($t['text']) : null;

			if ($name || $text) {
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

	public function addSubPageById(int $pageId, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$name = $body['name'] ?? '';
		$subSlug = $body['slug'] ?? '';

		if (empty($name)) {
			return ApiResponse::error('Название обязательно');
		}

		$parentPage = $this->neuronRepo->findById($pageId);
		if (!$parentPage) {
			return ApiResponse::error('Родительская страница не найдена', 404);
		}

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

	private function slugify(string $text): string
	{
		$text = mb_strtolower($text);
		$text = preg_replace('/[^a-z0-9а-яё]+/u', '-', $text);
		$text = trim($text, '-');
		return $text ?: 'page-' . time();
	}

	public function deleteSubPage(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$page = $this->neuronRepo->findById($id);
		if (!$page) {
			return ApiResponse::error('Подстраница не найдена', 404);
		}

		$this->deleteChildren($page['id']);
		$this->neuronRepo->delete($id, false);

		return ApiResponse::success(['id' => $id], 'Подстраница удалена');
	}

	private function deleteChildren(int $parentId): void
	{
		$children = $this->neuronRepo->findChildren($parentId);
		foreach ($children as $child) {
			$this->deleteChildren($child['id']);
			$this->neuronRepo->delete($child['id'], false);
		}
	}
}