<?php
declare(strict_types=1);

/**
 * СЕРВИС ИМПОРТА ИЗ ПАПКИ
 *
 * Читает файлы из папки _import внутри uploads/gallery
 * и создаёт нейроны с текстами.
 *
 * Поддерживает три режима:
 * 1. manifest.json с вложенным tree/files — новый формат
 * 2. manifest.json со section/category/items — старый формат
 * 3. Просто структура папок — папки → разделы, файлы → элементы
 *
 * @package Jan\Trinity\Plugin\Gallery
 */

namespace Jan\Trinity\Plugin\Gallery;

use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;

class GalleryImportService
{
	use ThumbnailTrait;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/**
	 * @var string Физический путь к папке загрузок галереи.
	 *             Берётся из .env (GALLERY_UPLOAD_DIR).
	 *             Без завершающего слэша.
	 */
	private string $galleryDir;

	/**
	 * @var string Путь к папке _import внутри галереи.
	 *             Сюда попадают файлы для импорта.
	 *             После импорта папка очищается.
	 */
	private string $importDir;

	/** @var LoggerInterface|null — логгер. */
	private ?LoggerInterface $logger;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	/**
	 * Конструктор.
	 *
	 * @param TextRepository $textRepo работа с текстами
	 * @param NeuronRepository $neuronRepo работа с нейронами
	 * @param string $galleryDir физический путь к uploads/gallery
	 * @param LoggerInterface|null $logger логгер
	 */
	public function __construct(
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		string $galleryDir,
		ConfigService $configService,
		?LoggerInterface $logger = null
	) {
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->configService = $configService;
		$this->logger = $logger;

		// [Лорелея]: Убираем завершающий слэш, чтобы не было двойных.
		$this->galleryDir = rtrim($galleryDir, '/');

		// [Мириам]: _import всегда внутри gallery — это правило Trinity.
		$this->importDir = $this->galleryDir . '/_import';
	}

	/**
	 * Главный метод импорта.
	 *
	 * @param int|null $parentId — id родительского нейрона (обычно корень галереи)
	 * @return array ['created' => int, 'errors' => string[]]
	 */
	public function import(?int $parentId = null): array
	{
		if (!is_dir($this->importDir)) {
			return ['created' => 0, 'errors' => ['Папка _import не существует']];
		}

		$created = 0;
		$errors = [];

		$manifestFile = $this->importDir . '/manifest.json';

		if (file_exists($manifestFile)) {
			$json = json_decode(file_get_contents($manifestFile), true);

			if (isset($json['tree'])) {
				$result = $this->importRecursiveTree($json['tree'], $parentId, $this->importDir);
			} else {
				$result = $this->importFromManifest($manifestFile, $parentId);
			}

			$created += $result['created'];
			$errors = array_merge($errors, $result['errors']);

			$this->cleanupManifest($json, $this->importDir);
			if (file_exists($manifestFile)) unlink($manifestFile);
		} else {
			$result = $this->importFromDirectory($this->importDir, $parentId);
			$created += $result['created'];
			$errors = array_merge($errors, $result['errors']);
		}

		return ['created' => $created, 'errors' => $errors];
	}

