<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР СТРАНИЦ
 *
 * Управление контентными страницами и их секциями.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ===
 * Раньше render(), show() и subPage() собирали $sections,
 * $subPages, $pageData и $pageTitle каждый сам. Почти слово
 * в слово. Это была копипаста. Которая работала. Но — копипаста.
 *
 * Теперь — PageRepository::buildPageData(). Один источник правды.
 * Один $sections. Один $subPages. Один $pageTitle.
 * Один page.html.twig. И — один контроллер, который не путается.
 *
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
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

	/** @var PageRepository — сборка данных страницы. Один источник правды. */
	private PageRepository $pageRepo;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		PageRepository $pageRepo
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->pageRepo = $pageRepo;

		$this->initAuth($session);
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

		$page = $this->resolvePage($slug);
		$pageData = $this->pageRepo->buildPageData($page, $lang);

		$html = $this->twig->render('base.html.twig', [
			'title'       => $pageData['title'],
			'user_lang'   => $lang,
			'is_admin'    => $this->isAdmin(),
			'breadcrumbs' => $this->buildBreadcrumbs($page['id'], 'PAGES', $lang),
			'page_id'     => $pageData['page_id'],
			'sections'    => $pageData['sections'],
			'sub_pages'   => $pageData['sub_pages'],
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

		$page = $this->resolvePage($slug);
		$pageData = $this->pageRepo->buildPageData($page, $lang);

		$html = $this->twig->render('page.html.twig', [
			'title'     => $pageData['title'],
			'sections'  => $pageData['sections'],
			'sub_pages' => $pageData['sub_pages'],
			'is_admin'  => $this->isAdmin(),
			'page_id'   => $pageData['page_id'],
		]);

		return ApiResponse::success([
			'title'       => $pageData['title'],
			'html'        => $html,
			'breadcrumbs' => $this->buildBreadcrumbs($page['id'], 'PAGES', $lang),
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
		$pageData = $this->pageRepo->buildPageData($page, $lang);

		$html = $this->twig->render('page.html.twig', [
			'title'     => $pageData['title'],
			'sections'  => $pageData['sections'],
			'sub_pages' => $pageData['sub_pages'],
			'is_admin'  => $this->isAdmin(),
			'page_id'   => $pageData['page_id'],
		]);

		return ApiResponse::success([
			'title'       => $pageData['title'],
			'html'        => $html,
			'breadcrumbs' => $this->buildBreadcrumbs($page['id'], 'PAGES', $lang),
		]);
	}

	// ============================================
	// ПОИСК
	// ============================================

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
			'query' => $query,
			'count' => count($items),
			'items' => $items,
		]);
	}

	// ============================================
	// ВСПОМОГАТЕЛЬНЫЕ
	// ============================================

	/**
	 * Найти страницу по slug под корнем PAGES.
	 *
	 * [Лорелея]: Один метод. Потому что render() и show()
	 * ищут одно и то же. И не должны искать это дважды.
	 *
	 * @param string $slug
	 * @return array
	 * @throws ResourceNotFoundException
	 */
	private function resolvePage(string $slug): array
	{
		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			throw new ResourceNotFoundException('PAGES не найден');
		}

		$page = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$page) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		return $page;
	}
}