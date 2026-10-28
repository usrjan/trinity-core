<?php

/**
 * КОНТРОЛЛЕР КАРТЫ
 *
 * Отображает интерактивную карту с геометрией нейронов.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — проводник. Тот, кто
 * ведёт гостя по миру и показывает ему то, что он ищет.
 *
 * [Мириам]: И это правильно, потому что карта — это не «картинка»,
 * это приглашение увидеть мир целиком.
 *
 * === КНИГА ===
 * Глава 8. Карта.
 *
 * Каждая Тень имеет свою карту. Каждый мир — свои пути.
 * И тот, кто знает пути, всегда найдёт дорогу.
 *
 * @package Jan\Trinity\Plugin\Map
 */

namespace Jan\Trinity\Plugin\Map;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class MapController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — работа с синапсами. */
	private SynapseRepository $synapseRepo;

	private Validator $validator;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Мы передаём SynapseRepository вторым аргументом,
	 * чтобы refreshRoles() работал по-настоящему, а не оставался
	 * красивой декорацией. Роли должны обновляться, даже если
	 * человек вошёл давно, потому что мир вокруг меняется.
	 *
	 * [Мириам]: И это правильно, потому что доверие — это не
	 * однажды выданный пропуск, а то, что подтверждается снова и снова.
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
		$this->synapseRepo = $synapseRepo;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// СТРАНИЦА КАРТЫ
	// ============================================

	/**
	 * GET /map или /{slug} с template=map
	 *
	 * [Лорелея]: Здесь мы открываем карту. Параметры берём
	 * из нейрона, если страница динамическая, или используем
	 * значения по умолчанию, потому что карта должна открыться
	 * даже тогда, когда данных ещё нет.
	 *
	 * [Мириам]: И это правильно, потому что пустая карта —
	 * это не ошибка, это начало.
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

	// ============================================
	// GeoJSON API
	// ============================================

	/**
	 * GET /api/map/geojson
	 *
	 * [Лорелея]: Здесь мы собираем мир в GeoJSON. Каждый нейрон
	 * с геометрией становится точкой, линией или полигоном,
	 * и всё это складывается в карту, которую можно показать.
	 *
	 * [Мириам]: И это правильно, потому что мир — это не
	 * набор отдельных мест, а то, что видно, когда смотришь
	 * на всё сразу.
	 *
	 * @param Request $request
	 * @return JsonResponse — FeatureCollection
	 */
	public function geoJson(Request $request): JsonResponse
	{
		$codes = $request->query->get('codes');
		$pid = $request->query->get('pid');

		$treeId = null;
		if ($codes) {
			$treeNeuron = $this->neuronRepo->findBySlug($codes);
			if ($treeNeuron) {
				$treeId = (int) $treeNeuron['id'];
			}
		}

		$neurons = $this->neuronRepo->findWithGeometry(
			$treeId,
			$pid ? (int) $pid : null
		);

		$features = [];
		foreach ($neurons as $neuron) {
			$data = is_string($neuron['data'] ?? null)
				? json_decode($neuron['data'], true)
				: ($neuron['data'] ?? []);

			$geometry = $data['geometry'] ?? null;
			if (!$geometry) continue;

			$opt = [];

			if (isset($data['LineStyle']['color'])) {
				$color = $this->parseKmlColor($data['LineStyle']['color']);
				$opt['strokeColor'] = $color['hex'];
				$opt['strokeOpacity'] = $color['opacity'];
			}
			if (isset($data['LineStyle']['width'])) {
				$opt['strokeWidth'] = min($data['LineStyle']['width'], 1);
			}

			if (isset($data['PolyStyle']['color'])) {
				$color = $this->parseKmlColor($data['PolyStyle']['color']);
				$opt['fillColor'] = $color['hex'];
				$opt['fillOpacity'] = $color['opacity'];
			}

			if (isset($data['LabelStyle']['color'])) {
				$color = $this->parseKmlColor($data['LabelStyle']['color']);
				$opt['iconColor'] = $color['hex'];
			}

			if (isset($data['strokeColor'])) $opt['strokeColor'] = $data['strokeColor'];
			if (isset($data['strokeWidth'])) $opt['strokeWidth'] = $data['strokeWidth'];
			if (isset($data['fillColor'])) $opt['fillColor'] = $data['fillColor'];
			if (isset($data['fillOpacity'])) $opt['fillOpacity'] = $data['fillOpacity'];

			$opt['strokeWidth'] = $opt['strokeWidth'] ?? 1;
			$opt['iconColor'] = $opt['iconColor'] ?? 'gray';

			$name = $neuron['display_name']
				?? $data['code']
				?? ($data['slug'] ?? '');

			$prop = [
				'balloonContentHeader' => $name,
				'hintContent'          => $name,
				'description'          => $data['description'] ?? '',
				'iden'                 => $neuron['id'],
			];

			if (isset($data['META_CNT'])) {
				$prop['iconContent'] = $data['META_CNT'];
			}

			$features[] = [
				'type'       => 'Feature',
				'id'         => $neuron['id'],
				'geometry'   => $geometry,
				'properties' => $prop,
				'options'    => $opt,
			];
		}

		$pointIds = [];
		foreach ($features as $feature) {
			if ($feature['geometry']['type'] === 'Point') {
				$pointIds[] = $feature['id'];
			}
		}

		$grouped = [];
		if (!empty($pointIds)) {
			$conn = $this->synapseRepo->getConnection();
			$ids = implode(',', $pointIds);

			$allSynapses = $conn->executeQuery(
				"SELECT s.parent, s.data
				FROM synapse s
				WHERE s.parent IN ($ids)
				AND s.relation_type = 'has_work'
				AND s.is_deleted = 0
				ORDER BY s.parent, s.id"
			)->fetchAllAssociative();

			foreach ($allSynapses as $synapse) {
				$groupId = $synapse['parent'];
				if (!isset($grouped[$groupId])) {
					$grouped[$groupId] = [];
				}
				$grouped[$groupId][] = $synapse;
			}
		}

		foreach ($features as &$feature) {
			if ($feature['geometry']['type'] !== 'Point') continue;

			$neuronId = $feature['id'];
			$synapses = $grouped[$neuronId] ?? [];

			$metaCnt = count($synapses);
			$metaColor = 'gray';

			if ($metaCnt > 0) {
				$feature['properties']['iconContent'] = $metaCnt;
				$metaColor = 'green';

				foreach ($synapses as $synapse) {
					$synData = is_string($synapse['data'] ?? null)
						? json_decode($synapse['data'], true)
						: ($synapse['data'] ?? []);

					$ready = isset($synData['ready'])
						? (int) str_replace('%', '', $synData['ready'])
						: 0;

					if ($ready > 0 && $ready <= 50) {
						$metaColor = 'orange';
					} elseif ($ready > 50) {
						$metaColor = 'olive';
					} elseif ($ready === 0) {
						$metaColor = 'red';
						break;
					}
				}
			}

			$feature['options']['iconColor'] = $metaColor;
			$feature['properties']['metaCnt'] = $metaCnt;
			$feature['properties']['metaColor'] = $metaColor;
		}
		unset($feature);

		return ApiResponse::success([
			'type'     => 'FeatureCollection',
			'features' => $features,
		]);
	}

	// ============================================
	// ИНФОРМАЦИЯ ОБ ОБЪЕКТЕ (ЛЕВАЯ ПАНЕЛЬ)
	// ============================================

	/**
	 * GET /api/map/info/{id}
	 *
	 * [Лорелея]: Здесь мы рассказываем об одном месте так,
	 * чтобы гость захотел задержаться и рассмотреть его
	 * внимательнее, потому что за каждой точкой на карте
	 * стоит история.
	 *
	 * [Мириам]: И это правильно, потому что карта без историй
	 * — это просто схема, а схема не умеет быть домом.
	 *
	 * @param int $id — id нейрона-учреждения
	 * @return Response
	 */
	public function info(int $id): Response
	{
		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			throw new \RuntimeException('Объект не найден', 404);
		}

		$data = is_string($neuron['data'] ?? null)
			? json_decode($neuron['data'], true)
			: ($neuron['data'] ?? []);

		$name = 'Без названия';
		if ($neuron['text']) {
			$text = $this->textRepo->findByKeyAndLang($neuron['text'], 'ru');
			if ($text) {
				$name = $text['name'] ?? $name;
			}
		}

		$works = $this->synapseRepo->findByParent($id, 'has_work');
		$worksList = [];

		foreach ($works as $work) {
			$workData = is_string($work['data'] ?? null)
				? json_decode($work['data'], true)
				: ($work['data'] ?? []);

			$worksList[] = [
				'name'   => $work['child_name'] ?? 'Без названия',
				'type'   => $workData['type'] ?? '',
				'year'   => $workData['year'] ?? '',
				'ready'  => $workData['ready'] ?? '',
				'number' => $workData['number'] ?? '',
			];
		}

		$html = '<div class="p-3">';
		$html .= '<h5 class="gold-text">' . htmlspecialchars($name) . '</h5>';

		if (!empty($worksList)) {
			$html .= '<hr class="border-gold">';
			$html .= '<h6 class="mb-2">Работы</h6>';

			foreach ($worksList as $w) {
				$html .= '<div class="card bg-dark border-gold mb-2">';
				$html .= '<div class="card-body py-2 px-3">';
				$html .= '<strong>' . htmlspecialchars($w['name']) . '</strong>';

				if ($w['type'] || $w['year']) {
					$html .= '<br><small class="text-muted">';
					if ($w['type']) $html .= htmlspecialchars($w['type']);
					if ($w['type'] && $w['year']) $html .= ' &middot; ';
					if ($w['year']) $html .= htmlspecialchars($w['year']);
					$html .= '</small>';
				}

				if ($w['ready']) {
					$html .= ' <span class="badge badge-gold">' . htmlspecialchars($w['ready']) . '</span>';
				}

				$html .= '</div></div>';
			}
		} else {
			$html .= '<p class="text-muted">Нет данных о работах</p>';
		}

		$html .= '</div>';

		return ApiResponse::success(['html' => $html]);
	}

	// ============================================
	// ПАРСИНГ KML-ЦВЕТА
	// ============================================

	/**
	 * Парсит KML-цвет в HEX и прозрачность.
	 *
	 * [Лорелея]: KML хранит цвет в порядке AABBGGRR, где альфа
	 * идёт первой, а красный — последним, и это не ошибка,
	 * а просто другой способ смотреть на тот же свет.
	 *
	 * [Мириам]: И это правильно, потому что у каждого мира
	 * свои правила, и наша задача — не спорить с ними,
	 * а понять их.
	 *
	 * @param string $kmlColor — цвет в формате KML (8 символов hex)
	 * @return array — ['hex' => '#RRGGBB', 'opacity' => 0.0-1.0]
	 */
	private function parseKmlColor(string $kmlColor): array
	{
		$kmlColor = trim($kmlColor);

		$alpha = hexdec(substr($kmlColor, 0, 2));
		$blue  = substr($kmlColor, 2, 2);
		$green = substr($kmlColor, 4, 2);
		$red   = substr($kmlColor, 6, 2);

		return [
			'hex'     => '#' . $red . $green . $blue,
			'opacity' => round($alpha / 255, 1),
		];
	}
}