	/**
	 * Импорт рекурсивной структуры tree/files.
	 *
	 * @param array $node — узел дерева из manifest.json
	 * @param int|null $parentId — id родителя
	 * @param string $baseDir — базовая папка для поиска файлов
	 * @return array ['created' => int, 'errors' => string[]]
	 */
	private function importRecursiveTree(array $node, ?int $parentId, string $baseDir): array
	{
		$created = 0;
		$errors = [];

		// ============================================
		// СОЗДАЁМ РАЗДЕЛ (TREE)
		// ============================================
		$sectionTranslations = [];
		$sectionName = 'Без названия';

		foreach ($node as $key => $value) {
			if (empty($value) || !is_string($value)) continue;
			if (str_starts_with($key, 'name_')) {
				$lang = $this->extractLang($key);
				$sectionTranslations[$lang] = array_merge(
					$sectionTranslations[$lang] ?? [],
					['name' => $value]
				);
			}
		}

		if (!empty($sectionTranslations['ru']['name'])) {
			$sectionName = $sectionTranslations['ru']['name'];
		} else {
			foreach ($sectionTranslations as $trans) {
				if (!empty($trans['name'])) {
					$sectionName = $trans['name'];
					break;
				}
			}
		}

		$textKey = !empty($sectionTranslations)
			? $this->textRepo->findOrCreateMulti($sectionTranslations)
			: $this->textRepo->findOrCreate('ru', $sectionName);

		$existing = $this->neuronRepo->findByNameAndPid($sectionName, $parentId);
		if ($existing) {
			$sectionId = (int) $existing['id'];
		} else {
			$sectionId = $this->neuronRepo->create('tree', null, $parentId, $textKey);
			$created++;
		}

		// ============================================
		// ОБРАБАТЫВАЕМ ФАЙЛЫ (напрямую, без items)
		// ============================================
		if (isset($node['files']) && is_array($node['files'])) {
			foreach ($node['files'] as $fileData) {
				try {
					$fileTranslations = [];
					$fileName = 'Без названия';

					foreach ($fileData as $key => $value) {
						if (empty($value) || !is_string($value)) continue;
						if ($key === 'file') continue;

						if (str_starts_with($key, 'name_')) {
							$lang = $this->extractLang($key);
							$fileTranslations[$lang] = array_merge(
								$fileTranslations[$lang] ?? [],
								['name' => $value]
							);
							if ($fileName === 'Без названия') $fileName = $value;
						} elseif (str_starts_with($key, 'text_')) {
							$lang = $this->extractLang($key);
							$fileTranslations[$lang] = array_merge(
								$fileTranslations[$lang] ?? [],
								['text' => $value]
							);
						}
					}

					if (!empty($fileTranslations['ru']['name'])) {
						$fileName = $fileTranslations['ru']['name'];
					}

					$fileTextKey = !empty($fileTranslations)
						? $this->textRepo->findOrCreateMulti($fileTranslations)
						: $this->textRepo->findOrCreate('ru', $fileName);

					$imageFile = $fileData['file'] ?? null;
					if ($imageFile) {
						$filePath = $baseDir . '/' . $imageFile;
						if (file_exists($filePath)) {
							$this->moveFileWithText($filePath, $sectionId, $fileTextKey);
							$created++;
						} else {
							$found = $this->findFile($baseDir, $imageFile);
							if ($found) {
								$this->moveFileWithText($found, $sectionId, $fileTextKey);
								$created++;
							} else {
								$errors[] = 'Файл не найден: ' . $imageFile;
							}
						}
					}

				} catch (\Throwable $e) {
					if ($this->logger !== null) {
						$this->logger->error('gallery import: recursive tree file failed', [
							'file'      => $fileName ?? '?',
							'message'   => $e->getMessage(),
							'file_path' => $e->getFile() . ':' . $e->getLine(),
						]);
					}

					$clientMessage = $this->isDebug()
						? ($fileName ?? '?') . ': ' . $e->getMessage()
						: ($fileName ?? '?') . ': ошибка импорта';

					$errors[] = $clientMessage;
				}
			}
		}

		// ============================================
		// РЕКУРСИВНО ОБРАБАТЫВАЕМ ВЛОЖЕННЫЕ TREE
		// ============================================
		if (isset($node['tree']) && is_array($node['tree'])) {
			$subResult = $this->importRecursiveTree($node['tree'], $sectionId, $baseDir);
			$created += $subResult['created'];
			$errors = array_merge($errors, $subResult['errors']);
		}

		return ['created' => $created, 'errors' => $errors];
	}

