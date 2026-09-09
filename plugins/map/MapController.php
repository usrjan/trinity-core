<?php

/**
 * КОНТРОЛЛЕР КАРТЫ
 * =================
 * 
 * Отображает интерактивную карту с геометрией нейронов.
 * 
 * Страничные методы (HTML):
 * - index()              — страница с картой (полноэкранный режим без панелей)
 * 
 * API-методы (JSON):
 * - geoJson()            — GeoJSON с геометрией (полигоны и точки)
 * - info(id)             — информация об объекте и связанных работах
 * 
 * Поддерживает:
 * - Полигоны районов (SubAdministrativeArea)
 * - Точки учреждений (BUILD) с цветовой индикацией статуса работ
 * - Фильтрацию по классификатору (codes) и родителю (pid)
 * - Стилизацию из KML (LineStyle, PolyStyle, LabelStyle)
 * - Цвет точек в зависимости от статуса работ:
 *   - Зелёный — есть работы, все > 50%
 *   - Оливковый — есть работы, все > 0%
 *   - Оранжевый — есть работы, некоторые ≤ 50%
 *   - Красный — есть работы с 0%
 *   - Серый — нет работ
 * 
 * Зависимости:
 * - TextRepository    — подтягивание названий объектов
 * - NeuronRepository  — поиск нейронов с геометрией
 * - SynapseRepository — подсчёт синапсов для статуса работ
 */

namespace Jan\Trinity\Plugin\Map;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Twig\Environment;

class MapController
{
	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — работа с синапсами */
	private SynapseRepository $synapseRepo;

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 */
	public function __construct(
		Environment $twig,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
	}

	// ============================================
	// СТРАНИЦА КАРТЫ
	// ============================================

