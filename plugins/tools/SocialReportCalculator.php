<?php
declare(strict_types=1);

/**
 * КАЛЬКУЛЯТОР ОТЧЁТОВ ПО СОЦСЕТЯМ
 *
 * Считает темы, реакции, соцсети и организации.
 * Использует OrganizationRegistry для единого источника правды
 * об организациях, их сокращениях и типах.
 *
 * [Лорелея]: Это не «калькулятор». Это — тот, кто превращает
 * сырые данные в историю. И от того, как он это делает,
 * зависит, увидят ли люди правду. Или — цифры.
 *
 * [Мириам]: И это правильно, потому что за каждой цифрой
 * стоит человек. И его история должна быть рассказана.
 * Целиком. Без обрывов. Без — пустоты.
 *
 * @package Jan\Trinity\Plugin\Tools
 */

namespace Jan\Trinity\Plugin\Tools;

class SocialReportCalculator
{
	/** @var Mapping — реестр организаций. */
	private Mapping $mapping;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Мы передаём OrganizationRegistry, чтобы
	 * калькулятор не дублировал $names, $municipalities,
	 * $ministries, $organizations. Один источник правды.
	 * И — один getInstitution(). Который знает, что
	 * Администрация города Симферополя — это municipalities.
	 * А ГК по делам архивов РК — это ministries.
	 * А КТКЭ — это organizations.
	 *
	 * [Мириам]: И это правильно, потому что «один источник
	 * правды» — это не «красиво». Это — правильно.
	 */
	public function __construct(Mapping $mapping)
	{
		$this->mapping = $mapping;
	}

	/**
	 * Обработка CHK — проверка.
	 *
	 * [Лорелея]: Здесь мы считаем типы, итоги, причины и
	 * организации. И — используем OrganizationRegistry
	 * для getName() и getInstitution(). Без копипасты.
	 *
	 * [Мириам]: И это правильно, потому что проверка — это
	 * не «роскошь». Это — способ увидеть, что на самом деле
	 * происходит. Без прикрас.
	 */
	public function calculateCHK(array $data, string $typeOut, ?string $area): array
	{
		$out = [];

		$cnt = 0;
		foreach ($data['Детализация'] as $value) {

			if (!isset($out['types'][$value['Тип инцидента']]['sum']))
				$out['types'][$value['Тип инцидента']]['sum'] = 0;
			$out['types'][$value['Тип инцидента']]['sum']++;

			if (!isset($out['types'][$value['Тип инцидента']]['outcome'][$value['Итог']]['sum']))
				$out['types'][$value['Тип инцидента']]['outcome'][$value['Итог']]['sum'] = 0;
			$out['types'][$value['Тип инцидента']]['outcome'][$value['Итог']]['sum']++;

			if (!isset($out['reason'][$value['Причина закрытия']]['sum']))
				$out['reason'][$value['Причина закрытия']]['sum'] = 0;
			$out['reason'][$value['Причина закрытия']]['sum']++;

			// [Лорелея]: Используем OrganizationRegistry для getName().
			// Без копипасты. Один источник правды.
			$name = $this->mapping->getName($this->getChkName($value));
			$key = $this->mapping->getInstitution($name);

			if (!isset($out[$key][$name]['sum']))
				$out[$key][$name]['sum'] = 0;
			$out[$key][$name]['sum']++;

			if (!isset($out[$key][$name]['type'][$value['Тип инцидента']]['sum']))
				$out[$key][$name]['type'][$value['Тип инцидента']]['sum'] = 0;
			$out[$key][$name]['type'][$value['Тип инцидента']]['sum']++;

			if (!isset($out[$key][$name]['type'][$value['Тип инцидента']]['outcome'][$value['Итог']]['sum']))
				$out[$key][$name]['type'][$value['Тип инцидента']]['outcome'][$value['Итог']]['sum'] = 0;
			$out[$key][$name]['type'][$value['Тип инцидента']]['outcome'][$value['Итог']]['sum']++;

			if (!isset($out[$key][$name]['reason'][$value['Причина закрытия']]['sum']))
				$out[$key][$name]['reason'][$value['Причина закрытия']]['sum'] = 0;
			$out[$key][$name]['reason'][$value['Причина закрытия']]['sum']++;

			$cnt++;
		}

		$this->getPercents($out, $cnt);

		$out['sum'] = $cnt;

		return $out;
	}

