<?php
declare(strict_types=1);

/**
 * ОБРАБОТЧИК ОТЧЁТА ПО СОЦСЕТЯМ
 *
 * Читает Excel-файлы, считает темы, реакции, соцсети
 * и организации, а потом собирает всё в один отчёт.
 *
 * @package Jan\Trinity\Plugin\Tools\Handlers
 */

namespace Jan\Trinity\Plugin\Tools\Handlers;

use Jan\Trinity\Plugin\Tools\SocialReportParser;
use Jan\Trinity\Plugin\Tools\SocialReportCalculator;
use Jan\Trinity\Plugin\Tools\SocialReportWordGenerator;

class ReportSocialHandler
{
	/** @var SocialReportParser — парсер Excel. */
	private SocialReportParser $parser;

	/** @var SocialReportCalculator — калькулятор отчёта. */
	private SocialReportCalculator $calculator;

	/** @var SocialReportWordGenerator — генератор Word. */
	private SocialReportWordGenerator $wordGenerator;

	public function __construct(
		SocialReportParser $parser,
		SocialReportCalculator $calculator,
		SocialReportWordGenerator $wordGenerator
	) {
		$this->parser = $parser;
		$this->calculator = $calculator;
		$this->wordGenerator = $wordGenerator;
	}

	/**
	 * Дамп для отладки.
	 */
	private function dump($in): string
	{
		$out = '';
		if (is_object($in)) {
			$out .= '<pre>';
			$out .= print_r(var_dump($in), true);
			$out .= '</pre>';
		} elseif (is_array($in)) {
			$out .= '<pre>';
			$out .= print_r($in, true);
			$out .= '</pre>';
		} else {
			$out .= $in;
		}
		return $out;
	}

	/**
	 * Обработка отчёта по соцсетям.
	 *
	 * @param array $files — ['im' => '/tmp/...', 'pos' => '/tmp/...', 'imp' => '/tmp/...']
	 * @param array $params — ['type_out' => 'alltheme', 'area' => 'Симферополь', 'to_word' => false]
	 * @return array
	 */
	public function process(array $files, array $params): array
	{
		$oldMemoryLimit = ini_get('memory_limit');
		ini_set('memory_limit', '1024M');

		$oldTimeLimit = (int) ini_get('max_execution_time');
		set_time_limit(300);

		try {
			$typeOut = $params['type_out'] ?? 'alltheme';
			$area = $params['area'] ?? null;
			$toWord = $params['to_word'] ?? false;

			$data = [];
			$dateFrom = null;
			$dateTo = null;

			// CHK
			if ($typeOut === 'chk') {
				if (isset($files['im'])) {
					$imData = $this->parser->parse($files['im'], 'Детализация');
					$imResult = $this->calculator->calculateCHK($imData, $typeOut, $area);
					$data['types'] = $imResult['types'];

					$data['municipalities'] = $imResult['municipalities'];
					$data['ministries'] = $imResult['ministries'];
					$data['organizations'] = $imResult['organizations'];

					$data['sum'] = $imResult['sum'];

					$data['params'] = [
						'type_out'  => $typeOut,
					];
					$data['debug'] = $this->dump($imResult);
				}
			}

			// IM
			if (isset($files['im']) && $typeOut != 'chk') {
				$imData = $this->parser->parse($files['im'], 'Детализация');
				$imResult = $this->calculator->calculateIM($imData, $typeOut, $area);
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
				$posData = $this->parser->parse($files['pos'], 'Sheet0');
				$data['pos'] = $this->calculator->calculatePOS($posData, $typeOut, $area, $hasBoth);
			}
			if (isset($files['pos']) && $typeOut === 'org') {
				$posData = $this->parser->parse($files['pos'], 'Sheet0');
				$data['org'] = $this->getOrgFromPOS($posData);
			}

			// IMP
			if (isset($files['imp'])) {
				$impData = $this->parser->parse($files['imp'], 'Инциденты');
				$data['imp'] = $this->calculator->calculateIMP($impData, $area);
			}

			// Сводка
			if (isset($data['im']) && isset($data['pos'])) {
				$data['combine'] = $this->calculator->combine($data);
			}

			// WORD
			if ($toWord) {
				$wordFile = $this->wordGenerator->generate($data, $typeOut, $area);
				$data['word_file'] = $wordFile;
			}

			return $data;
		} finally {
			ini_set('memory_limit', $oldMemoryLimit);
			set_time_limit($oldTimeLimit);
		}
	}

	/**
	 * Обработка организаций из POS-файла.
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