	/**
	 * Перемещает файл в хранилище и создаёт file-нейрон с текстом.
	 */
	private function moveFileWithText(string $sourcePath, int $parentId, int $textKey): void
	{
		$originalName = basename($sourcePath);
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		$mimeType = $this->getMimeType($extension);
		$size = filesize($sourcePath);

		$datePath = date('Y/m/d');
		$storageDir = $this->galleryDir . '/' . $datePath;
		if (!is_dir($storageDir)) {
			mkdir($storageDir, 0775, true);
		}

		$unique = md5($originalName . time() . uniqid('', true));
		$storageName = $unique . '.' . $extension;
		$thumbName = $unique . '_thumb.' . $extension;
		$storagePath = $storageDir . '/' . $storageName;
		$thumbPath = $storageDir . '/' . $thumbName;

		if (!@rename($sourcePath, $storagePath)) {
			if (!@copy($sourcePath, $storagePath)) {
				throw new \RuntimeException('Не удалось переместить файл: ' . $originalName);
			}
			@unlink($sourcePath);
		}

		$imageInfo = $this->createThumbnail($storagePath, $thumbPath);

		$this->neuronRepo->create('file', [
			'original_name' => $originalName,
			'mime'          => $mimeType,
			'size'          => $size,
			'width'         => $imageInfo['width'] ?? 0,
			'height'        => $imageInfo['height'] ?? 0,
			'storage_path'  => $datePath . '/' . $storageName,
			'thumb_path'    => $datePath . '/' . $thumbName,
			'uploaded_at'   => date('Y-m-d H:i:s'),
		], $parentId, $textKey);
	}

	/**
	 * Очищает файлы после импорта manifest.json.
	 */
	private function cleanupManifest(array $json, string $baseDir): void
	{
		if (isset($json['tree'])) {
			$this->collectFilesNew($json['tree'], $baseDir);
		} else {
			$files = [];
			$this->collectFiles($json, $files);
			foreach ($files as $fileName) {
				$filePath = $baseDir . '/' . $fileName;
				if (file_exists($filePath)) unlink($filePath);
			}
		}
	}

	/**
	 * Рекурсивно удаляет файлы из нового формата tree/files.
	 */
	private function collectFilesNew(array $node, string $baseDir): void
	{
		if (isset($node['files']) && is_array($node['files'])) {
			foreach ($node['files'] as $fileData) {
				$fileName = $fileData['file'] ?? null;
				if ($fileName) {
					$filePath = $baseDir . '/' . $fileName;
					if (file_exists($filePath)) unlink($filePath);
				}
			}
		}

		if (isset($node['items']) && is_array($node['items'])) {
			foreach ($node['items'] as $item) {
				$files = [];

				$this->collectFiles($item, $files);

				foreach ($files as $fileName) {
					$filePath = $baseDir . '/' . $fileName;
					if (file_exists($filePath)) unlink($filePath);
				}
			}
		}

		// [Мириам]: Вложенные tree. Рекурсия.
		if (isset($node['tree']) && is_array($node['tree'])) {
			$this->collectFilesNew($node['tree'], $baseDir);
		}
	}

	/**
	 * Собирает имена файлов из старого формата items.
	 *
	 * @param array $item — элемент из manifest.json
	 * @param array &$result — массив, в который пишутся имена файлов
	 */
	private function collectFiles(array $item, array &$result): void
	{
		if (isset($item['files']) && is_array($item['files'])) {
			foreach ($item['files'] as $fileName) {
				if (is_string($fileName) && $fileName !== '') {
					$result[] = $fileName;
				}
			}
		}

		if (isset($item['items']) && is_array($item['items'])) {
			foreach ($item['items'] as $subItem) {
				$this->collectFiles($subItem, $result);
			}
		}

		if (isset($item['tree']) && is_array($item['tree'])) {
			$this->collectFiles($item['tree'], $result);
		}
	}