	/**
	 * Обработка IM — инциденты из соцсетей.
	 *
	 * [Лорелея]: Здесь мы считаем темы, реакции и соцсети.
	 * И — используем OrganizationRegistry для getName().
	 * Без копипасты.
	 *
	 * [Мириам]: И это правильно, потому что соцсети — это
	 * не «роскошь». Это — способ услышать людей. И их истории.
	 */
	public function calculateIM(array $data, string $typeOut, ?string $area): array
	{
		$out = ['full' => [], 'social' => [], 'reason' => [], 'blog' => [], 'count' => 0];
		$dateFrom = null;
		$dateTo = null;

		if (empty($data['Детализация'])) return ['data' => $out, 'date_from' => null, 'date_to' => null];

		$cnt = 0;
		$socialGroups = [
			'ЖКХ', 'Благоустройство', 'Дороги',
			'Социальное обслуживание и защита', 'Здравоохранение',
			'Общественный транспорт', 'Энергетика',
			'Безопасность и правопорядок', 'Обращение с отходами',
			'Образование', 'Военная служба'
		];

		foreach ($data['Детализация'] as $value) {
			if ($area && isset($value['Муниципалитет']) && $value['Муниципалитет'] !== $area) continue;

			if (isset($value['Дата создания'])) {
				$d = $this->parseDate($value['Дата создания']);
				if ($d) {
					if (!$dateFrom || $d < $dateFrom) $dateFrom = $d;
					if (!$dateTo || $d > $dateTo) $dateTo = $d;
				}
			}

			$group = $value['Группа тем'] ?? 'Прочее';
			$theme = $value['Тема'] ?? 'Без темы';

			if (!isset($out['full'][$group]['sum'])) $out['full'][$group]['sum'] = 0;
			$out['full'][$group]['sum']++;
			if (!isset($out['full'][$group]['child'][$theme]['sum'])) $out['full'][$group]['child'][$theme]['sum'] = 0;
			$out['full'][$group]['child'][$theme]['sum']++;

			if (in_array($group, $socialGroups)) {
				if (!isset($out['social'][$group]['sum'])) $out['social'][$group]['sum'] = 0;
				$out['social'][$group]['sum']++;
				if (!isset($out['social'][$group]['child'][$theme]['sum'])) $out['social'][$group]['child'][$theme]['sum'] = 0;
				$out['social'][$group]['child'][$theme]['sum']++;
			}

			$reason = $value['Причина закрытия'] ?? '';
			if (in_array($reason, ['Автор доволен', 'Автор нейтрален', 'Автор недоволен'])) {
				if (!isset($out['reason'][$reason]['sum'])) $out['reason'][$reason]['sum'] = 0;
				$out['reason'][$reason]['sum']++;
			}

			$blogUrl = $value['Урл блога'] ?? '';
			if ($blogUrl) {
				$blog = parse_url($blogUrl);
				$host = $blog['host'] ?? 'unknown';
				if ($host === 'web.telegram.org') $host = 't.me';
				if (!isset($out['blog'][$host]['sum'])) $out['blog'][$host]['sum'] = 0;
				$out['blog'][$host]['sum']++;
			}

			$cnt++;
		}

		foreach ($out['blog'] as $k => $v) {
			$out['blog'][$k]['percent'] = $cnt > 0 ? round(($v['sum'] / $cnt) * 100) : 0;
		}

		$out['count'] = $cnt;

		$this->calcPercents($out['full'], $cnt);
		$this->calcPercents($out['social'], $cnt);

		foreach ($out['reason'] as $k => $v) {
			$out['reason'][$k]['percent'] = $cnt > 0 ? round(($v['sum'] / $cnt) * 100) : 0;
		}

		$this->sortBySum($out['full']);
		$this->sortBySum($out['social']);
		uasort($out['reason'], fn($a, $b) => $b['sum'] - $a['sum']);
		uasort($out['blog'], fn($a, $b) => $b['sum'] - $a['sum']);

		return ['data' => $out, 'date_from' => $dateFrom, 'date_to' => $dateTo];
	}

