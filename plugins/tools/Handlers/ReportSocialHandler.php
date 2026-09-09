<?php

namespace Jan\Trinity\Plugin\Tools\Handlers;

use PhpOffice\PhpSpreadsheet\IOFactory;
//use PhpOffice\PhpWord\PhpWord;
//use PhpOffice\PhpWord\IOFactory;

class ReportSocialHandler
{
    /**
     * Обработка отчёта по соцсетям.
     * 
     * @param array $files — ['im' => '/tmp/...', 'pos' => '/tmp/...', 'imp' => '/tmp/...']
     * @param array $params — ['type_out' => 'alltheme', 'area' => 'Симферополь', 'to_word' => false]
     * @return array
     */
    public function process(array $files, array $params): array
    {
        $typeOut = $params['type_out'] ?? 'alltheme';
        $area = $params['area'] ?? null;
        $toWord = $params['to_word'] ?? false;

        $data = [];
        $dateFrom = null;
        $dateTo = null;

        // IM
        if (isset($files['im'])) {
            $imData = $this->parseExcel($files['im'], 'Детализация');
            $imResult = $this->getIM($imData, $typeOut, $area);
            $data['im'] = $imResult['data'];
            if ($imResult['date_from']) $dateFrom = $imResult['date_from'];
            if ($imResult['date_to']) $dateTo = $imResult['date_to'];
        }

        $data['params'] = [
            'type_out'  => $typeOut,
            'area'      => $area,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'to_word'   => $toWord,
        ];

        // POS
        $hasBoth = isset($files['im']) && isset($files['pos']);

        if (isset($files['pos'])) {
            $posData = $this->parseExcel($files['pos'], 'Sheet0');
            $data['pos'] = $this->getPOS($posData, $typeOut, $area, $hasBoth);
        }
        if (isset($files['pos']) && $typeOut === 'org') {
            $posData = $this->parseExcel($files['pos'], 'Sheet0');
            $data['org'] = $this->getOrgFromPOS($posData);
        }

        // IMP
        if (isset($files['imp'])) {
            $impData = $this->parseExcel($files['imp'], 'Инциденты');
            $data['imp'] = $this->getIMP($impData, $area);
        }

        // Сводка
        if (isset($data['im']) && isset($data['pos'])) {
            $data['combine'] = $this->getCombine($data);
        }

        // WORD
        if ($toWord) {
            $wordFile = $this->generateWord($data, $typeOut, $area);
            $data['word_file'] = $wordFile;
        }

        return $data;
    }