	/**
	 * GET /map или /{slug} с template=map
	 * 
	 * Отображает полноэкранную карту без боковых панелей.
	 * Параметры карты берутся из data нейрона (если страница динамическая)
	 * или используются значения по умолчанию (центр Крыма).
	 * 
	 * @param Request $request
	 * @return Response — HTML страница с картой
	 */
	public function index(Request $request): Response
	{
		$path = $request->getPathInfo();
		$slug = trim($path, '/');

		// Значения по умолчанию (центр Крыма)
		$mapVars = [
			'cen_lat' => 45.31,    // широта центра
			'cen_lon' => 34.63,    // долгота центра
			'zoom'    => 9,        // масштаб
			'codes'   => null,     // фильтр объектов (null = все)
			'title'   => 'Карта',  // заголовок
		];

		// Если это динамическая страница (не /map) — ищем нейрон с template=map
		if ($slug && $slug !== 'map') {
			$page = $this->neuronRepo->findBySlug($slug);

			if ($page) {
				$pageData = is_string($page['data'] ?? null)
					? json_decode($page['data'], true)
					: ($page['data'] ?? []);

				// Параметры отображения из data нейрона
				$view = $pageData['view'] ?? [];

				$mapVars = [
					'cen_lat' => $view['center'][0] ?? 45.31,
					'cen_lon' => $view['center'][1] ?? 34.63,
					'zoom'    => $view['zoom'] ?? 9,
					'codes'   => $pageData['codes'] ?? null,
					'title'   => $pageData['slug'] ?? 'Карта',
				];

				// Подтягиваем название из текста
				if ($page['text']) {
					$text = $this->textRepo->findByKeyAndLang($page['text'], 'ru');
					if ($text && $text['name']) {
						$mapVars['title'] = $text['name'];
					}
				}
			}
		}

		// Рендерим полноэкранный шаблон карты
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
	 * Возвращает GeoJSON с геометрией нейронов.
	 * 
	 * Параметры фильтрации:
	 * - codes — slug классификатора (SubAdministrativeArea, BUILD)
	 * - pid   — id родительского нейрона (для фильтрации точек по району)
	 * 
	 * Для точек (учреждений) вычисляет цвет в зависимости от статуса работ.
	 * 
	 * @param Request $request
	 * @return JsonResponse — FeatureCollection
	 */
	public function geoJson(Request $request): JsonResponse
	{
		$codes = $request->query->get('codes');
		$pid = $request->query->get('pid');

		// Определяем tree id для фильтрации
		$treeId = null;
		if ($codes) {
			$treeNeuron = $this->neuronRepo->findBySlug($codes);
			if ($treeNeuron) {
				$treeId = (int) $treeNeuron['id'];
			}
		}

		// Загружаем нейроны с геометрией
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

			// ============================================
			// СТИЛИ ОБЪЕКТА
			// ============================================
			$opt = [];

			// KML-стиль линии (AABBGGRR → #RRGGBB + opacity)
			if (isset($data['LineStyle']['color'])) {
				$color = $this->parseKmlColor($data['LineStyle']['color']);
				$opt['strokeColor'] = $color['hex'];
				$opt['strokeOpacity'] = $color['opacity'];
			}
			if (isset($data['LineStyle']['width'])) {
				$opt['strokeWidth'] = min($data['LineStyle']['width'], 1);
			}

			// KML-стиль заливки
			if (isset($data['PolyStyle']['color'])) {
				$color = $this->parseKmlColor($data['PolyStyle']['color']);
				$opt['fillColor'] = $color['hex'];
				$opt['fillOpacity'] = $color['opacity'];
			}

			// KML-стиль метки
			if (isset($data['LabelStyle']['color'])) {
				$color = $this->parseKmlColor($data['LabelStyle']['color']);
				$opt['iconColor'] = $color['hex'];
			}

			// Простые стили (из data напрямую)
			if (isset($data['strokeColor'])) $opt['strokeColor'] = $data['strokeColor'];
			if (isset($data['strokeWidth'])) $opt['strokeWidth'] = $data['strokeWidth'];
			if (isset($data['fillColor'])) $opt['fillColor'] = $data['fillColor'];
			if (isset($data['fillOpacity'])) $opt['fillOpacity'] = $data['fillOpacity'];

			// Значения по умолчанию
			$opt['strokeWidth'] = $opt['strokeWidth'] ?? 1;
			$opt['iconColor'] = $opt['iconColor'] ?? 'gray';

			// ============================================
			// СВОЙСТВА ОБЪЕКТА
			// ============================================
			$name = $neuron['display_name']
				?? $data['code']
				?? ($data['slug'] ?? '');

			$prop = [
				'balloonContentHeader' => $name,
				'hintContent'          => $name,
				'description'          => $data['description'] ?? '',
				'iden'                 => $neuron['id'],   // id для левой панели
			];

			// Количество синапсов на иконке (если задано)
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

		// ============================================
		// РАСКРАШИВАЕМ ТОЧКИ ПО СТАТУСУ РАБОТ
		// (оптимизированная версия — один запрос)
		// ============================================

		// Собираем все ID точек
		$pointIds = [];
		foreach ($features as $feature) {
			if ($feature['geometry']['type'] === 'Point') {
				$pointIds[] = $feature['id'];
			}
		}

		// Загружаем все синапсы для всех точек одним запросом
		$allSynapses = [];
		if (!empty($pointIds)) {
			$conn = $this->synapseRepo->getConnection();
			$ids = implode(',', $pointIds);
			
			$allSynapses = $conn->executeQuery(
				"SELECT s.parent, s.data 
				FROM synapse s 
				WHERE s.parent IN ($ids) 
				AND s.relation_type = 'has_work'
				ORDER BY s.parent, s.id"
			)->fetchAllAssociative();
			
			// Группируем по parent_id
			$grouped = [];
			foreach ($allSynapses as $synapse) {
				$groupId = $synapse['parent'];
				if (!isset($grouped[$groupId])) {
					$grouped[$groupId] = [];
				}
				$grouped[$groupId][] = $synapse;
			}
		}

		// Раскрашиваем
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
	 * Возвращает HTML с информацией об учреждении и списком работ.
	 * Используется в левой панели при клике на точку.
	 * 
	 * @param int $id — id нейрона-учреждения
	 * @return JsonResponse
	 */
	public function info(int $id): JsonResponse
	{
		// Ищем нейрон
		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Объект не найден', 404);
		}

		$data = is_string($neuron['data'] ?? null)
			? json_decode($neuron['data'], true)
			: ($neuron['data'] ?? []);

		// Получаем название из текста
		$name = 'Без названия';
		if ($neuron['text']) {
			$text = $this->textRepo->findByKeyAndLang($neuron['text'], 'ru');
			if ($text) {
				$name = $text['name'] ?? $name;
			}
		}

		// Загружаем связанные работы (синапсы has_work)
		$works = $this->synapseRepo->findByParent($id, 'has_work');
		$worksList = [];

		foreach ($works as $work) {
			$workData = is_string($work['data'] ?? null)
				? json_decode($work['data'], true)
				: ($work['data'] ?? []);

			$worksList[] = [
				'name'   => $work['child_name'] ?? 'Без названия',
				'type'   => $workData['type'] ?? '',      // вид работ (СМР, ПИР...)
				'year'   => $workData['year'] ?? '',      // год проведения
				'ready'  => $workData['ready'] ?? '',     // готовность (100%, 50%...)
				'number' => $workData['number'] ?? '',    // порядковый номер
			];
		}

		// Собираем HTML для левой панели
		$html = '<div class="p-3">';
		$html .= '<h5 class="gold-text">' . htmlspecialchars($name) . '</h5>';

		if (!empty($worksList)) {
			$html .= '<hr class="border-gold">';
			$html .= '<h6 class="mb-2">Работы</h6>';

			foreach ($worksList as $w) {
				$html .= '<div class="card bg-dark border-gold mb-2">';
				$html .= '<div class="card-body py-2 px-3">';
				$html .= '<strong>' . htmlspecialchars($w['name']) . '</strong>';

				// Тип и год
				if ($w['type'] || $w['year']) {
					$html .= '<br><small class="text-muted">';
					if ($w['type']) $html .= htmlspecialchars($w['type']);
					if ($w['type'] && $w['year']) $html .= ' &middot; ';
					if ($w['year']) $html .= htmlspecialchars($w['year']);
					$html .= '</small>';
				}

				// Статус готовности
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
	 * KML формат: AABBGGRR (альфа, синий, зелёный, красный).
	 * 
	 * @param string $kmlColor — цвет в формате KML (8 символов hex)
	 * @return array — ['hex' => '#RRGGBB', 'opacity' => 0.0-1.0]
	 */
	private function parseKmlColor(string $kmlColor): array
	{
		$kmlColor = trim($kmlColor);

		// Извлекаем компоненты
		$alpha = hexdec(substr($kmlColor, 0, 2));   // AA
		$blue  = substr($kmlColor, 2, 2);           // BB
		$green = substr($kmlColor, 4, 2);           // GG
		$red   = substr($kmlColor, 6, 2);           // RR

		return [
			'hex'     => '#' . $red . $green . $blue,
			'opacity' => round($alpha / 255, 1),
		];
	}
}