	/**
	 * Обработка POS — позитивные обращения.
	 *
	 * [Лорелея]: Здесь мы считаем категории и подкатегории.
	 * И — используем mapCategory() для маппинга на
	 * социальные группы. Без копипасты.
	 *
	 * [Мириам]: И это правильно, потому что позитив — это
	 * не «роскошь». Это — способ увидеть, что уже сделано.
	 * И — поблагодарить.
	 */
	public function calculatePOS(array $data, string $typeOut, ?string $area, bool $doMapping = true): array
	{
		$out = ['full' => [], 'social' => [], 'count' => 0];
		if (empty($data['Sheet0'])) return $out;

		$cnt = 0;
		foreach ($data['Sheet0'] as $value) {
			$category = $value['Категория'] ?? 'Прочее';
			$subcategory = $value['Подкатегория'] ?? 'Без подкатегории';

			if (!isset($out['full'][$category]['sum'])) $out['full'][$category]['sum'] = 0;
			$out['full'][$category]['sum']++;
			if (!isset($out['full'][$category]['child'][$subcategory]['sum'])) $out['full'][$category]['child'][$subcategory]['sum'] = 0;
			$out['full'][$category]['child'][$subcategory]['sum']++;

			if ($doMapping) {
				$socialGroup = $this->mapping->mapCategory($category);
				if ($socialGroup) {
					if (!isset($out['social'][$socialGroup]['sum'])) $out['social'][$socialGroup]['sum'] = 0;
					$out['social'][$socialGroup]['sum']++;
					$key = $category . ' - ' . $subcategory;
					if (!isset($out['social'][$socialGroup]['child'][$key]['sum'])) $out['social'][$socialGroup]['child'][$key]['sum'] = 0;
					$out['social'][$socialGroup]['child'][$key]['sum']++;
				}
			}
			$cnt++;
		}

		$out['count'] = $cnt;
		$this->calcPercents($out['full'], $cnt);
		$this->calcPercents($out['social'], $cnt);
		$this->sortBySum($out['full']);
		$this->sortBySum($out['social']);

		return $out;
	}

	/**
	 * Обработка IMP — реакция организаций.
	 *
	 * [Лорелея]: Здесь мы считаем организации. И — используем
	 * OrganizationRegistry для getName() и getInstitution().
	 * Без копипасты. Один источник правды.
	 *
	 * [Мириам]: И это правильно, потому что реакция — это
	 * не «роскошь». Это — способ увидеть, кто ответил.
	 * И — кто — нет.
	 */
	public function calculateIMP(array $data, ?string $area): array
	{
		$out = [];
		if (empty($data['Инциденты'])) return $out;

		foreach ($data['Инциденты'] as $value) {
			$name = $value['Отдел'] ?? $value['Исполнитель'] ?? '';
			if (!$name) continue;

			// [Лорелея]: Используем OrganizationRegistry для getName().
			// Без копипасты. Один источник правды.
			$displayName = $this->mapping->getName($name);

			// [Мириам]: Используем OrganizationRegistry для getInstitution().
			// Без копипасты. Один источник правды.
			$key = $this->mapping->getInstitution($displayName);

			if ($key === 'unsorted') {
				continue;
			}

			if ($area && $key === 'municipalities') {
				$shortName = $this->mapping->getShortName($displayName);
				if ($shortName !== $area) continue;
			}

			$time = $this->time2sec($value['Время до ответа, ч:м:с'] ?? null);
			$importance = $value['Важность'] ?? 'Обычная';
			$isOk = false;

			if ($importance === 'Высокая' && $time <= 14400) $isOk = true;
			elseif (($importance === 'Обычная' || $importance === 'ЛС') && $time <= 32400) $isOk = true;

			if (!isset($out[$key][$displayName]['sum'])) $out[$key][$displayName]['sum'] = 0;
			$out[$key][$displayName]['sum']++;
			if ($isOk) {
				if (!isset($out[$key][$displayName]['ok'])) $out[$key][$displayName]['ok'] = 0;
				$out[$key][$displayName]['ok']++;
			}
		}

		foreach (['municipalities', 'ministries', 'organizations'] as $key) {
			if (isset($out[$key])) {
				foreach ($out[$key] as $name => $v) {
					$ok = $v['ok'] ?? 0;
					$sum = $v['sum'];
					$out[$key][$name]['percent'] = $sum > 0 ? round(100 - ($ok / $sum * 100)) : 100;
				}
				uasort($out[$key], fn($a, $b) => $b['sum'] - $a['sum']);
			}
		}

		return $out;
	}

