<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР СТРАНИЦ
 *
 * Управление контентными страницами и их секциями.
 *
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Trait\BreadcrumbsTrait;
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

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	private Validator $validator;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// ПОЛНАЯ СТРАНИЦА (ДЛЯ БРАУЗЕРА)
	// ============================================

	/**
	 * GET /{slug}
	 *
	 * @param string $slug
	 * @param Request $request
	 * @return Response
	 */
	public function render(string $slug, Request $request): Response
	{
		$lang = $request->query->get('lang', 'ru');

		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			throw new ResourceNotFoundException('PAGES не найден');
		}

		$page = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$page) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		$breadcrumbs = $this->buildBreadcrumbs($page['id'], 'PAGES', $lang);
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

		$html = $this->twig->render('base.html.twig', [
			'title'       => $pageTitle,
			'user_lang'   => $lang,
			'is_admin'    => $isAdmin,
			'breadcrumbs' => $breadcrumbs,
			'page_id'     => $page['id'],
			'sections'    => $sections,
			'sub_pages'   => $subPages,
		]);

		return new Response($html);
	}

	// ============================================
	// ФРАГМЕНТ (ДЛЯ SPA)
	// ============================================

	/**
	 * GET /api/page/{slug}
	 *
	 * @param string $slug
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function show(string $slug, Request $request): JsonResponse
	{
		$lang = $request->query->get('lang', 'ru');

		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			throw new ResourceNotFoundException('PAGES не найден');
		}

		$page = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$page) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		$breadcrumbs = $this->buildBreadcrumbs($page['id'], 'PAGES', $lang);
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
			'title'     => $pageTitle,
			'sections'  => $sections,
			'sub_pages' => $subPages,
			'is_admin'  => $isAdmin,
			'page_id'   => $page['id'],
		]);

		return ApiResponse::success([
			'title'       => $pageTitle,
			'html'        => $html,
			'breadcrumbs' => $breadcrumbs,
		]);
	}

	// ============================================
	// ПОДСТРАНИЦА (ФРАГМЕНТ)
	// ============================================

	/**
	 * GET /api/page/sub/{id}
	 */
	public function subPage(int $id): JsonResponse
	{
		$page = $this->neuronRepo->findById($id);
		if (!$page) {
			throw new ResourceNotFoundException('Подстраница не найдена');
		}

		$lang = 'ru';
		$breadcrumbs = $this->buildBreadcrumbs($page['id'], 'PAGES', $lang);
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
			'html'        => $html,
			'breadcrumbs' => $breadcrumbs,
		]);
	}

	/**
	 * GET /api/search?q=...
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function search(Request $request): JsonResponse
	{
		$query = trim($request->query->get('q', ''));
		$lang = $request->query->get('lang', 'ru');

		if ($query === '') {
			return ApiResponse::error('Параметр q обязателен', 400);
		}

		$results = $this->textRepo->search($query, $lang, 50);

		$items = [];
		foreach ($results as $row) {
			$items[] = [
				'key'  => $row['key'],
				'lang' => $row['lang'],
				'name' => $row['name'] ?? '',
				'text' => mb_substr($row['text'] ?? '', 0, 200),
			];
		}

		return ApiResponse::success([
			'query'   => $query,
			'count'   => count($items),
			'items'   => $items,
		]);
	}
}