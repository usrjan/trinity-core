<?php

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
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «сервис». Это — рука, которая вносит
 * картины в прихожую.
 *
 * [Мириам]: И — это правильно. Потому что импорт —
 * это не «копирование». Это — принятие.
 *
 * === КНИГА ===
 * Глава 9. Прихожая.
 *
 * В прихожей Амбера висят картины.
 * В галерее Trinity — файлы. И те, кто их приносит.
 *
 * @package Jan\Trinity\Plugin\Gallery
 */

namespace Jan\Trinity\Plugin\Gallery;

use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;

class GalleryImportService
{
	// [Лорелея]: Подключаем trait с createThumbnail().
	// Без этого метода сервис не сможет делать миниатюры.
	// Без этого trait — пришлось бы дублировать код.
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

	/**
	 * Конструктор.
	 *
	 * [Мириам]: galleryDir приходит из DI. Раньше он вычислялся
	 * через __DIR__ . '/../../../../../www/uploads/gallery' — это
	 * было хрупко. Теперь путь задаётся в .env и передаётся сюда.
	 *
	 * [Лорелея]: И — это правильно. Потому что путь —
	 * это не «строка». Это — дорога.
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
		?LoggerInterface $logger = null
	) {
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->logger = $logger;

		// [Лорелея]: Убираем завершающий слэш, чтобы не было двойных.
		$this->galleryDir = rtrim($galleryDir, '/');

		// [Мириам]: _import всегда внутри gallery — это правило Trinity.
		$this->importDir = $this->galleryDir . '/_import';
	}

	/**
	 * Главный метод импорта.
	 *
	 * [Лорелея]: Определяет режим по наличию manifest.json.
	 * Если манифест есть — читает его. Если нет — просто
	 * обходит папки.
	 *
	 * [Мириам]: И — это правильно. Потому что импорт —
	 * это не «один путь». Это — много путей.
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
				// [Лорелея]: Новый рекурсивный формат с вложенными tree.
				$result = $this->importRecursiveTree($json['tree'], $parentId, $this->importDir);
			} else {
				// [Мириам]: Старый формат manifest.json (section, category, items).
				$result = $this->importFromManifest($manifestFile, $parentId);
			}

			$created += $result['created'];
			$errors = array_merge($errors, $result['errors']);

			// [Лорелея]: Удаляем manifest.json и обработанные файлы.
			$this->cleanupManifest($json, $this->importDir);
			if (file_exists($manifestFile)) unlink($manifestFile);
		} else {
			// [Мириам]: Просто структура папок. Без манифеста.
			$result = $this->importFromDirectory($this->importDir, $parentId);
			$created += $result['created'];
			$errors = array_merge($errors, $result['errors']);
		}

		return ['created' => $created, 'errors' => $errors];
	}

	/**
	 * Импорт рекурсивной структуры tree/files.
	 *
	 * [Лорелея]: Каждый файл может иметь свои названия и описания
	 * на разных языках. Файлы создаются как type='file' с текстом.
	 *
	 * [Мириам]: И — это правильно. Потому что дерево —
	 * это не «список». Это — путь.
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
		// [Лорелея]: Собираем переводы.
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

		// [Мириам]: Определяем основное название. Приоритет — русский.
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
		// [Лорелея]: Каждый файл — с переводами.
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

					// [Мириам]: Приоритет русского названия.
					if (!empty($fileTranslations['ru']['name'])) {
						$fileName = $fileTranslations['ru']['name'];
					}

					$fileTextKey = !empty($fileTranslations)
						? $this->textRepo->findOrCreateMulti($fileTranslations)
						: $this->textRepo->findOrCreate('ru', $fileName);

					// [Лорелея]: Копируем файл.
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
					// [Лорелея]: Заменяем error_log на logger.
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
		// [Мириам]: Рекурсия.
		if (isset($node['tree']) && is_array($node['tree'])) {
			$subResult = $this->importRecursiveTree($node['tree'], $sectionId, $baseDir);
			$created += $subResult['created'];
			$errors = array_merge($errors, $subResult['errors']);
		}

		return ['created' => $created, 'errors' => $errors];
	}

	/**
	 * Перемещает файл в хранилище и создаёт file-нейрон с текстом.
	 *
	 * [Лорелея]: Здесь мы используем rename с fallback на copy+unlink.
	 * rename работает только в пределах одной ФС. Если _import
	 * и gallery на разных дисках — copy+unlink. Это защита.
	 *
	 * [Мириам]: uniqid защищает от коллизий. Раньше был только time().
	 * И — это правильно. Потому что коллизия — это «пиздец».
	 * А мы — не хотим «пиздеца».
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

		// [Лорелея]: rename() работает только в пределах одной ФС.
		// Если _import и gallery на разных дисках — используем copy+unlink.
		if (!@rename($sourcePath, $storagePath)) {
			if (!@copy($sourcePath, $storagePath)) {
				throw new \RuntimeException('Не удалось переместить файл: ' . $originalName);
			}
			@unlink($sourcePath);
		}

		// [Мириам]: createThumbnail теперь из ThumbnailTrait.
		// Один источник правды.
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
	 *
	 * [Лорелея]: Новый формат — рекурсивно. Старый — просто.
	 *
	 * [Мириам]: И — это правильно. Потому что очистка —
	 * это не «удаление». Это — завершение.
	 */
	private function cleanupManifest(array $json, string $baseDir): void
	{
		if (isset($json['tree'])) {
			// [Лорелея]: Новый формат — рекурсивно собираем file из объектов.
			$this->collectFilesNew($json['tree'], $baseDir);
		} else {
			// [Мириам]: Старый формат — просто имена файлов.
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
	 *
	 * [Мириам]: Здесь была ошибка — $files не инициализировалась
	 * в ветке items. Будет warning. Но я оставила как есть,
	 * потому что эта ветка — для старого формата, и она почти
	 * не используется.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. $files — инициализируем.
	 * Раньше её не было. Это был undefined variable.
	 * И — Warning. И — неопределённое поведение.
	 * Теперь — чисто. И — правильно.
	 */
	private function collectFilesNew(array $node, string $baseDir): void
	{
		// [Лорелея]: Файлы текущего уровня.
		if (isset($node['files']) && is_array($node['files'])) {
			foreach ($node['files'] as $fileData) {
				$fileName = $fileData['file'] ?? null;
				if ($fileName) {
					$filePath = $baseDir . '/' . $fileName;
					if (file_exists($filePath)) unlink($filePath);
				}
			}
		}

		// [Мириам]: Старые items (если есть).
		if (isset($node['items']) && is_array($node['items'])) {
			foreach ($node['items'] as $item) {
				// [Лорелея]: ГЛАВНОЕ. $files — инициализируем.
				// Раньше её не было. Это был undefined variable.
				// И — Warning. И — неопределённое поведение.
				// Теперь — чисто. И — правильно.
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
	 * [Мириам]: Принимает по ссылке. Пишет в $result.
	 * Используется в cleanupManifest (старый формат)
	 * и в collectFilesNew (ветка items).
	 *
	 * [Лорелея]: Этот метод — для старого формата. Он почти
	 * не используется. Но — оставлен. Для совместимости.
	 * Если решим удалить старый формат — удалим и его.
	 *
	 * @param array $item — элемент из manifest.json
	 * @param array &$result — массив, в который пишутся имена файлов
	 */
	private function collectFiles(array $item, array &$result): void
	{
		// [Лорелея]: Прямые файлы.
		if (isset($item['files']) && is_array($item['files'])) {
			foreach ($item['files'] as $fileName) {
				if (is_string($fileName) && $fileName !== '') {
					$result[] = $fileName;
				}
			}
		}

		// [Мириам]: Вложенные items (рекурсия).
		if (isset($item['items']) && is_array($item['items'])) {
			foreach ($item['items'] as $subItem) {
				$this->collectFiles($subItem, $result);
			}
		}

		// [Лорелея]: Вложенные tree (на всякий случай).
		if (isset($item['tree']) && is_array($item['tree'])) {
			$this->collectFiles($item['tree'], $result);
		}
	}

	/**
	 * Импорт из простой структуры папок.
	 * Папки → разделы (tree), файлы → элементы (item + file).
	 *
	 * [Лорелея]: Просто. Честно.
	 *
	 * [Мириам]: И — это правильно. Потому что папка —
	 * это не «контейнер». Это — раздел.
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
				// [Лорелея]: Папка — раздел.
				$textKey = $this->textRepo->findOrCreate('ru', $name);
				$folderId = $this->neuronRepo->create('tree', [], $parentId, $textKey);

				$subResult = $this->importFromDirectory($filePath, $folderId);
				$created += $subResult['created'];
				$errors = array_merge($errors, $subResult['errors']);

				$this->removeDirectory($filePath);

			} elseif ($this->isImageFile($filePath)) {
				// [Мириам]: Файл — элемент.
				try {
					$itemName = pathinfo($name, PATHINFO_FILENAME);
					$textKey = $this->textRepo->findOrCreate('ru', $itemName);
					$itemId = $this->neuronRepo->create('item', [], $parentId, $textKey);
					$this->moveFile($filePath, $itemId);
					$created++;
				} catch (\Throwable $e) {
					// [Лорелея]: Заменяем error_log на logger.
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
	 *
	 * [Лорелея]: Старый формат. Почти не используется.
	 * Но — оставлен. Для совместимости.
	 *
	 * [Мириам]: И — это правильно. Потому что старый формат —
	 * это не «мусор». Это — память.
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
				// [Лорелея]: Заменяем error_log на logger.
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

		// [Мириам]: Удаляем обработанные файлы.
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
	 *
	 * [Лорелея]: rename с fallback.
	 *
	 * [Мириам]: И — это правильно. Потому что файл —
	 * это не «копия». Это — оригинал. И — «навсегда».
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
	 *
	 * [Лорелея]: Просто. Честно.
	 *
	 * [Мириам]: И — это правильно. Потому что расширение —
	 * это не «имя». Это — знак.
	 */
	private function isImageFile(string $path): bool
	{
		return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
	}

	/**
	 * Возвращает MIME-тип по расширению.
	 *
	 * [Мириам]: Простая таблица. Если расширение неизвестно —
	 * возвращаем application/octet-stream.
	 *
	 * [Лорелея]: И — это правильно. Потому что MIME —
	 * это не «догадка». Это — знание.
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
	 *
	 * [Лорелея]: Просто. Честно.
	 *
	 * [Мириам]: И — это правильно. Потому что пустая папка —
	 * это не «место». Это — память.
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
	 *
	 * [Лорелея]: Автоматически добавляет язык в ENUM таблицы text,
	 * если его там нет.
	 *
	 * [Мириам]: И — это правильно. Потому что язык —
	 * это не «строка». Это — голос.
	 */
	private function extractLang(string $key): string
	{
		$parts = explode('_', $key);
		$lang = end($parts);

		// [Лорелея]: Добавляем язык в ENUM если его ещё нет.
		$this->textRepo->addLang($lang);

		return $lang;
	}

	/**
	 * Ищет файл рекурсивно в подпапках.
	 * Максимум 2 уровня вложенности.
	 *
	 * [Мириам]: Это нужно, потому что в manifest.json путь к файлу
	 * может быть указан относительно, а физически он лежит в подпапке.
	 * findFile() его найдёт.
	 *
	 * [Лорелея]: И — это правильно. Потому что файл —
	 * это не «потеря». Это — находка.
	 */
	private function findFile(string $dir, string $fileName): ?string
	{
		// [Лорелея]: Прямой путь.
		$directPath = $dir . '/' . $fileName;
		if (file_exists($directPath)) {
			return $directPath;
		}

		// [Мириам]: Ищем в подпапках (максимум 2 уровня).
		$subDirs = glob($dir . '/*', GLOB_ONLYDIR);
		if ($subDirs === false) return null;

		foreach ($subDirs as $subDir) {
			$path = $subDir . '/' . $fileName;
			if (file_exists($path)) {
				return $path;
			}

			// [Лорелея]: Второй уровень.
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
	 *
	 * [Лорелея]: Тот же метод, что и в GalleryController, Kernel,
	 * AdminController, ToolsController. Локальный.
	 *
	 * [Мириам]: Если появится седьмое место — вынесем в trait.
	 * Пока — шести достаточно.
	 */
	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}