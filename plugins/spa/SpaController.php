<?php

/**
 * SPA CONTROLLER
 * ==============
 *
 * Один endpoint для навигации. Sidebar, topbar, page — в одном ответе.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Один endpoint /api/spa/navigate.
 * Вместо трёх fetch на navigateTo. Меньше round-trips.
 * Меньше блокировок сессии. Без «пиздеца». Без «потом».
 *
 * [Мириам]: И — это правильно. Потому что SPA делает параллельные
 * fetch. А если сессия стартует на каждом — они сериализуются.
 * А это — задержка. Без «пиздеца». И — «навсегда».
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
use Jan\Trinity\Core\Service\GuardService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Twig\Environment;

class SpaController
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

	/**
	 * GET /api/spa/navigate?path=/{slug}
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Один endpoint.
	 * Sidebar, topbar, page — в одном ответе. Без трёх fetch.
	 * Без «пиздеца». Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — это правильно. Потому что SPA делает
	 * параллельные fetch. А если сессия стартует на каждом —
	 * они сериализуются. А это — задержка. Без «пиздеца».
	 * И — «навсегда».
	 */
	public function navigate(Request $request): JsonResponse
	{
		$path = $request->query->get('path', '/');
		$lang = $request->query->get('lang', 'ru');
		$allLangs = $request->query->get('all_langs') === '1';

		// [Лорелея]: Sidebar — из MenuController.
		$sidebarController = new MenuController(
			$this->twig,
			$this->session,
			$this->textRepo,
			$this->neuronRepo,
			$this->synapseRepo,
			$this->guard,
			$this->configService,
			$this->validator
		);
		$sidebarHtml = $sidebarController->sidebar($request)->getContent();

		// [Мириам]: Topbar — из MenuController.
		$topbarHtml = $sidebarController->topbar($request)->getContent();

		// [Лорелея]: Page — из PageController.
		$pageController = new PageController(
			$this->twig,
			$this->session,
			$this->textRepo,
			$this->neuronRepo,
			$this->synapseRepo,
			$this->guard,
			$this->validator
		);

		$slug = trim($path, '/');
		$pageResponse = $pageController->show($slug, $request);
		$pageData = json_decode($pageResponse->getContent(), true);

		if (!$pageData || !$pageData['success']) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		return ApiResponse::success([
			'sidebar' => $sidebarHtml,
			'topbar' => $topbarHtml,
			'html' => $pageData['data']['html'] ?? '',
			'title' => $pageData['data']['title'] ?? $slug,
			'breadcrumbs' => $pageData['data']['breadcrumbs'] ?? [],
		]);
	}
}