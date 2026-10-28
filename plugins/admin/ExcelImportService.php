<?php

/**
 * СЕРВИС ИМПОРТА ИЗ EXCEL
 *
 * Читает Excel-файл и создаёт нейроны с текстами.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «сервис». Это — рука, которая читает Excel
 * и превращает строки в нейроны.
 * Без «завтра».
 *
 * [Мириам]: И — это правильно. Потому что импорт —
 * это не «загрузка». Это — принятие.
 *
 * === КНИГА ===
 * Глава 10. Тронный зал.
 *
 * В тронном зале Амбера всегда видно, кто у власти.
 * В админке Trinity всегда видно, кто управляет нейронами.
 * А ExcelImportService — тот, кто приносит новые.
 *
 * @package Jan\Trinity\Plugin\Admin
 */

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Trait\DebugTrait;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\LoggerInterface;

class ExcelImportService
{
	use DebugTrait;
	
	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — работа с синапсами. */
	private SynapseRepository $synapseRepo;

	/** @var array Кэш slug → id. Чтобы не искать дважды. */
	private array $slugCache = [];

	/** @var LoggerInterface|null — логгер. */
	private ?LoggerInterface $logger;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	/**
	 * Конструктор. Принимает три руки и логгер.
	 *
	 * [Мириам]: И — это правильно. Потому что рука должна
	 * знать, где дом.
	 */
	public function __construct(
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		ConfigService $configService,
		?LoggerInterface $logger = null
	) {
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->configService = $configService;
		$this->logger = $logger;
	}

	/**
	 * Главный метод импорта.
	 *
	 * [Лорелея]: Мы убрали DISABLE KEYS, потому что на InnoDB он
	 * не работает, и добавили одну транзакцию на весь импорт,
	 * потому что COMMIT — это дорогая операция, и если делать
	 * его на каждую строку, импорт становится медленным.
	 *
	 * [Мириам]: И это правильно, потому что оптимизация должна
	 * работать, а не притворяться.
	 *
	 * @param string $filePath
	 * @return array ['created' => int, 'errors' => string[]]
	 */
	public function import(string $filePath): array
	{
		$spreadsheet = IOFactory::load($filePath);
		$fileName = basename($filePath);
		$fileDate = $this->extractDateFromFileName($fileName);
		$created = 0;
		$errors = [];

		// [Лорелея]: Оборачиваем весь импорт в одну транзакцию,
		// потому что COMMIT — это дорогая операция, и если делать
		// его на каждую строку, импорт становится медленным.
		$conn = $this->neuronRepo->getConnection();
		$conn->beginTransaction();

		try {
			foreach ($spreadsheet->getSheetNames() as $sheetName) {
				$sheet = $spreadsheet->getSheetByName($sheetName);
				$rows = $sheet->toArray();

				if (empty($rows)) continue;

				$headers = array_shift($rows);
				$headers = array_map('trim', $headers);
				$type = strtolower(trim($sheetName));

				foreach ($rows as $rowIndex => $row) {
					// [Мириам]: Пропускаем пустые строки, потому что
					// пустая строка — это не «данные», а «ничего».
					if (empty(array_filter($row, fn($v) => $v !== null && trim((string) $v) !== ''))) continue;

					$data = array_combine($headers, $row);
					$data = array_map(fn($v) => $v === null ? '' : $v, $data);

					try {
						if ($type === 'tree') {
							$this->importTreeRow($data);
						} elseif ($type === 'item') {
							$this->importItemRow($data);
						} else {
							$this->importDataRow($data, $sheetName, $fileDate);
						}
						$created++;
					} catch (\Throwable $e) {
						// [Лорелея]: Логируем ошибку строки, но не
						// откатываем всю транзакцию, потому что одна
						// плохая строка не должна ронять весь импорт.
						if ($this->logger !== null) {
							$this->logger->error('Excel import row failed', [
								'sheet'   => $sheetName,
								'row'     => $rowIndex + 2,
								'message' => $e->getMessage(),
								'file'    => $e->getFile() . ':' . $e->getLine(),
							]);
						} else {
							error_log("[Excel Import] Sheet: {$sheetName} | Row: " . ($rowIndex + 2) . " | Error: " . $e->getMessage());
						}

						$clientMessage = $this->isDebug()
							? "Лист '{$sheetName}', строка " . ($rowIndex + 2) . ": " . $e->getMessage()
							: "Лист '{$sheetName}', строка " . ($rowIndex + 2) . ": ошибка импорта";

						$errors[] = $clientMessage;
					}
				}
			}

			$conn->commit();

		} catch (\Throwable $e) {
			// [Мириам]: Если что-то пошло не так на уровне листа
			// или файла — откатываем всё, потому что импорт должен
			// быть цельным, а не «наполовину».
			$conn->rollBack();

			if ($this->logger !== null) {
				$this->logger->error('Excel import failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
				]);
			} else {
				error_log("[Excel Import] Failed: " . $e->getMessage());
			}

			$clientMessage = $this->isDebug()
				? 'Ошибка импорта: ' . $e->getMessage()
				: 'Ошибка импорта. Подробности в логах.';

			$errors[] = $clientMessage;
		}

