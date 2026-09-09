<?php

namespace Jan\Trinity\Plugin\Gallery;

use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;

class GalleryImportService
{
	private TextRepository $textRepo;
	private NeuronRepository $neuronRepo;
	private string $importDir;
	private string $galleryDir;

	public function __construct(TextRepository $textRepo, NeuronRepository $neuronRepo)
	{
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->galleryDir = __DIR__ . '/../../public/uploads/gallery';
		$this->importDir = $this->galleryDir . '/_import';
	}

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
				// Новый рекурсивный формат с вложенными tree
				$result = $this->importRecursiveTree($json['tree'], $parentId, $this->importDir);
			} else {
				// Старый формат manifest.json (section, category, items)
				$result = $this->importFromManifest($manifestFile, $parentId);
			}
			
			$created += $result['created'];
			$errors = array_merge($errors, $result['errors']);
			
			// Удаляем manifest.json и обработанные файлы
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
	 * Каждый файл может иметь свои названия и описания на разных языках.
	 * Файлы создаются как type='file' с текстом.
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

		// Определяем основное название
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
					// Собираем переводы для файла
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

					// Приоритет русского названия
					if (!empty($fileTranslations['ru']['name'])) {
						$fileName = $fileTranslations['ru']['name'];
					}

					// Создаём текст для файла
					$fileTextKey = !empty($fileTranslations)
						? $this->textRepo->findOrCreateMulti($fileTranslations)
						: $this->textRepo->findOrCreate('ru', $fileName);

					// Копируем файл
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
				} catch (\Exception $e) {
					$errors[] = ($fileName ?? '?') . ': ' . $e->getMessage();
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

		$storageName = md5($originalName . time()) . '.' . $extension;
		$thumbName = md5($originalName . time()) . '_thumb.' . $extension;
		$storagePath = $storageDir . '/' . $storageName;
		$thumbPath = $storageDir . '/' . $thumbName;

		if (!rename($sourcePath, $storagePath)) {
			throw new \RuntimeException('Не удалось переместить файл: ' . $originalName);
		}

		$imageInfo = $this->createThumbnail($storagePath, $thumbPath);

		// Создаём file-нейрон с текстом
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
			// Новый формат — рекурсивно собираем file из объектов
			$this->collectFilesNew($json['tree'], $baseDir);
		} else {
			// Старый формат — просто имена файлов
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
		// Файлы текущего уровня
		if (isset($node['files']) && is_array($node['files'])) {
			foreach ($node['files'] as $fileData) {
				$fileName = $fileData['file'] ?? null;
				if ($fileName) {
					$filePath = $baseDir . '/' . $fileName;
					if (file_exists($filePath)) unlink($filePath);
				}
			}
		}
		
		// Старые items (если есть)
		if (isset($node['items']) && is_array($node['items'])) {
			foreach ($node['items'] as $item) {
				$this->collectFiles($item, $files);
				foreach ($files as $fileName) {
					$filePath = $baseDir . '/' . $fileName;
					if (file_exists($filePath)) unlink($filePath);
				}
				$files = [];
			}
		}
		
		// Вложенные tree
		if (isset($node['tree']) && is_array($node['tree'])) {
			$this->collectFilesNew($node['tree'], $baseDir);
		}
	}

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
				} catch (\Exception $e) {
					$errors[] = $name . ': ' . $e->getMessage();
				}
			}
		}

		return ['created' => $created, 'errors' => $errors];
	}

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
			} catch (\Exception $e) {
				$errors[] = ($itemData['name'] ?? '?') . ': ' . $e->getMessage();
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

		$storageName = md5($originalName . time()) . '.' . $extension;
		$thumbName = md5($originalName . time()) . '_thumb.' . $extension;
		$storagePath = $storageDir . '/' . $storageName;
		$thumbPath = $storageDir . '/' . $thumbName;

		if (!rename($sourcePath, $storagePath)) {
			throw new \RuntimeException('Не удалось переместить файл: ' . $originalName);
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

	private function createThumbnail(string $sourcePath, string $thumbPath, int $maxSize = 400): array
	{
		if (!function_exists('getimagesize')) {
			copy($sourcePath, $thumbPath);
			return [];
		}

		$info = @getimagesize($sourcePath);
		if (!$info) {
			copy($sourcePath, $thumbPath);
			return [];
		}

		$width = $info[0];
		$height = $info[1];
		$mime = $info['mime'];

		if ($width > $height) {
			$newWidth = $maxSize;
			$newHeight = (int) ($height * ($maxSize / $width));
		} else {
			$newHeight = $maxSize;
			$newWidth = (int) ($width * ($maxSize / $height));
		}

		$source = match ($mime) {
			'image/jpeg' => @imagecreatefromjpeg($sourcePath),
			'image/png'  => @imagecreatefrompng($sourcePath),
			'image/gif'  => @imagecreatefromgif($sourcePath),
			'image/webp' => @imagecreatefromwebp($sourcePath),
			default      => null,
		};

		if (!$source) {
			copy($sourcePath, $thumbPath);
			return ['width' => $width, 'height' => $height];
		}

		$thumb = imagecreatetruecolor($newWidth, $newHeight);

		if ($mime === 'image/png') {
			imagealphablending($thumb, false);
			imagesavealpha($thumb, true);
		}

		imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

		match ($mime) {
			'image/jpeg' => imagejpeg($thumb, $thumbPath, 85),
			'image/png'  => imagepng($thumb, $thumbPath, 8),
			'image/gif'  => imagegif($thumb, $thumbPath),
			'image/webp' => imagewebp($thumb, $thumbPath, 85),
			default      => copy($sourcePath, $thumbPath),
		};

		imagedestroy($source);
		imagedestroy($thumb);

		return ['width' => $width, 'height' => $height];
	}

	private function isImageFile(string $path): bool
	{
		return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
	}

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
	 * Автоматически добавляет язык в ENUM таблицы text, если его там нет.
	 * 
	 * @param string $key — ключ поля (name_ru, text_en, description_de)
	 * @return string — двухбуквенный код языка
	 */
	private function extractLang(string $key): string
	{
		$parts = explode('_', $key);
		$lang = end($parts);

		// Добавляем язык в ENUM если его ещё нет
		$this->textRepo->addLang($lang);

		return $lang;
	}

	/**
	 * Ищет файл рекурсивно в подпапках.
	 * 
	 * @param string $dir — базовая директория
	 * @param string $fileName — имя файла
	 * @return string|null — полный путь к файлу или null
	 */
	private function findFile(string $dir, string $fileName): ?string
	{
		// Прямой путь
		$directPath = $dir . '/' . $fileName;
		if (file_exists($directPath)) {
			return $directPath;
		}

		// Ищем в подпапках (максимум 2 уровня)
		$subDirs = glob($dir . '/*', GLOB_ONLYDIR);
		if ($subDirs === false) return null;

		foreach ($subDirs as $subDir) {
			$path = $subDir . '/' . $fileName;
			if (file_exists($path)) {
				return $path;
			}

			// Второй уровень
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
}