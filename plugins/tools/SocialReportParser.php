<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Tools;

use PhpOffice\PhpSpreadsheet\IOFactory;

class SocialReportParser
{
	/**
	 * Читает Excel-файл и возвращает данные с ключом по имени листа.
	 */
	public function parse(string $filePath, string $sheetName): array
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
}