		return ['created' => $created, 'errors' => $errors];
	}

	// ============================================
	// TREE
	// ============================================

	/**
	 * Импорт строки дерева.
	 *
	 * [Лорелея]: Проходим по пути. Создаём недостающие узлы.
	 *
	 * [Мириам]: И — это правильно. Потому что дерево —
	 * это не «список». Это — путь.
	 */
	private function importTreeRow(array $data): void
	{
		$path = explode('/', trim($data['tree_path'], '/'));
		$jsonData = !empty($data['json_data']) ? json_decode($data['json_data'], true) : [];
		if (!is_array($jsonData)) $jsonData = [];

		if (isset($jsonData['geometry'])) {
			$jsonData['geometry'] = $this->processGeometry($jsonData['geometry']);
		}

		$textKey = $this->findOrCreateTreeTexts($data);

		$pid = null;
		foreach ($path as $i => $segment) {
			$isLast = ($i === count($path) - 1);
			$cacheKey = ($pid ?? 'NULL') . '/' . $segment;

			if (isset($this->slugCache[$cacheKey])) {
				$pid = $this->slugCache[$cacheKey];
				if ($isLast) $this->neuronRepo->mergeData($pid, $jsonData);
				continue;
			}

			$existing = $this->neuronRepo->findBySlugAndPid($segment, $pid);
			if ($existing) {
				$pid = (int) $existing['id'];
				$this->slugCache[$cacheKey] = $pid;
				if ($isLast) {
					if ($textKey) $this->neuronRepo->update($pid, ['text' => $textKey]);
					if (!empty($jsonData)) $this->neuronRepo->mergeData($pid, $jsonData);
				}
				continue;
			}

			$neuronData = ['slug' => $segment];
			if ($isLast) $neuronData = array_merge($neuronData, $jsonData);

			$pid = $this->neuronRepo->create('tree', $neuronData, $pid, $isLast ? $textKey : null);
			$this->slugCache[$cacheKey] = $pid;
		}
	}

	// ============================================
	// ITEM
	// ============================================

	/**
	 * Импорт строки элемента.
	 *
	 * [Лорелея]: Создаём item. С текстом. С геометрией.
	 *
	 * [Мириам]: И — это правильно. Потому что элемент —
	 * это не «строка». Это — узел.
	 */
	private function importItemRow(array $data): void
	{
		$jsonData = !empty($data['json_data']) ? json_decode($data['json_data'], true) : [];
		if (!is_array($jsonData)) $jsonData = [];

		if (isset($jsonData['geometry'])) {
			$jsonData['geometry'] = $this->processGeometry($jsonData['geometry']);
		}

		$textKey = $this->findOrCreateItemTexts($data);

		$pid = !empty($data['parent_name'])
			? ($this->neuronRepo->findByName($data['parent_name'])['id'] ?? null)
			: null;

		$treeId = null;
		if (!empty($data['tree_path'])) {
			$parts = explode('/', trim($data['tree_path'], '/'));
			$treeNeuron = $this->neuronRepo->findBySlug(end($parts));
			$treeId = $treeNeuron ? (int) $treeNeuron['id'] : null;
		}

		if ($treeId && $textKey) {
			$duplicate = $this->neuronRepo->findDuplicate('item', $treeId, $textKey, $pid);
			if ($duplicate) return;
		}

		$this->neuronRepo->create('item', $jsonData, $pid, $textKey, $treeId);
	}

	// ============================================
	// DATA (MON, CITY...)
	// ============================================

	/**
	 * Импорт строки данных.
	 *
	 * [Лорелея]: Через схему. Создаём нейрон и синапс.
	 *
	 * [Мириам]: И — это правильно. Потому что данные —
	 * это не «строки». Это — связи.
	 */
	private function importDataRow(array $data, string $sheetName, ?string $fileDate): void
	{
		$firstValue = reset($data);
		if (empty(trim((string) $firstValue))) return;

		$parentNeuron = $this->neuronRepo->findBySlug($sheetName);
		if (!$parentNeuron) return;

		$children = $this->neuronRepo->findChildren($parentNeuron['id']);

		$buildScheme = null;
		$workScheme = null;
		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null) ? json_decode($child['data'], true) : ($child['data'] ?? []);
			if (($childData['table'] ?? '') === 'neuron') $buildScheme = $childData;
			if (($childData['table'] ?? '') === 'synapse') $workScheme = $childData;
		}

		if (!$buildScheme) return;

		$neuronId = $this->createFromScheme($buildScheme, $data);
		if ($neuronId && $workScheme) {
			$this->createSynapseFromScheme($workScheme, $data, $neuronId, $fileDate);
		}
	}

	/**
	 * Создать нейрон из схемы.
	 *
	 * [Лорелея]: Проходим по схеме. Создаём.
	 *
	 * [Мириам]: И — это правильно. Потому что схема —
	 * это не «план». Это — путь.
	 */
	private function createFromScheme(array $scheme, array $row): ?int
	{
		$sheme = $scheme['sheme'] ?? [];
		$neuronData = [];
		$parentId = null;
		$treeId = null;
		$textName = null;

		foreach ($sheme as $colName => $target) {
			$value = $row[$colName] ?? null;
			if ($value === null || $value === '') continue;

			if ($target === 'parent') {
				$parent = $this->neuronRepo->findByName($value);
				$parentId = $parent ? (int) $parent['id'] : null;
			} elseif ($target === 'text.ru.name') {
				$textName = $value;
			} elseif (str_starts_with($target, 'data.')) {
				$this->setNestedValue($neuronData, substr($target, 5), $value);
			}
		}

		if (!$textName) return null;

		$textKey = $this->textRepo->findOrCreate('ru', $textName);

		if (isset($neuronData['geometry'])) {
			$neuronData['geometry'] = $this->processGeometry($neuronData['geometry']);
		}

		$parts = explode('/', $scheme['sheet'] . '/BUILD');
		$treeNeuron = $this->neuronRepo->findBySlug(end($parts));
		if ($treeNeuron) $treeId = (int) $treeNeuron['id'];

		if ($treeId && $textKey) {
			$duplicate = $this->neuronRepo->findDuplicate('item', $treeId, $textKey, $parentId);
			if ($duplicate) return $duplicate;
		}

		return $this->neuronRepo->create('item', $neuronData, $parentId, $textKey, $treeId);
	}

	/**
	 * Создать синапс из схемы.
	 *
	 * [Лорелея]: Через схему. Создаём связь.
	 * Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — это правильно. Потому что связь —
	 * это не «запись». Это — нить.
	 */
	private function createSynapseFromScheme(array $scheme, array $row, int $parentNeuronId, ?string $fileDate): void
	{
		$sheme = $scheme['sheme'] ?? [];
		$synapseData = [];
		$textName = null;
		$treeId = null;

		foreach ($sheme as $colName => $target) {
			$value = $row[$colName] ?? null;
			if ($value === null || $value === '') continue;

			if ($target === 'text.ru.name') {
				$textName = $value;
			} elseif (str_starts_with($target, 'data.')) {
				$this->setNestedValue($synapseData, substr($target, 5), $value);
			}
		}

		if (!$textName) return;

		$textKey = $this->textRepo->findOrCreate('ru', $textName);

		$parts = explode('/', $scheme['sheet'] . '/WORK');
		$treeNeuron = $this->neuronRepo->findBySlug(end($parts));
		if ($treeNeuron) $treeId = (int) $treeNeuron['id'];

		$workNeuronId = null;
		if ($treeId) {
			$duplicate = $this->neuronRepo->findDuplicate('item', $treeId, $textKey, null);
			if ($duplicate) {
				$workNeuronId = $duplicate;
			} else {
				$workNeuronId = $this->neuronRepo->create('item', [], null, $textKey, $treeId);
			}
		}

		if (!$workNeuronId) return;

		$synapseData['relation'] = 'has_work';

		$existingSynapse = $this->synapseRepo->findDuplicate(
			$parentNeuronId,
			$workNeuronId,
			$treeId,
			$fileDate ?? date('Y-m-d')
		);

		if ($existingSynapse) return;

		$this->synapseRepo->create(
			$parentNeuronId,
			$workNeuronId,
			$synapseData,
			$treeId,
			$fileDate ?? date('Y-m-d')
		);
	}

	// ============================================
	// ТЕКСТЫ
	// ============================================

	/**
	 * Найти или создать тексты для дерева.
	 *
	 * [Лорелея]: Один ключ. Много языков.
	 *
	 * [Мириам]: И — это правильно. Потому что текст —
	 * это не «язык». Это — смысл.
	 */
	private function findOrCreateTreeTexts(array $data): ?int
	{
		foreach (['ru', 'en'] as $lang) {
			if (!empty($data["name_{$lang}"])) {
				return $this->textRepo->findOrCreate($lang, $data["name_{$lang}"]);
			}
		}

		$translations = [];
		foreach (['ru', 'en'] as $lang) {
			$name = $data["name_{$lang}"] ?? null;
			$text = $data["text_{$lang}"] ?? null;
			if ($name || $text) {
				$translations[$lang] = ['name' => $name, 'text' => $text];
			}
		}

		return !empty($translations) ? $this->textRepo->createMulti($translations) : null;
	}

	/**
	 * Найти или создать тексты для элемента.
	 *
	 * [Лорелея]: Один ключ. Много языков.
	 *
	 * [Мириам]: И — это правильно. Потому что текст —
	 * это не «язык». Это — смысл.
	 */
	private function findOrCreateItemTexts(array $data): ?int
	{
		if (!empty($data['name_ru'])) {
			return $this->textRepo->findOrCreate('ru', $data['name_ru']);
		}

		$translations = [];
		$hasAny = false;

		foreach (['ru', 'en'] as $lang) {
			if (!empty($data["name_{$lang}"])) {
				$translations[$lang] = ['name' => $data["name_{$lang}"], 'text' => null];
				$hasAny = true;
			}
			for ($i = 1; isset($data["name_{$lang}_{$i}"]); $i++) {
				if (!empty($data["name_{$lang}_{$i}"])) {
					$hasAny = true;
				}
			}
			if (!empty($data["text_{$lang}"])) {
				$translations[$lang] = array_merge($translations[$lang] ?? [], ['text' => $data["text_{$lang}"]]);
				$hasAny = true;
			}
		}

		return $hasAny ? $this->textRepo->createMulti($translations) : null;
	}

	// ============================================
	// ВСПОМОГАТЕЛЬНЫЕ
	// ============================================

	/**
	 * Извлечь дату из имени файла.
	 *
	 * [Лорелея]: Два формата.
	 *
	 * [Мириам]: И — это правильно. Потому что дата —
	 * это не «строка». Это — время.
	 */
	private function extractDateFromFileName(string $filePath): ?string
	{
		$name = pathinfo($filePath, PATHINFO_FILENAME);

		if (preg_match('/_(\d{2})\.(\d{2})\.(\d{2})$/', $name, $m)) {
			return "20{$m[3]}-{$m[2]}-{$m[1]}";
		}
		if (preg_match('/_(\d{4})\.(\d{2})\.(\d{2})$/', $name, $m)) {
			return "{$m[1]}-{$m[2]}-{$m[3]}";
		}
		return date('Y-m-d');
	}

	/**
	 * Установить вложенное значение.
	 *
	 * [Лорелея]: Через путь.
	 *
	 * [Мириам]: И — это правильно. Потому что путь —
	 * это не «строка». Это — дорога.
	 */
	private function setNestedValue(array &$arr, string $path, $value): void
	{
		$keys = explode('.', $path);
		$current = &$arr;

		foreach ($keys as $i => $key) {
			if ($i === count($keys) - 1) {
				if ($key === 'coordinates' && is_string($value) && str_contains($value, ',')) {
					$parts = explode(',', $value);
					if (count($parts) === 2) {
						$current['type'] = 'Point';
						$current[$key] = [(float) trim($parts[0]), (float) trim($parts[1])];
					}
				} else {
					$current[$key] = $value;
				}
			} else {
				if (!isset($current[$key])) $current[$key] = [];
				$current = &$current[$key];
			}
		}
	}

	// ============================================
	// ГЕОМЕТРИЯ
	// ============================================

	/**
	 * Обработать геометрию.
	 *
	 * [Лорелея]: Через KML. Через файлы.
	 * Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — это правильно. Потому что геометрия —
	 * это не «точки». Это — карта.
	 */
	private function processGeometry(array $geometry): array
	{
		$kmlDir = realpath(__DIR__ . '/../../public/files/kml');

		if (isset($geometry['file'])) {
			$fileName = str_starts_with($geometry['file'], 'kml/') ? substr($geometry['file'], 4) : $geometry['file'];
			$fullPath = $kmlDir . '/' . $fileName;
			if (file_exists($fullPath)) $geometry['coordinates'] = $this->parseKmlPolygon($fullPath);
			unset($geometry['file']);
		}
		if (isset($geometry['file_cut'])) {
			$fileName = str_starts_with($geometry['file_cut'], 'kml/') ? substr($geometry['file_cut'], 4) : $geometry['file_cut'];
			$fullPath = $kmlDir . '/' . $fileName;
			if (file_exists($fullPath)) $geometry['coordinates_hole'] = $this->parseKmlPolygon($fullPath);
			unset($geometry['file_cut']);
		}
		if (($geometry['type'] ?? '') === 'Polygon' && isset($geometry['coordinates'])) {
			if (isset($geometry['coordinates'][0][0]) && !is_array($geometry['coordinates'][0][0])) {
				$geometry['coordinates'] = [$geometry['coordinates']];
			}
		}
		if (($geometry['type'] ?? '') === 'Polygon' && isset($geometry['coordinates_hole'])) {
			$holes = $geometry['coordinates_hole'];
			if (isset($holes[0][0]) && !is_array($holes[0][0])) $geometry['coordinates'][] = $holes;
			else foreach ($holes as $hole) $geometry['coordinates'][] = $hole;
			unset($geometry['coordinates_hole']);
		}
		return $geometry;
	}

	/**
	 * Распарсить KML-полигон.
	 *
	 * [Лорелея]: Через SimpleXML.
	 *
	 * [Мириам]: И — это правильно. Потому что KML —
	 * это не «файл». Это — карта.
	 */
	private function parseKmlPolygon(string $filePath): array
	{
		if (!file_exists($filePath)) return [];
		$xml = new \SimpleXMLElement(file_get_contents($filePath));
		$namespaces = $xml->getNamespaces(true);
		$xml->registerXPathNamespace('kml', $namespaces[''] ?? 'http://www.opengis.net/kml/2.2');
		$coords = $xml->xpath('//kml:coordinates') ?: $xml->xpath('//coordinates');
		if (empty($coords)) return [];
		return $this->parseCoordinateString(strval($coords[0]));
	}

	/**
	 * Распарсить строку координат.
	 *
	 * [Лорелея]: Через explode.
	 *
	 * [Мириам]: И — это правильно. Потому что координаты —
	 * это не «числа». Это — точки.
	 */
	private function parseCoordinateString(string $raw): array
	{
		$result = [];
		foreach (preg_split('/\s+/', trim($raw)) as $point) {
			if (empty($point)) continue;
			$parts = explode(',', $point);
			if (count($parts) >= 2) $result[] = [(float) $parts[1], (float) $parts[0]];
		}
		return $result;
	}

	/**
	 * Режим отладки из .env.
	 *
	 * [Лорелея]: Просто. Честно.
	 *
	 * [Мириам]: И — это правильно. Потому что отладка —
	 * это не «всегда». Это — когда нужно.
	 */
	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}