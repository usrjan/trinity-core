<?php

/**
 * СЕРВИС ИМПОРТА ИЗ EXCEL
 * 
 * Читает Excel-файл и создаёт нейроны с текстами.
 * 
 * Поддерживает три типа листов:
 * 1. TREE  — классификатор (нейроны type='tree')
 * 2. ITEM  — данные с привязкой к классификатору (нейроны type='item')
 * 3. Любой другой (MON, CITY...) — дата-лист, создаёт нейроны и синапсы по схемам BUILD и WORK
 */

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImportService
{
    private TextRepository $textRepo;
    private NeuronRepository $neuronRepo;
    private SynapseRepository $synapseRepo;

    /** @var array Кэш slug → id */
    private array $slugCache = [];

    public function __construct(
        TextRepository $textRepo,
        NeuronRepository $neuronRepo,
        SynapseRepository $synapseRepo
    ) {
        $this->textRepo = $textRepo;
        $this->neuronRepo = $neuronRepo;
        $this->synapseRepo = $synapseRepo;
    }

    /**
     * Главный метод импорта.
     */
    public function import(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $fileName = basename($filePath);
        $fileDate = $this->extractDateFromFileName($fileName);
        $created = 0;
        $errors = [];

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            $rows = $sheet->toArray();

            if (empty($rows)) continue;

            $headers = array_shift($rows);
            $headers = array_map('trim', $headers);
            $type = strtolower(trim($sheetName));

            foreach ($rows as $rowIndex => $row) {
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
                } catch (\Exception $e) {
                    $errors[] = "Лист '{$sheetName}', строка " . ($rowIndex + 2) . ": " . $e->getMessage();
                }
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    // ============================================
    // TREE
    // ============================================
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
            // Проверяем что родитель совпадает
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

        // Ищем или создаём нейрон для текста работы
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

        // Проверка дубликата синапса
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
    private function findOrCreateTreeTexts(array $data): ?int
    {
        foreach (['ru', 'en'] as $lang) {
            if (!empty($data["name_{$lang}"])) {
                return $this->textRepo->findOrCreate($lang, $data["name_{$lang}"]);
            }
        }

        // Создаём мультиязычный текст
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
    // ГЕОМЕТРИЯ (без изменений)
    // ============================================
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
}