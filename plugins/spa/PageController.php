<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР СТРАНИЦ
 *
 * Управление контентными страницами и их секциями.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — тот, кто ведёт гостя
 * по страницам и секциям, и от того, как он это делает,
 * зависит, увидит ли гость то, что ему нужно.
 *
 * [Мириам]: И это правильно, потому что страница — это
 * не «набор блоков», это история, которую нужно рассказать.
 *
 * === КНИГА ===
 * Глава 13. Прихожая.
 *
 * Войдя в Амбер, ты видишь коридоры.
 * Войдя в Trinity, ты видишь страницы.
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
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// ПРОСМОТР СТРАНИЦЫ (ПУБЛИЧНЫЙ МЕТОД)
	// ============================================

	/**
	 * GET /api/page/{slug}
	 *
	 * [Лорелея]: Здесь мы показываем страницу. Секции и подстраницы
	 * приходят одним запросом, без N+1, потому что страница —
	 * это не «набор блоков», а целая история.
	 *
	 * [Мириам]: И если страницы нет, мы говорим об этом через
	 * ResourceNotFoundException, чтобы Kernel поймал это как 404.
	 *
	 * @param string $slug
	 * @param Request $request
	 * @return Response
	 */
	public function show(string $slug, Request $request): Response
	{
		$lang = $request->query->get('lang', 'ru');
		$allLangs = $request->query->get('all_langs') === '1';

		// [Лорелея]: ВАЖНО. slug — это строка для поиска в базе,
		// а не путь к файлу. Никогда не используйте его для
		// файловых операций, даже если он выглядит как путь.
		// Это защита от будущих ошибок, а не от текущих.
		$pagesRoot = $this->neuronRepo->findBySlug('PAGES');
		if (!$pagesRoot) {
			return ApiResponse::error('PAGES не найден', 500);
		}

		$page = $this->neuronRepo->findBySlugAndPid($slug, $pagesRoot['id']);
		if (!$page) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		$breadcrumbs = $this->buildBreadcrumbs($page['id'], 'PAGES', $lang);

		// [Лорелея]: Один запрос вместо N+1. findChildrenWithText
		// возвращает и name, и text, и это правильно, потому что
		// страница должна собираться быстро.
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
					// [Мириам]: Один текст из JOIN, без N+1, и это
					// правильно, потому что секция — это не «набор
					// запросов», а одна история.
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

	/**
	 * GET /api/page/sub/{id}
	 *
	 * [Мириам]: Здесь мы показываем подстраницу по её id.
	 * Если её нет — ResourceNotFoundException, чтобы Kernel
	 * поймал это как 404.
	 */
	public function subPage(int $id): JsonResponse
	{
		$page = $this->neuronRepo->findById($id);
		if (!$page) {
			throw new ResourceNotFoundException('Подстраница не найдена');
		}

		$lang = 'ru';
		$breadcrumbs = $this->buildBreadcrumbs($page['id'], 'PAGES', $lang);

		// [Лорелея]: Один запрос вместо N+1, и это правильно,
		// потому что подстраница должна собираться быстро.
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
	 * [Лорелея]: Поиск по текстам, потому что дом должен уметь
	 * отвечать на вопрос, даже если вопрос — это одно слово.
	 *
	 * [Мириам]: И это правильно, потому что поиск — это не
	 * «роскошь», а способ найти то, что уже есть.
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