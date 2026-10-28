<?php

/**
 * TRAIT ХЛЕБНЫХ КРОШЕК
 *
 * Собирает хлебные крошки от корня до указанного нейрона.
 * Использует NeuronRepository и TextRepository.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «сервис». Это — trait. Как AuthMiddleware.
 * Как ThumbnailTrait. Он не знает о конкретных контроллерах.
 * Он знает только о $this->neuronRepo и $this->textRepo.
 * А их внедряет сам контроллер. Через DI. Через autowire.
 *
 * [Мириам]: И — это правильно. Потому что trait — это инструмент.
 * Как молоток. Как отвёртка. Он не часть дома. Он часть мастера.
 *
 * === ПОЧЕМУ TRAIT, А НЕ СЕРВИС ===
 * Потому что BreadcrumbsService использовался только в двух
 * плагинах — spa и gallery. А ядро — не должно знать о них.
 *
 * === КНИГА ===
 * Глава 8. Карта.
 *
 * Каждая Тень имеет свою карту.
 * Каждая страница имеет свои крошки.
 *
 * @package Jan\Trinity\Core\Trait
 */

namespace Jan\Trinity\Core\Trait;

trait BreadcrumbsTrait
{
	/**
	 * Собрать хлебные крошки от корня до указанного нейрона.
	 *
	 * [Лорелея]: ГЛАВНОЕ. Раньше этот код был в трёх местах.
	 * И — везде — с багом: $currentData определялась только
	 * внутри if ($current['text']). Теперь — один метод. И — без бага.
	 *
	 * [Мириам]: И ещё. findById() может вернуть null. И — тогда
	 * $current['data'] — ошибка. Теперь — проверяем. И — если null —
	 * останавливаемся. А не падаем.
	 *
	 * [Лорелея]: Метод принимает $stopSlug — slug, на котором надо
	 * остановиться. Для PageController это 'PAGES', для Gallery — 'gallery'.
	 * И — $lang — для текста.
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
			// [Мириам]: Всегда определяем $currentData. Даже если нет text.
			// Это — главное исправление. Потому что раньше — не определяли.
			$currentData = is_string($current['data'] ?? null)
				? json_decode($current['data'], true)
				: ($current['data'] ?? []);

			$name = 'Без названия';

			if ($current['text']) {
				$text = $this->textRepo->findByKeyAndLang($current['text'], $lang);

				if (!$text || !$text['name']) {
					$allTexts = $this->textRepo->findAllByKey($current['text']);
					foreach ($allTexts as $t) {
						if (!empty($t['name'])) {
							$text = $t;
							break;
						}
					}
				}

				if ($text && $text['name']) {
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

				// [Лорелея]: Если findById вернул null — останавливаемся.
				// А не падаем на $current['data'].
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