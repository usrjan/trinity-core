<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Map;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class MapGeoJsonController
{
	use AuthMiddleware;

	private NeuronRepository $neuronRepo;
	private SynapseRepository $synapseRepo;

	public function __construct(
		Session $session,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo
	) {
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;

		$this->initAuth($session);
	}

	/**
	 * GET /api/map/geojson
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