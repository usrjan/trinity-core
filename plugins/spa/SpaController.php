<?php
declare(strict_types=1);

/**
 * SPA CONTROLLER
 *
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\ConfigService;
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

class SpaController
{
	use AuthMiddleware;
	use BreadcrumbsTrait;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — работа с синапсами. */
	private SynapseRepository $synapseRepo;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	private Validator $validator;

	/** @var MenuController — контроллер меню. */
	private SidebarController $sidebarController;
	private TopbarController $topbarController;

	/** @var PageController — контроллер страниц. */
	private PageController $pageController;

	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		ConfigService $configService,
		Validator $validator,
		SidebarController $sidebarController,
		TopbarController $topbarController,
		PageController $pageController
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->configService = $configService;
		$this->validator = $validator;
		$this->sidebarController = $sidebarController;
		$this->topbarController = $topbarController;
		$this->pageController = $pageController;

		$this->initAuth($session, $synapseRepo);
	}

	/**
	 * GET /api/spa/navigate?path=/{slug}
	 */
	public function navigate(Request $request): JsonResponse
	{
		$path = $request->query->get('path', '/');
		$lang = $request->query->get('lang', 'ru');
		$allLangs = $request->query->get('all_langs') === '1';

		$slug = trim($path, '/');

		$sidebarHtml = $this->sidebarController->sidebar($request)->getContent();
		$topbarHtml = $this->topbarController->topbar($request)->getContent();

		$pageResponse = $this->pageController->show($slug, $request);
		$pageData = json_decode($pageResponse->getContent(), true);

		if (!$pageData || !$pageData['success']) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		return ApiResponse::success([
			'sidebar'     => $sidebarHtml,
			'topbar'      => $topbarHtml,
			'html'        => $pageData['data']['html'] ?? '',
			'title'       => $pageData['data']['title'] ?? $slug,
			'breadcrumbs' => $pageData['data']['breadcrumbs'] ?? [],
		]);
	}
}