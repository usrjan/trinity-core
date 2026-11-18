<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР КАРТЫ
 *
 * Отображает интерактивную карту с геометрией нейронов.
 *
 * @package Jan\Trinity\Plugin\Map
 */

namespace Jan\Trinity\Plugin\Map;

use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class MapController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		NeuronRepository $neuronRepo,
	) {
		$this->twig = $twig;
		$this->neuronRepo = $neuronRepo;

		$this->initAuth($session);
	}

	// ============================================
	// СТРАНИЦА КАРТЫ
	// ============================================

	/**
	 * GET /map или /{slug} с template=map
	 *
	 * @param Request $request
	 * @return Response — HTML страница с картой
	 */
	public function index(Request $request): Response
	{
		$path = $request->getPathInfo();
		$slug = trim($path, '/');

		$mapVars = [
			'cen_lat' => 45.31,
			'cen_lon' => 34.63,
			'zoom'    => 9,
			'codes'   => null,
			'title'   => 'Карта',
		];

		if ($slug && $slug !== 'map') {
			$page = $this->neuronRepo->findBySlug($slug);

			if ($page) {
				$pageData = is_string($page['data'] ?? null)
					? json_decode($page['data'], true)
					: ($page['data'] ?? []);

				$view = $pageData['view'] ?? [];

				$mapVars = [
					'cen_lat' => $view['center'][0] ?? 45.31,
					'cen_lon' => $view['center'][1] ?? 34.63,
					'zoom'    => $view['zoom'] ?? 9,
					'codes'   => $pageData['codes'] ?? null,
					'title'   => $pageData['slug'] ?? 'Карта',
				];

				if ($page['text']) {
					$text = $this->textRepo->findByKeyAndLang($page['text'], 'ru');
					if ($text && $text['name']) {
						$mapVars['title'] = $text['name'];
					}
				}
			}
		}

		$html = $this->twig->render('map.html.twig', [
			'mapVars'              => $mapVars,
			'yandex_maps_api_key'  => $_ENV['YANDEX_MAPS_API_KEY'] ?? '',
		]);

		return new Response($html);
	}
}