	/**
	 * Читает Excel-файл в ассоциативный массив с ключом по имени листа
	 */
	private function parseExcel(string $filePath, string $sheetName): array
	{
		$spreadsheet = IOFactory::load($filePath);
		$sheet = $spreadsheet->getSheetByName($sheetName);
		if (!$sheet) return [];
		$rows = $sheet->toArray();
		if (empty($rows)) return [];
		$headers = array_shift($rows);
		$data = [];
		foreach ($rows as $row) {
			if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) continue;
			$row = array_slice($row, 0, count($headers));
			while (count($row) < count($headers)) $row[] = '';
			$data[] = array_combine($headers, $row);
		}
		return [$sheetName => $data];
	}

	/**
	 * Обработка IM — инциденты из соцсетей
	 */
	private function getIM(array $data, string $typeOut, ?string $area): array
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
			// Фильтр по локации
			if ($area && isset($value['Муниципалитет']) && $value['Муниципалитет'] !== $area) continue;

			// Даты
			if (isset($value['Дата создания'])) {
				$d = $this->parseDate($value['Дата создания']);
				if ($d) {
					if (!$dateFrom || $d < $dateFrom) $dateFrom = $d;
					if (!$dateTo || $d > $dateTo) $dateTo = $d;
				}
			}

			// Группа тем → тема
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

			// Причина закрытия
			$reason = $value['Причина закрытия'] ?? '';
			if (in_array($reason, ['Автор доволен', 'Автор нейтрален', 'Автор недоволен'])) {
				if (!isset($out['reason'][$reason]['sum'])) $out['reason'][$reason]['sum'] = 0;
				$out['reason'][$reason]['sum']++;
			}

			// Блог
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
		// Проценты для блогов
		foreach ($out['blog'] as $k => $v) {
			$out['blog'][$k]['percent'] = $cnt > 0 ? round(($v['sum'] / $cnt) * 100) : 0;
		}

		$out['count'] = $cnt;

		// Проценты для full
		$this->calcPercents($out['full'], $cnt);
		// Проценты для social
		$this->calcPercents($out['social'], $cnt);
		// Проценты для reason
		foreach ($out['reason'] as $k => $v) {
			$out['reason'][$k]['percent'] = $cnt > 0 ? round(($v['sum'] / $cnt) * 100) : 0;
		}

		// Сортировка
		$this->sortBySum($out['full']);
		$this->sortBySum($out['social']);
		uasort($out['reason'], fn($a, $b) => $b['sum'] - $a['sum']);
		uasort($out['blog'], fn($a, $b) => $b['sum'] - $a['sum']);

		return ['data' => $out, 'date_from' => $dateFrom, 'date_to' => $dateTo];
	}

	/**
	 * Обработка POS — позитивные обращения
	 */
	private function getPOS(array $data, string $typeOut, ?string $area, bool $doMapping = true): array
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
				$socialGroup = $this->mapPOSCategory($category);
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
	 * Маппинг категорий POS на социальные группы
	 */
	private function mapPOSCategory(string $category): ?string
	{
		$map = [
			'Медицина' => 'Здравоохранение',
			'Электронная запись на прием к врачу' => 'Здравоохранение',
			'Обращение по проблеме льготного лекарственного обеспечения' => 'Здравоохранение',
			'Телефонные обращения по вопросам здравоохранения' => 'Здравоохранение',
			'Обращение по проблеме вакцинации или лечения от Коронавируса' => 'Здравоохранение',
			'Больничный лист' => 'Здравоохранение',
			'Медицинский персонал' => 'Здравоохранение',
			'Автомобильные дороги' => 'Дороги',
			'Состояние дорог' => 'Дороги',
			'Многоквартирные дома' => 'ЖКХ',
			'Дворы и территории общего пользования' => 'ЖКХ',
			'Водоснабжение' => 'ЖКХ',
			'Управляющая компания ' => 'ЖКХ',
			'Плата за ЖКУ' => 'ЖКХ',
			'Холодное водоснабжение' => 'ЖКХ',
			'Содержание многоквартирного дома' => 'ЖКХ',
			'Горячее водоснабжение' => 'ЖКХ',
			'Теплоснабжение' => 'ЖКХ',
			'Жилищная политика' => 'ЖКХ',
			'Иные вопросы в сфере ЖКХ' => 'ЖКХ',
			'Лицензирование управляющих компаний многоквартирных домов' => 'ЖКХ',
			'Придомовая территория' => 'ЖКХ',
			'Образование' => 'Образование',
			'Сведения об образовании и достижениях на портале Госуслуг' => 'Образование',
			'Горячее питание для младшеклассников' => 'Образование',
			'Колледж, техникум' => 'Образование',
			'Строительство школ, детских садов' => 'Образование',
			'Социальное обслуживание и защита' => 'Социальное обслуживание и защита',
			'Электронное удостоверение многодетной семьи' => 'Социальное обслуживание и защита',
			'Трудовые права' => 'Социальное обслуживание и защита',
			'Выплаты и пособия' => 'Социальное обслуживание и защита',
			'Материнский капитал' => 'Социальное обслуживание и защита',
			'Инвалидность' => 'Социальное обслуживание и защита',
			'Гарантии и условия труда отдельных категорий работников' => 'Социальное обслуживание и защита',
			'Мусор' => 'Обращение с отходами',
			'Общественный транспорт' => 'Общественный транспорт',
			'Электроснабжение' => 'Энергетика',
			'Газоснабжение' => 'Энергетика',
			'Нефть и нефтепродукты' => 'Энергетика',
			'Электроэнергетика' => 'Энергетика',
			'Благоустройство' => 'Благоустройство',
			'Обращения военнослужащих и их семей' => 'Военная служба',
			'Помощь участникам СВО (ФЗО)' => 'Военная служба',
			'Ветераны боевых действий' => 'Военная служба',
			'Обжалование решений по воинскому учету' => 'Военная служба',
		];
		return $map[$category] ?? null;
	}

	/**
	 * Обработка IMP — реакция организаций
	 */
	private function getIMP(array $data, ?string $area): array
	{
		// Оставляем существующую логику, но добавляем фильтр по area если нужно
		// Существующий код getIMP...
		$out = [];
		if (empty($data['Инциденты'])) return $out;

		$names = [
			'Администрация города Симферополя'=>'г. Симферополь',
			'Администрация Города Старый Крым Кировского района'=>'г. Старый Крым',
			'Администрация города Ялта'=>'г. Ялта',
			'Администрация Кировского района'=>'Кировский район',
			'Администрация Красноперекопского района'=>'Красноперекопский район',
			'Алушта РК'=>'г. Алушта',
			'Армянск РК'=>'г. Армянск',
			'Джанкой РК'=>'г. Джанкой',
			'Евпатория'=>'г. Евпатория',
			'Керчь'=>'г. Керчь',
			'Красноперекопск РК'=>'г. Красноперекопск',
			'Саки РК'=>'г. Саки',
			'Феодосия РК'=>'г. Феодосия',

			'ГК по делам архивов РК'=>'ГК по делам архивов',
			'ГК по делам межнациональных отношений Республики Крым'=>'ГК по делам межнациональных отношений',
			'Госкомрегистр'=>'ГK по государственной регистрации и кадастру',
			'Инспекция по жилищному надзору РК'=>'Инспекция по жилищному надзору',
			'Минздрав РК'=>'Министерство здравоохранения',
			'Мининформ'=>'Министерство внутренней политики, информации и связи',
			'Министерство жилищно -коммунального хозяйства'=>'Министерство жилищно - коммунального хозяйства',
			'Министерство спорта РК'=>'Министерство спорта',
			'Минкурортов'=>'Министерство курортов и туризма',
			'Минобраз'=>'Министерство образования, науки и молодежи',
			'Минстрой'=>'Министерство строительства и архитектуры',
			'Минтоп РК'=>'Министерство топлива и энергетики',
			'Минтранспорта'=>'Министерство транспорта',
			'Минтруд'=>'Министерство труда и социальной защиты',
			'МЧС РК'=>'Министерство чрезвычайных ситуаций',

			'КТКЭ'=>'ГУП РК «Крымтеплокоммунэнерго»',
			'АО "Киевский Жилсервис"'=>'АО «Киевский Жилсервис»',
			'Вода Крыма'=>'ГУП РК «Вода Крыма»',
			'ГУП РК Крымавтодор'=>'ГУП РК «Крымавтодор»',
			'Инвестстрой'=>'ГКУ РК «Инвестстрой»',
			'Крымтроллейбус'=>' ГУП РК «Крымтроллейбус»',
			'Крымэнерго'=>'ГУП РК «Крымэнерго»',
			'РО АО Крымэкоресурсы'=>'РО АО «Крымэкоресурсы»',
			'РО ГУП РК "Крымгазсети"'=>'РО ГУП РК «Крымгазсети»',
			'САД РК'=>'ГКУ РК «Служба автомобильных дорог»',
		];

		$municipalities = [
			'Администрация города Симферополя',
			'Администрация Города Старый Крым Кировского района',
			'Администрация города Ялта',
			'Администрация Кировского района',
			'Администрация Красноперекопского района',
			'Алушта РК',
			'Армянск РК',
			'Бахчисарайский район',
			'Белогорский район',
			'г. Бахчисарай',
			'г. Судак',
			'Джанкой РК',
			'Джанкойский район',
			'Евпатория',
			'Керчь',
			'Красногвардейский район',
			'Красноперекопск РК',
			'Ленинский район',
			'Первомайский район',
			'Саки РК',
			'Сакский район',
			'Симферопольский район',
			'Феодосия РК',
		];

		$ministries = [
			'ГБУ РК Многофункциональный центр предоставления государственных и муниципальных услуг',
			'ГК по делам архивов РК',
			'ГК по делам межнациональных отношений Республики Крым',
			'Госкомрегистр',
			'Инспекция по жилищному надзору РК',
			'Минздрав РК',
			'Мининформ',
			'Министерство жилищно -коммунального хозяйства',
			'Министерство имущественных и земельных отношений',
			'Министерство культуры',
			'Министерство сельского хозяйства',
			'Министерство спорта РК',
			'Министерство финансов',
			'Министерство экологии и природных ресурсов',
			'Министерство юстиции',
			'Минкурортов',
			'Минобраз',
			'Минстрой',
			'Минтоп РК',
			'Минтранспорта',
			'Минтруд',
			'МЧС РК',
			'Роспотребнадзор',
			'ФОИВ Фонд "Защитники Отечества" в Республике Крым',
		];

		$organizations = [
			'КТКЭ',
			'АО "Киевский Жилсервис"',
			'Вода Крыма',
			'ГУП РК Крымавтодор',
			'Инвестстрой',
			'Крымтроллейбус',
			'Крымэнерго',
			'Региональный фонд капитального ремонта',
			'РО АО Крымэкоресурсы',
			'РО АО Крымэкоресурсы',
			'РО ГУП РК "Крымгазсети"',
			'РО ГУП РК «Водоканал ЮБК»',
			'САД РК',
		];

		foreach ($data['Инциденты'] as $value) {
			$name = $value['Отдел'] ?? $value['Исполнитель'] ?? '';
			if (!$name) continue;

			// Фильтр по локации — только для муниципалитетов
			if ($area && in_array($name, $municipalities)) {
				$shortName = $names[$name] ?? $name;
				if ($shortName !== $area) continue;
			}

			$time = $this->time2sec($value['Время до ответа, ч:м:с'] ?? null);
			$importance = $value['Важность'] ?? 'Обычная';
			$isOk = false;

			if ($importance === 'Высокая' && $time <= 14400) $isOk = true;
			elseif (($importance === 'Обычная' || $importance === 'ЛС') && $time <= 32400) $isOk = true;

			$displayName = $names[$name] ?? $name;

			if (in_array($name, $municipalities)) {
				$key = 'municipalities';
			} elseif (in_array($name, $ministries)) {
				$key = 'ministries';
			} elseif (in_array($name, $organizations)) {
				$key = 'organizations';
			} else {
				continue;
			}

			if (!isset($out[$key][$displayName]['sum'])) $out[$key][$displayName]['sum'] = 0;
			$out[$key][$displayName]['sum']++;
			if ($isOk) {
				if (!isset($out[$key][$displayName]['ok'])) $out[$key][$displayName]['ok'] = 0;
				$out[$key][$displayName]['ok']++;
			}
		}

		// Проценты и сортировка
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
	 * Объединение IM и POS
	 */
	private function getCombine(array $data): array
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
					'sum_im' => $sumIm,
					'sum_pos' => $sumPos,
					'sum_total' => $sumIm + $sumPos,
					'percent' => $out['count']['total'] > 0
						? round(($sumIm + $sumPos) / $out['count']['total'] * 100)
						: 0,
				];
			}
			uasort($out['social'], fn($a, $b) => $b['sum_total'] - $a['sum_total']);
		}
		return $out;
	}

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

	private function sortBySum(array &$arr): void
	{
		uasort($arr, fn($a, $b) => $b['sum'] - $a['sum']);
	}

	/**
	 * Конвертация времени "ч:м:с" в секунды
	 */
	private function time2sec(?string $time): int
	{
		if ($time === null || $time === '') {
			return 999999; // Очень большое значение — точно не уложится в норматив
		}
		$parts = explode(':', $time);
		return ((int)($parts[0] ?? 0) * 3600) + ((int)($parts[1] ?? 0) * 60) + (int)($parts[2] ?? 0);
	}

	private function parseDate(string $dateStr): ?string
	{
		// Пробуем разные форматы
		$formats = ['Y-m-d H:i:s', 'd.m.Y H:i:s', 'd.m.Y', 'Y-m-d'];
		foreach ($formats as $format) {
			$d = \DateTime::createFromFormat($format, $dateStr);
			if ($d) return $d->format('Y-m-d');
		}
		return null;
	}

	private function generateWord(array $data, string $typeOut, ?string $area): string
	{
		$phpWord = new \PhpOffice\PhpWord\PhpWord();
		
		// Стили
		$fontStyleName1 = 'RmcUserDefinedStyle1';
		$fontStyleName = 'RmcUserDefinedStyle';
		$rmcFontStyleName = 'RmcDefinedStyle';
		
		$phpWord->addFontStyle($rmcFontStyleName, [
			'name' => 'PT Sans',
			'size' => 8,
			'color' => '2790d4',
		]);
		$phpWord->addFontStyle($fontStyleName1, [
			'name' => 'Times New Roman',
			'size' => 14,
			'color' => 'FFFFFF',
			'bold' => true,
		]);
		$phpWord->addFontStyle($fontStyleName, [
			'name' => 'Times New Roman',
			'size' => 14,
			'color' => '000000',
		]);

		$phpWord->addTableStyle('myTable', [
			'borderColor' => '000000',
			'border' => 0,
			'cellMargin' => 0,
		]);
		
		$phpWord->addTableStyle('orgTable', [
			'borderColor' => '000000',
			'borderSize' => 1,
			'cellMargin' => 100,
		]);

		$cellColSpan = ['gridSpan' => 3, 'borderColor' => '000000', 'borderSize' => 1];
		$cellStyle = ['bgColor' => '999999'];
		$noSpace = ['spaceAfter' => 0, 'indent' => 0.1];
		
		$sectionStyle = [
			'marginTop' => 550,
			'marginRight' => 850,
			'marginBottom' => 850,
			'marginLeft' => 1400,
			'headerHeight' => 500,
		];
		
		$section = $phpWord->addSection($sectionStyle);
		
		// Хедер с логотипом и информацией
		$header = $section->addHeader();
		$header->firstPage();
		$htable = $header->addTable('myTable');
		$htable->addRow();
		
		// Логотип
		$logoPath = dirname(__DIR__, 3) . '/public/assets/img/logo.png';
		if (file_exists($logoPath)) {
			$htable->addCell(4000)->addImage($logoPath, ['width' => 121, 'height' => 51]);
		} else {
			$htable->addCell(4000)->addText('ЦУР', $rmcFontStyleName);
		}
		
		$textrun = $htable->addCell()->addTextRun();
		$textrun->addText('Обособленное подразделение АНО по развитию цифровых проектов ', $rmcFontStyleName);
		$textrun->addTextBreak();
		$textrun->addText('в сфере общественных связей и коммуникаций «Диалог Регионы»', $rmcFontStyleName);
		$textrun->addTextBreak();
		$textrun->addText('в Республике Крым', $rmcFontStyleName);
		$textrun->addTextBreak();
		$textrun->addText('', $rmcFontStyleName);
		$textrun->addTextBreak();
		$textrun->addText('Петропавловская улица, д. 12, Симферополь, Республика Крым, 295000', $rmcFontStyleName);
		$textrun->addTextBreak();
		$textrun->addText('Тел.: +7 (3652) 50-08-27', $rmcFontStyleName);

		$areaName = $area ?: 'Республика Крым';
		$dateFrom = $data['params']['date_from'] ?? '';
		$dateTo = $data['params']['date_to'] ?? '';
		$typeIn = isset($data['im']) ? 'IM' : 'POS';

		$file_name = $typeIn . '_' . $dateFrom . '-' . $dateTo;
		$countOut = $data['im']['count'] ?? $data['pos']['count'] ?? 0;

		// ========================================
		// ОРГАНИЗАЦИИ
		// ========================================
		if ($typeOut === 'org') {
			$file_name .= '_organizacii';
			
			$table = $section->addTable([
				'borderColor' => '000000',
				'borderSize' => 1,
				'cellMargin' => 100,
			]);
			
			$orgData = $data['org'] ?? [];
			
			$phpWord->addLinkStyle('lStyle_prs', [
				'name' => 'Times New Roman',
				'size' => 14,
				'color' => 'FF0000',
			]);
			$phpWord->addLinkStyle('lStyle_oks', [
				'name' => 'Times New Roman',
				'size' => 14,
				'color' => '0000FF',
			]);
			
			$cellStyle = ['borderColor' => '000000', 'borderSize' => 1];
			$cellColSpan = ['gridSpan' => 3, 'borderColor' => '000000', 'borderSize' => 1];
			
			foreach ($orgData as $key => $val) {
				$prs = $val['prs'] ?? 0;
				
				$table->addRow();
				$table->addCell(6000, $cellStyle)->addText($key, $fontStyleName);
				$table->addCell(2000, $cellStyle)->addText('Всего: ' . $val['sum'], $fontStyleName);
				$table->addCell(2000, $cellStyle)->addText('Просрочено: ' . $prs, $fontStyleName);
				
				foreach ($val['stage'] as $skey => $sval) {
					$sprs = $sval['prs'] ?? 0;
					
					$table->addRow();
					$table->addCell(6000, $cellStyle)->addText('     ' . $skey, $fontStyleName);
					$table->addCell(2000, $cellStyle)->addText('Всего: ' . $sval['sum'], $fontStyleName);
					$table->addCell(2000, $cellStyle)->addText('Просрочено: ' . $sprs, $fontStyleName);
					
					if (!empty($sval['prs_ids'])) {
						$table->addRow();
						$cell = $table->addCell(10000, $cellColSpan);
						$textRun = $cell->addTextRun();
						foreach ($sval['prs_ids'] as $okey => $oval) {
							$textRun->addLink(
								'https://pos.gosuslugi.ru/backoffice/appeals/' . $oval,
								$oval,
								'lStyle_prs'
							);
							$textRun->addText(' ', $fontStyleName);
						}
					}
					
					if (!empty($sval['oks_ids'])) {
						$table->addRow();
						$cell = $table->addCell(10000, $cellColSpan);
						$textRun = $cell->addTextRun();
						foreach ($sval['oks_ids'] as $okey => $oval) {
							$textRun->addLink(
								'https://pos.gosuslugi.ru/backoffice/appeals/' . $oval,
								$oval,
								'lStyle_oks'
							);
							$textRun->addText(' ', $fontStyleName);
						}
					}
				}
				
				$table->addRow();
				$table->addCell(10000, ['gridSpan' => 3])->addText('', $fontStyleName);
			}
		}

		// ========================================
		// ТОП-5 ТЕМ
		// ========================================
		if ($typeOut === 'top5theme') {
			$section->addText(
				'<w:br/>В ' . $areaName . ' за период с ' . $dateFrom . ' по ' . $dateTo . 
				' в ' . $typeIn . ' поступило ' . $countOut . ' обращений<w:br/>',
				$fontStyleName
			);
			
			$table = $section->addTable([
				'width' => 100 * 50,
				'unit' => 'pct',
				'align' => 'center',
			]);
			
			$tgcellStyle = ['borderColor' => '000000', 'borderSize' => 1, 'bgColor' => '000000'];
			$cellStyle = ['borderColor' => '000000', 'borderSize' => 1];
			
			$i = 1;
			$themes = $data['im']['full'] ?? $data['pos']['full'] ?? [];
			
			foreach ($themes as $key => $val) {
				$table->addRow();
				$table->addCell(null, $tgcellStyle)->addText($key, $fontStyleName1, $noSpace);
				$table->addCell(1400, $tgcellStyle)->addText($val['sum'], $fontStyleName1, $noSpace);
				$table->addCell(1400, $tgcellStyle)->addText($val['percent'] . '%', $fontStyleName1, $noSpace);
				
				if ($i <= 5 && isset($val['child'])) {
					$ic = 1;
					foreach ($val['child'] as $skey => $sval) {
						if ($ic <= 5) {
							$table->addRow();
							$table->addCell(null, $cellStyle)->addText($skey, $fontStyleName, $noSpace);
							$table->addCell(1400, $cellStyle)->addText($sval['sum'], $fontStyleName, $noSpace);
							$table->addCell(1400, $cellStyle)->addText($sval['percent'] . '%', $fontStyleName, $noSpace);
						}
						$ic++;
					}
				}
				$i++;
			}
			
			// Реакции
			if (isset($data['im']['reason'])) {
				$section->addText('<w:br/>Реакции:', $fontStyleName);
				$rtable = $section->addTable([
					'width' => 100 * 50,
					'unit' => 'pct',
					'align' => 'center',
				]);
				foreach ($data['im']['reason'] as $rkey => $rval) {
					$rtable->addRow();
					$rtable->addCell(null, $cellStyle)->addText($rkey, $fontStyleName, $noSpace);
					$rtable->addCell(1000, $cellStyle)->addText($rval['sum'], $fontStyleName, $noSpace);
					$rtable->addCell(1000, $cellStyle)->addText($rval['percent'] . '%', $fontStyleName, $noSpace);
				}
			}
			
			// Соцсети
			if (isset($data['im']['blog'])) {
				$section->addText('<w:br/>Соц. сети:', $fontStyleName);
				$utable = $section->addTable([
					'width' => 100 * 50,
					'unit' => 'pct',
					'align' => 'center',
				]);
				foreach ($data['im']['blog'] as $ukey => $uval) {
					$utable->addRow();
					$utable->addCell(null, $cellStyle)->addText($ukey, $fontStyleName, $noSpace);
					$utable->addCell(1000, $cellStyle)->addText($uval['sum'], $fontStyleName, $noSpace);
					$utable->addCell(1000, $cellStyle)->addText($uval['percent'] . '%', $fontStyleName, $noSpace);
				}
			}
		}

		// ========================================
		// ВСЕ ТЕМЫ
		// ========================================
		if ($typeOut === 'alltheme') {
			$section->addText(
				'<w:br/>В ' . $areaName . ' за период с ' . $dateFrom . ' по ' . $dateTo . 
				' в ' . $typeIn . ' поступило ' . $countOut . ' обращений<w:br/>',
				$fontStyleName
			);
			
			$table = $section->addTable([
				'width' => 100 * 50,
				'unit' => 'pct',
				'align' => 'center',
			]);
			
			$tgcellStyle = ['borderColor' => 'black', 'borderSize' => 1, 'bgColor' => 'black'];
			$cellStyle = ['borderColor' => 'black', 'borderSize' => 1];
			
			$themes = $data['im']['full'] ?? $data['pos']['full'] ?? [];
			
			foreach ($themes as $key => $val) {
				$table->addRow();
				$table->addCell(null, $tgcellStyle)->addText($key, $fontStyleName1, $noSpace);
				$table->addCell(1400, $tgcellStyle)->addText($val['sum'], $fontStyleName1, $noSpace);
				$table->addCell(1400, $tgcellStyle)->addText($val['percent'] . '%', $fontStyleName1, $noSpace);
				
				if (isset($val['child'])) {
					foreach ($val['child'] as $skey => $sval) {
						$table->addRow();
						$table->addCell(null, $cellStyle)->addText($skey, $fontStyleName, $noSpace);
						$table->addCell(1400, $cellStyle)->addText($sval['sum'], $fontStyleName, $noSpace);
						$table->addCell(1400, $cellStyle)->addText($sval['percent'] . '%', $fontStyleName, $noSpace);
					}
				}
			}
			
			// Реакции
			if (isset($data['im']['reason'])) {
				$section->addText('<w:br/>Реакции:', $fontStyleName);
				$rtable = $section->addTable([
					'width' => 100 * 50,
					'unit' => 'pct',
					'align' => 'center',
				]);
				foreach ($data['im']['reason'] as $rkey => $rval) {
					$rtable->addRow();
					$rtable->addCell(null, $cellStyle)->addText($rkey, $fontStyleName, $noSpace);
					$rtable->addCell(1000, $cellStyle)->addText($rval['sum'], $fontStyleName, $noSpace);
					$rtable->addCell(1000, $cellStyle)->addText($rval['percent'] . '%', $fontStyleName, $noSpace);
				}
			}
			
			// Соцсети
			if (isset($data['im']['blog'])) {
				$section->addText('<w:br/>Соц. сети:', $fontStyleName);
				$utable = $section->addTable([
					'width' => 100 * 50,
					'unit' => 'pct',
					'align' => 'center',
				]);
				foreach ($data['im']['blog'] as $ukey => $uval) {
					$utable->addRow();
					$utable->addCell(null, $cellStyle)->addText($ukey, $fontStyleName, $noSpace);
					$utable->addCell(1000, $cellStyle)->addText($uval['sum'], $fontStyleName, $noSpace);
					$utable->addCell(1000, $cellStyle)->addText($uval['percent'] . '%', $fontStyleName, $noSpace);
				}
			}
		}

		// Сохраняем файл
		$uploadDir = __DIR__ . '/../../../public/uploads';
		if (!is_dir($uploadDir)) {
			mkdir($uploadDir, 0775, true);
		}

		$fileName = $file_name . '.docx';
		$filePath = $uploadDir . '/' . $fileName;

		$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
		$writer->save($filePath);

		return '/uploads/' . $fileName;
	}

	/**
	 * Обработка организаций из POS-файла
	 * Колонки: 0 - Номер, 8 - Организация, 12 - Стадия, 14 - Просрочено
	 */
	private function getOrgFromPOS(array $data): array
	{
		$out = [];
		if (empty($data['Sheet0'])) return $out;

		foreach ($data['Sheet0'] as $row) {
			$org = $row['Организация, в которой находится сообщение'] ?? $row['Организация'] ?? 'Неизвестно';
			$stage = $row['Стадия'] ?? 'Без стадии';
			$overdue = $row['Просрочено'] ?? 'Нет';
			$number = $row['Номер'] ?? '';

			if (!isset($out[$org]['sum'])) $out[$org]['sum'] = 0;
			$out[$org]['sum']++;

			if ($overdue === 'Да') {
				if (!isset($out[$org]['prs'])) $out[$org]['prs'] = 0;
				$out[$org]['prs']++;
			}

			if (!isset($out[$org]['stage'][$stage]['sum'])) $out[$org]['stage'][$stage]['sum'] = 0;
			$out[$org]['stage'][$stage]['sum']++;

			if ($overdue === 'Да') {
				if (!isset($out[$org]['stage'][$stage]['prs'])) $out[$org]['stage'][$stage]['prs'] = 0;
				$out[$org]['stage'][$stage]['prs']++;
				$out[$org]['stage'][$stage]['prs_ids'][] = $number;
			}

			if ($overdue === 'Нет') {
				$out[$org]['stage'][$stage]['oks_ids'][] = $number;
			}
		}

		$sort = array_column($out, 'sum');
		array_multisort($sort, SORT_DESC, $out);

		return $out;
	}
}