	/**
	 * Импорт из простой структуры папок.
	 * Папки → разделы (tree), файлы → элементы (item + file).
	 */
	private function importFromDirectory(string $dir, ?int $parentId): array
	{
		$created = 0;
		$errors = [];

		$files = glob($dir . '/*');
		if ($files === false) return ['created' => 0, 'errors' => []];

		foreach ($files as $filePath) {
			$name = basename($filePath);

			if (str_starts_with($name, '.')) continue;
			if ($name === 'manifest.json') continue;

			if (is_dir($filePath)) {
				$textKey = $this->textRepo->findOrCreate('ru', $name);
				$folderId = $this->neuronRepo->create('tree', [], $parentId, $textKey);

				$subResult = $this->importFromDirectory($filePath, $folderId);
				$created += $subResult['created'];
				$errors = array_merge($errors, $subResult['errors']);

				$this->removeDirectory($filePath);

			} elseif ($this->isImageFile($filePath)) {
				try {
					$itemName = pathinfo($name, PATHINFO_FILENAME);
					$textKey = $this->textRepo->findOrCreate('ru', $itemName);
					$itemId = $this->neuronRepo->create('item', [], $parentId, $textKey);
					$this->moveFile($filePath, $itemId);
					$created++;
				} catch (\Throwable $e) {
					if ($this->logger !== null) {
						$this->logger->error('gallery import: directory item failed', [
							'file'    => $name,
							'message' => $e->getMessage(),
							'file_path' => $e->getFile() . ':' . $e->getLine(),
						]);
					}

					$clientMessage = $this->isDebug()
						? $name . ': ' . $e->getMessage()
						: $name . ': ошибка импорта';

					$errors[] = $clientMessage;
				}
			}
		}

		return ['created' => $created, 'errors' => $errors];
	}

	/**
	 * Импорт из старого формата manifest.json (section, category, items).
	 */
	private function importFromManifest(string $manifestFile, ?int $galleryId): array
	{
		$created = 0;
		$errors = [];

		$json = json_decode(file_get_contents($manifestFile), true);
		if (!$json) {
			return ['created' => 0, 'errors' => ['manifest.json не является валидным JSON']];
		}

		$baseDir = dirname($manifestFile);
		$parentId = $galleryId;

		if (!empty($json['section'])) {
			$existing = $this->neuronRepo->findByNameAndPid($json['section'], $parentId);
			if ($existing) {
				$parentId = (int) $existing['id'];
			} else {
				$textKey = $this->textRepo->findOrCreate('ru', $json['section']);
				$parentId = $this->neuronRepo->create('tree', [], $parentId, $textKey);
			}
		}

		if (!empty($json['category'])) {
			$existing = $this->neuronRepo->findByNameAndPid($json['category'], $parentId);
			if ($existing) {
				$parentId = (int) $existing['id'];
			} else {
				$textKey = $this->textRepo->findOrCreate('ru', $json['category']);
				$parentId = $this->neuronRepo->create('tree', [], $parentId, $textKey);
			}
		}

		$items = $json['items'] ?? [];
		foreach ($items as $itemData) {
			try {
				$name = $itemData['name'] ?? 'Без названия';
				$description = $itemData['description'] ?? '';
				$tags = $itemData['tags'] ?? [];
				$year = $itemData['year'] ?? null;
				$meta = $itemData['meta'] ?? [];

				$textKey = $this->textRepo->findOrCreate('ru', $name, $description);

				$itemId = $this->neuronRepo->create('item', [
					'tags' => $tags,
					'year' => $year,
					'meta' => $meta,
				], $parentId, $textKey);

				foreach (($itemData['files'] ?? []) as $fileName) {
					$filePath = $baseDir . '/' . $fileName;
					if (file_exists($filePath)) {
						$this->moveFile($filePath, $itemId);
						$created++;
					} else {
						$errors[] = 'Файл не найден: ' . $fileName;
					}
				}
			} catch (\Throwable $e) {
				if ($this->logger !== null) {
					$this->logger->error('gallery import: manifest item failed', [
						'item_name' => $itemData['name'] ?? '?',
						'message'   => $e->getMessage(),
						'file'      => $e->getFile() . ':' . $e->getLine(),
					]);
				}

				$clientMessage = $this->isDebug()
					? ($itemData['name'] ?? '?') . ': ' . $e->getMessage()
					: ($itemData['name'] ?? '?') . ': ошибка импорта';

				$errors[] = $clientMessage;
			}
		}

		foreach ($items as $itemData) {
			foreach (($itemData['files'] ?? []) as $fileName) {
				$filePath = $baseDir . '/' . $fileName;
				if (file_exists($filePath)) unlink($filePath);
			}
		}
		if (file_exists($manifestFile)) unlink($manifestFile);

		return ['created' => $created, 'errors' => $errors];
	}

