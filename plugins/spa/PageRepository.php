<?php
declare(strict_types=1);

/**
 * РЕПОЗИТОРИЙ СТРАНИЦ
 *
 * Один источник правды для сборки данных страницы.
 * Знает, как превратить нейрон страницы в структуру,
 * которую понимает page.html.twig: title, sections, sub_pages.
 *
 * === ПОЧЕМУ РЕПОЗИТОРИЙ, А НЕ СЕРВИС ===
 * Потому что здесь нет бизнес-логики. Здесь только чтение:
 * найти детей, декодировать data, собрать секции и подстраницы,
 * вытащить заголовок из text. Никаких побочных эффектов.
 * Никаких сессий. Никаких Response. Только данные.
 *
 * === ЧТО ЗАМЕНЯЕТ ===
 * Раньше PageController::render(), PageController::show() и
 * PageController::subPage() собирали $sections, $subPages,
 * $pageData и $pageTitle почти слово в слово. Это была
 * копипаста. Которая работала. Но — копипаста.
 *
 * Теперь — один метод buildPageData(). Один $sections.
 * Один $subPages. Один $pageTitle. Один источник правды.
 *
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\TextRepository;

class PageRepository
{
	/** @var NeuronRepository — рука, которая трогает нейроны. */
	private NeuronRepository $neuronRepo;

	/** @var TextRepository — слова. Заголовки. Имена. */
	private TextRepository $textRepo;

	/**
	 * Конструктор. Принимает два репозитория.
	 *
	 * @param NeuronRepository $neuronRepo
	 * @param TextRepository $textRepo
	 */
	public function __construct(
		NeuronRepository $neuronRepo,
		TextRepository $textRepo
	) {
		$this->neuronRepo = $neuronRepo;
		$this->textRepo = $textRepo;
	}

	/**
	 * Собрать всё, что нужно для отрисовки страницы.
	 *
	 * Возвращает единый массив:
	 * [
	 *   'page_id'   => int,
	 *   'title'     => string,
	 *   'page_data' => array,
	 *   'sections'  => array,
	 *   'sub_pages' => array,
	 * ]
	 *
	 * @param array $page — нейрон страницы (как вернул NeuronRepository)
	 * @param string $lang — язык для текста
	 * @return array
	 */
	public function buildPageData(array $page, string $lang = 'ru'): array
	{
		$pageData = $this->decodeData($page['data'] ?? null);

		$children = $this->neuronRepo->findChildrenWithText($page['id'], $lang);

		$sections = [];
		$subPages = [];

		foreach ($children as $child) {
			$childData = $this->decodeData($child['data'] ?? null);

			// [Лорелея]: Ветка — это подстраница. Дверь вглубь.
			if ($child['type'] === 'tree') {
				$subPages[] = [
					'id'   => (int) $child['id'],
					'name' => $child['name'] ?? $childData['slug'] ?? 'Без названия',
					'slug' => $childData['slug'] ?? '',
				];
				continue;
			}

			// [Мириам]: Секция — это текст. Глава книги.
			// Если текста нет — это не секция, это пустота.
			if (empty($child['text'])) {
				continue;
			}

			$sections[] = [
				'id'      => (int) $child['id'],
				'sort'    => $childData['sort'] ?? 999999,
				'is_html' => ($childData['mime'] ?? '') === 'text/html',
				'texts'   => [[
					'text_id' => (int) $child['text'],
					'lang'    => $lang,
					'name'    => $child['name'] ?? '',
					'text'    => $child['text_content'] ?? '',
				]],
			];
		}

		return [
			'page_id'   => (int) $page['id'],
			'title'     => $this->resolveTitle($page, $pageData, $lang),
			'page_data' => $pageData,
			'sections'  => $sections,
			'sub_pages' => $subPages,
		];
	}

	/**
	 * Декодировать поле data нейрона в массив.
	 *
	 * [Лорелея]: Потому что data может быть строкой JSON,
	 * массивом, null или чем угодно. Но нам нужен массив.
	 *
	 * @param mixed $data
	 * @return array
	 */
	private function decodeData($data): array
	{
		if (is_string($data)) {
			$decoded = json_decode($data, true);
			return is_array($decoded) ? $decoded : [];
		}

		return is_array($data) ? $data : [];
	}

	/**
	 * Определить заголовок страницы.
	 *
	 * Порядок:
	 * 1. text.name по ключу page.text и языку
	 * 2. data.title
	 * 3. data.slug
	 * 4. 'Без названия'
	 *
	 * @param array $page — нейрон страницы
	 * @param array $pageData — декодированный data
	 * @param string $lang — язык
	 * @return string
	 */
	private function resolveTitle(array $page, array $pageData, string $lang): string
	{
		if (!empty($page['text'])) {
			$text = $this->textRepo->findByKeyAndLang((int) $page['text'], $lang);
			if ($text && !empty($text['name'])) {
				return $text['name'];
			}
		}

		return $pageData['title'] ?? $pageData['slug'] ?? 'Без названия';
	}
}