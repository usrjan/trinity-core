<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Tools;

class SocialReportWordGenerator
{
	/**
	 * Генерирует Word-файл.
	 *
	 * [Лорелея]: Здесь мы собираем отчёт в Word, и это правильно,
	 * потому что иногда документ нужен на бумаге.
	 *
	 * [Мириам]: И это правильно, потому что бумага помнит
	 * дольше, чем экран.
	 */
	public function generate(array $data, string $typeOut, ?string $area): string
	{
		$phpWord = new \PhpOffice\PhpWord\PhpWord();

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

		$header = $section->addHeader();
		$header->firstPage();
		$htable = $header->addTable('myTable');
		$htable->addRow();

		$logoPath = dirname(__DIR__, 2) . '/public/assets/img/logo.png';
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

		$uploadDir = __DIR__ . '/../../public/uploads';
		if (!is_dir($uploadDir)) {
			mkdir($uploadDir, 0775, true);
		}

		$fileName = $file_name . '.docx';
		$filePath = $uploadDir . '/' . $fileName;

		$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
		$writer->save($filePath);

		return '/uploads/' . $fileName;
	}
}