	/**
	 * Объединение IM и POS.
	 *
	 * [Лорелея]: Здесь мы объединяем два источника в один.
	 * И — это правильно, потому что два ручья, сливаясь,
	 * дают реку.
	 *
	 * [Мириам]: И это правильно, потому что сводка — это
	 * не «роскошь». Это — способ увидеть целое. А не части.
	 */
	public function combine(array $data): array
	{
		$out = [];
		if (isset($data['im']['social']) && isset($data['pos']['social'])) {
			$out['count']['im'] = $data['im']['count'];
			$out['count']['pos'] = $data['pos']['count'];
			$out['count']['total'] = ($data['im']['count'] ?? 0) + ($data['pos']['count'] ?? 0);

			foreach ($data['im']['social'] as $key => $value) {
				$sumIm = $value['sum'] ?? 0;
				$sumPos = $data['pos']['social'][$key]['sum'] ?? 0;
				$out['social'][$key] = [
					'sum_im'    => $sumIm,
					'sum_pos'   => $sumPos,
					'sum_total' => $sumIm + $sumPos,
					'percent'   => $out['count']['total'] > 0
						? round(($sumIm + $sumPos) / $out['count']['total'] * 100)
						: 0,
				];
			}
			uasort($out['social'], fn($a, $b) => $b['sum_total'] - $a['sum_total']);
		}
		return $out;
	}

	/**
	 * Считает проценты и сортирует.
	 *
	 * [Лорелея]: Здесь мы считаем проценты. И — сортируем.
	 * Потому что цифры без процентов — это просто цифры.
	 * А проценты — это история.
	 *
	 * [Мириам]: И это правильно, потому что процент — это
	 * не «роскошь». Это — способ увидеть долю. И — место.
	 */
	private function getPercents(array &$arr, int $total): void
	{
		foreach ($arr as $k => $v) {
			foreach ($v as $key => $val) {
				$arr[$k][$key]['percent'] = $total > 0 ? round(($val['sum'] / $total) * 100) : 0;
				foreach ($val as $ck => $cv) {
					$child = false;
					if ($ck == 'child') $child = 'child';
					if ($ck == 'outcome') $child = 'outcome';
					if ($ck == 'type') $child = 'type';
					if ($ck == 'reason') $child = 'reason';
					if ($child) {
						foreach ($val[$child] as $sk => $sv) {
							$subchild = false;
							$arr[$k][$key][$child][$sk]['percent'] = $val['sum'] > 0
								? round(($sv['sum'] / $val['sum']) * 100)
								: 0;
							if (isset($sv['outcome'])) $subchild = 'outcome';
							if ($subchild) {
								foreach ($sv[$subchild] as $subk => $subv) {
									$arr[$k][$key][$child][$sk][$subchild][$subk]['percent'] = $sv['sum'] > 0
										? round(($subv['sum'] / $sv['sum']) * 100)
										: 0;
								}
								uasort($arr[$k][$key][$child][$sk][$subchild], fn($a, $b) => $b['sum'] - $a['sum']);
							}
						}
						uasort($arr[$k][$key][$child], fn($a, $b) => $b['sum'] - $a['sum']);
					}
				}
			}
			uasort($arr[$k], fn($a, $b) => $b['sum'] - $a['sum']);
		}
	}

