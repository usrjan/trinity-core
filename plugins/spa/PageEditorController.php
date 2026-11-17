<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Service\CsrfService;
use Jan\Trinity\Core\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class PageEditorController
{
	use AuthMiddleware;

	private TextRepository $textRepo;
	private NeuronRepository $neuronRepo;
	private Validator $validator;

	/** @var CsrfService — CSRF-защита. */
	private CsrfService $csrfService;

	public function __construct(
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		CsrfService $csrfService,
		Validator $validator
	) {
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->csrfService = $csrfService;
		$this->validator = $validator;

		$this->initAuth($session);
	}

	// ============================================
	// РЕДАКТИРОВАНИЕ СЕКЦИЙ
	// ============================================

	public function addSection(string $slug, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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
	
	/**
	 * POST /api/page/section/{pageId}/add
	 *
	 * [Лорелея]: Здесь мы добавляем секцию по id страницы,
	 * а не по slug, потому что иногда гость приходит из
	 * подстраницы, и slug у него уже нет.
	 *
	 * [Мириам]: И это правильно, потому что id — это
	 * не «догадка», это точное знание.
	 */
	public function addSectionById(int $pageId, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
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
			'id'       => $sectionId,
			'sort'     => $sort,
			'text_key' => $textKey,
		]);
	}

	public function updateSection(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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
		if ($error = $this->requireCsrf($request)) return $error;

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

	public function deleteSection(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

		$section = $this->neuronRepo->findById($id);
		if (!$section) {
			return ApiResponse::error('Секция не найдена', 404);
		}

		$this->neuronRepo->delete($id, false);

		return ApiResponse::success(['id' => $id]);
	}

	// ============================================
	// ПОДСТРАНИЦЫ
	// ============================================

	public function addSubPage(string $slug, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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

	public function addSubPageById(int $pageId, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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

	public function deleteSubPage(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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

	private function slugify(string $text): string
	{
		$text = mb_strtolower($text);
		$text = preg_replace('/[^a-z0-9а-яё]+/u', '-', $text);
		$text = trim($text, '-');
		return $text ?: 'page-' . time();
	}

	private function requireCsrf(Request $request): ?JsonResponse
	{
		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}
		return null;
	}
}