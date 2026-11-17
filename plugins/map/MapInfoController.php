<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Map;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Repository\TextRepository;
use Symfony\Component\HttpFoundation\Response;

class MapInfoController
{
	private NeuronRepository $neuronRepo;
	private SynapseRepository $synapseRepo;
	private TextRepository $textRepo;

	public function __construct(
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		TextRepository $textRepo
	) {
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->textRepo = $textRepo;
	}

	/**
	 * GET /api/map/info/{id}
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
}