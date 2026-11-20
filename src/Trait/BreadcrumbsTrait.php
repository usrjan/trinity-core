<?php
declare(strict_types=1);

/**
 * TRAIT ХЛЕБНЫХ КРОШЕК
 *
 * Собирает хлебные крошки от корня до указанного нейрона.
 * Использует NeuronRepository и TextRepository.
 *
 * === ПОЧЕМУ TRAIT, А НЕ СЕРВИС ===
 * Потому что BreadcrumbsService использовался только в двух
 * плагинах — spa и gallery. А ядро — не должно знать о них.
 *
 * @package Jan\Trinity\Core\Trait
 */

namespace Jan\Trinity\Core\Trait;

trait BreadcrumbsTrait
{
	/**
	 * Собрать хлебные крошки от корня до указанного нейрона.
	 *
	 * @param int $id — id нейрона, с которого начинаем
	 * @param string $stopSlug — slug, на котором остановиться
	 * @param string $lang — язык для текста
	 * @return array — массив крошек
	 */
	protected function buildBreadcrumbs(int $id, string $stopSlug, string $lang = 'ru'): array
	{
		$breadcrumbs = [];
		$current = $this->neuronRepo->findById($id);

		while ($current) {
			$currentData = is_string($current['data'] ?? null)
				? json_decode($current['data'], true)
				: ($current['data'] ?? []);

			$name = 'Без названия';

			if ($current['text'] !== null && $current['text'] !== '') {
				$text = $this->textRepo->findByKeyAndLang($current['text'], $lang);

				if (!$text || $text['name'] === null || $text['name'] === '') {
					$allTexts = $this->textRepo->findAllByKey($current['text']);
					foreach ($allTexts as $t) {
						if ($t['name'] !== null && $t['name'] !== '') {
							$text = $t;
							break;
						}
					}
				}

				if ($text && $text['name'] !== null && $text['name'] !== '') {
					$name = $text['name'];
				}
			}

			if ($name === 'Без названия') {
				$name = $currentData['slug'] ?? $currentData['original_name'] ?? 'Без названия';
			}

			array_unshift($breadcrumbs, [
				'name' => $name,
				'slug' => $currentData['slug'] ?? '',
				'id'   => $current['id'],
				'type' => $current['type'],
			]);

			if ($current['pid']) {
				$current = $this->neuronRepo->findById($current['pid']);

				if (!$current) {
					break;
				}

				$parentData = is_string($current['data'] ?? null)
					? json_decode($current['data'], true)
					: ($current['data'] ?? []);

				if (($parentData['slug'] ?? '') === $stopSlug) {
					break;
				}
			} else {
				break;
			}
		}

		return $breadcrumbs;
	}
}