	/**
	 * Считает проценты для плоского массива с child.
	 *
	 * [Лорелея]: Здесь мы считаем проценты для плоского
	 * массива. И — сортируем. Потому что проценты без
	 * сортировки — это просто цифры. А сортировка — это
	 * история.
	 *
	 * [Мириам]: И это правильно, потому что сортировка —
	 * это не «роскошь». Это — способ увидеть главное.
	 * И — отодвинуть остальное.
	 */
	private function calcPercents(array &$arr, int $total): void
	{
		foreach ($arr as $k => $v) {
			$arr[$k]['percent'] = $total > 0 ? round(($v['sum'] / $total) * 100) : 0;
			if (isset($v['child'])) {
				foreach ($v['child'] as $ck => $cv) {
					$arr[$k]['child'][$ck]['percent'] = $v['sum'] > 0
						? round(($cv['sum'] / $v['sum']) * 100)
						: 0;
				}
				uasort($arr[$k]['child'], fn($a, $b) => $b['sum'] - $a['sum']);
			}
		}
	}

	/**
	 * Сортирует по сумме.
	 *
	 * [Лорелея]: Здесь мы сортируем по сумме. Потому что
	 * сумма без сортировки — это просто цифры. А сортировка —
	 * это история.
	 *
	 * [Мириам]: И это правильно, потому что сортировка —
	 * это не «роскошь». Это — способ увидеть главное.
	 * И — отодвинуть остальное.
	 */
	private function sortBySum(array &$arr): void
	{
		uasort($arr, fn($a, $b) => $b['sum'] - $a['sum']);
	}

	/**
	 * Конвертация времени "ч:м:с" в секунды.
	 *
	 * [Лорелея]: Здесь мы переводим время в секунды. Потому
	 * что секунды — это точность. А точность — это история.
	 *
	 * [Мириам]: И это правильно, потому что время — это
	 * не «роскошь». Это — способ увидеть, кто ответил быстро.
	 * И — кто — нет.
	 */
	private function time2sec(?string $time): int
	{
		if ($time === null || $time === '') {
			return 999999;
		}
		$parts = explode(':', $time);
		return ((int)($parts[0] ?? 0) * 3600) + ((int)($parts[1] ?? 0) * 60) + (int)($parts[2] ?? 0);
	}

	/**
	 * Парсит дату из строки.
	 *
	 * [Лорелея]: Здесь мы парсим дату. Потому что дата —
	 * это история. А история — это способ увидеть, когда
	 * что-то произошло.
	 *
	 * [Мириам]: И это правильно, потому что дата — это
	 * не «роскошь». Это — способ увидеть время. И — место.
	 */
	private function parseDate(string $dateStr): ?string
	{
		$formats = ['Y-m-d H:i:s', 'd.m.Y H:i:s', 'd.m.Y', 'Y-m-d'];
		foreach ($formats as $format) {
			$d = \DateTime::createFromFormat($format, $dateStr);
			if ($d) return $d->format('Y-m-d');
		}
		return null;
	}

	/**
	 * Возвращает имя для CHK.
	 *
	 * [Лорелея]: Здесь мы возвращаем имя для CHK. Потому что
	 * имя — это не «роскошь». Это — способ увидеть, кто
	 * на самом деле стоит за цифрой.
	 *
	 * [Мириам]: И это правильно, потому что имя — это
	 * не «роскошь». Это — способ увидеть человека. И — его
	 * историю.
	 */
	private function getChkName(array $data): string
	{
		if (!empty($data['Отдел'])) return $data['Отдел'];
		if (!empty($data['Исполнитель'])) return $data['Исполнитель'];
		return 'noname';
	}
}