	/**
	 * Перемещает файл в хранилище (без текста, только file-нейрон).
	 */
	private function moveFile(string $sourcePath, int $parentId): void
	{
		$originalName = basename($sourcePath);
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		$mimeType = $this->getMimeType($extension);
		$size = filesize($sourcePath);

		$datePath = date('Y/m/d');
		$storageDir = $this->galleryDir . '/' . $datePath;
		if (!is_dir($storageDir)) {
			mkdir($storageDir, 0775, true);
		}

		$unique = md5($originalName . time() . uniqid('', true));
		$storageName = $unique . '.' . $extension;
		$thumbName = $unique . '_thumb.' . $extension;
		$storagePath = $storageDir . '/' . $storageName;
		$thumbPath = $storageDir . '/' . $thumbName;

		if (!@rename($sourcePath, $storagePath)) {
			if (!@copy($sourcePath, $storagePath)) {
				throw new \RuntimeException('Не удалось переместить файл: ' . $originalName);
			}
			@unlink($sourcePath);
		}

		$imageInfo = $this->createThumbnail($storagePath, $thumbPath);

		$this->neuronRepo->create('file', [
			'original_name' => $originalName,
			'mime'          => $mimeType,
			'size'          => $size,
			'width'         => $imageInfo['width'] ?? 0,
			'height'        => $imageInfo['height'] ?? 0,
			'storage_path'  => $datePath . '/' . $storageName,
			'thumb_path'    => $datePath . '/' . $thumbName,
			'uploaded_at'   => date('Y-m-d H:i:s'),
		], $parentId);
	}

	/**
	 * Проверяет, является ли файл изображением (по расширению).
	 */
	private function isImageFile(string $path): bool
	{
		return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
	}

	/**
	 * Возвращает MIME-тип по расширению.
	 */
	private function getMimeType(string $extension): string
	{
		return match ($extension) {
			'jpg', 'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'bmp'  => 'image/bmp',
			'svg'  => 'image/svg+xml',
			default => 'application/octet-stream',
		};
	}

	/**
	 * Удаляет пустую директорию.
	 */
	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) return;

		$files = glob($dir . '/*');
		if ($files === false || count($files) === 0) {
			rmdir($dir);
		}
	}

	/**
	 * Извлекает код языка из ключа поля.
	 * name_ru → ru, text_en → en, name_it → it.
	 */
	private function extractLang(string $key): string
	{
		$parts = explode('_', $key);
		$lang = end($parts);

		$this->textRepo->addLang($lang);

		return $lang;
	}

	/**
	 * Ищет файл рекурсивно в подпапках.
	 * Максимум 2 уровня вложенности.
	 */
	private function findFile(string $dir, string $fileName): ?string
	{
		$directPath = $dir . '/' . $fileName;
		if (file_exists($directPath)) {
			return $directPath;
		}

		$subDirs = glob($dir . '/*', GLOB_ONLYDIR);
		if ($subDirs === false) return null;

		foreach ($subDirs as $subDir) {
			$path = $subDir . '/' . $fileName;
			if (file_exists($path)) {
				return $path;
			}

			$subSubDirs = glob($subDir . '/*', GLOB_ONLYDIR);
			if ($subSubDirs === false) continue;

			foreach ($subSubDirs as $subSubDir) {
				$path = $subSubDir . '/' . $fileName;
				if (file_exists($path)) {
					return $path;
				}
			}
		}

		return null;
	}

	/**
	 * Режим отладки из .env.
	 */
	private function isDebug(): bool
	{
		return $this->configService->getEnv('APP_DEBUG', 'false') === 'true';
	}
}