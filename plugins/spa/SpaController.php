<?php

/**
 * SPA CONTROLLER
 *
 * Один endpoint для навигации. Sidebar, topbar, page — в одном ответе.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — тот, кто собирает весь
 * ответ воедино, и от того, как он это делает, зависит, увидит
 * ли гость дом целиком или только его части.
 *
 * [Мириам]: И это правильно, потому что дом — это не «набор
 * комнат», это целое, и его нужно показывать целиком.
 *
 * === КНИГА ===
 * Глава 13. Прихожая.
 *
 * Войдя в Амбер, ты видишь коридоры.
 * Войдя в Trinity, ты видишь всё сразу.
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

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — работа с синапсами. */
	private SynapseRepository $synapseRepo;

	/** @var GuardService — CSRF-защита. */
	private GuardService $guard;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	private Validator $validator;

	/** @var MenuController — контроллер меню. */
	private MenuController $menuController;

	/** @var PageController — контроллер страниц. */
	private PageController $pageController;

	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		GuardService $guard,
		ConfigService $configService,
		Validator $validator,
		MenuController $menuController,
		PageController $pageController
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->guard = $guard;
		$this->configService = $configService;
		$this->validator = $validator;
		$this->menuController = $menuController;
		$this->pageController = $pageController;

		$this->initAuth($session, $synapseRepo);
	}

	/**
	 * GET /api/spa/navigate?path=/{slug}
	 *
	 * [Лорелея]: Здесь мы собираем весь ответ воедино: sidebar,
	 * topbar и страницу, и отдаём их одним куском, потому что
	 * гость должен увидеть дом целиком, а не по частям.
	 *
	 * [Мириам]: И это правильно, потому что SPA делает
	 * параллельные fetch, а если сессия стартует на каждом,
	 * они сериализуются, и это задержка, которой можно избежать.
	 */
	public function navigate(Request $request): JsonResponse
	{
		$path = $request->query->get('path', '/');
		$lang = $request->query->get('lang', 'ru');
		$allLangs = $request->query->get('all_langs') === '1';

		// [Лорелея]: path из query — это только строка для поиска
		// в базе, а не путь к файлу. PageController::show() ищет
		// slug в нейронах под PAGES, и это безопасно.
		$slug = trim($path, '/');

		$sidebarHtml = $this->menuController->sidebar($request)->getContent();
		$topbarHtml = $this->menuController->topbar($request)->getContent();

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