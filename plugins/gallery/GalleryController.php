<?php

/**
 * КОНТРОЛЛЕР ГАЛЕРЕИ
 * ====================
 * 
 * Управление галереей изображений с древовидной структурой.
 * 
 * Страничные методы (HTML):
 * - index()              — главная страница галереи
 * 
 * API-методы для навигации (JSON):
 * - section(id)          — содержимое раздела (папки и элементы)
 * - item(id)             — информация об элементе и его фотографии
 * 
 * API-методы для админов (JSON):
 * - upload()             — загрузка файлов в текущий раздел
 * - uploadToItem(id)     — добавление фото к существующему элементу
 * - updateItem(id)       — обновление названия, описания, метаданных
 * - deleteItem(id)       — удаление элемента со всеми фото
 * - deleteFile(id)       — удаление отдельного файла
 * - import()             — импорт из папки _import
 * 
 * Скачивание:
 * - download(id)         — скачать файл с оригинальным именем
 * 
 * Структура нейронов:
 * MEDIA → Галерея (tree, slug=gallery)
 *   ├── Раздел (tree)
 *   │   ├── Подраздел (tree)
 *   │   │   ├── Элемент (item) → text: название, data: {meta, tags, year}
 *   │   │   │   ├── Фото (file) → data: {original_name, storage_path, thumb_path, ...}
 *   │   │   │   └── Фото (file)
 * 
 * Зависимости:
 * - TextRepository    — работа с названиями и описаниями
 * - NeuronRepository  — работа с нейронами
 * - AuthMiddleware    — проверка прав доступа
 */

namespace Jan\Trinity\Plugin\Gallery;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class GalleryController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;

		// Инициализация middleware авторизации
		$this->initAuth($session);
	}

	// ============================================
	// ГЛАВНАЯ СТРАНИЦА ГАЛЕРЕИ
	// ============================================

	/**
	 * GET /gallery
	 * 
	 * Отображает главную страницу галереи с разделами верхнего уровня.
	 * Если структура галереи ещё не создана — инициализирует её.
	 * 
	 * @return Response
	 */
	public function index(): Response
	{
		// Проверяем и создаём структуру папок
		$this->ensureDirectories();

		// Ищем корень галереи (MEDIA → Галерея, slug=gallery)
		$galleryRoot = $this->neuronRepo->findBySlug('gallery');

		if (!$galleryRoot) {
			return $this->initGallery();
		}

		// Загружаем разделы верхнего уровня
		$sections = $this->neuronRepo->findChildren($galleryRoot['id']);

		// Формируем данные для шаблона
		$items = [];
		foreach ($sections as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);

			$items[] = [
				'id'           => $child['id'],
				'type'         => $child['type'],
				'name'         => $child['name'] ?? $childData['slug'] ?? 'Без названия',
				'has_children' => (int) ($child['child_count'] ?? 0) > 0,
			];
		}

		// Рендерим страницу
		$html = $this->twig->render('gallery.html.twig', [
			'sections' => $items,
			'rootId'   => $galleryRoot['id'],
		]);

		return new Response($html);
	}

	// ============================================
	// СОДЕРЖИМОЕ РАЗДЕЛА
	// ============================================

	/**
	 * GET /api/gallery/section/{id}
	 * 
	 * Возвращает HTML с содержимым раздела:
	 * - Папки (tree) — кликабельны, открывают вложенные разделы
	 * - Файлы (file) — показывают превьюшку, при клике — страница файла
	 * - Элементы (item) — для обратной совместимости
	 */
	public function section(int $id): JsonResponse
	{
		// Загружаем дочерние элементы
		$children = $this->neuronRepo->findChildren($id);

		$items = [];
		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);

			// ============================================
			// ОПРЕДЕЛЯЕМ НАЗВАНИЕ
			// ============================================
			$name = $child['name'] ?? $childData['slug'] ?? 'Без названия';

			if ($child['text'] && (!$child['name'] || $name === 'Без названия')) {
				$allTexts = $this->textRepo->findAllByKey($child['text']);
				
				foreach ($allTexts as $t) {
					if ($t['lang'] === 'ru' && !empty($t['name'])) {
						$name = $t['name'];
						break;
					}
				}
				
				if ($name === 'Без названия' || !$name) {
					foreach ($allTexts as $t) {
						if (!empty($t['name'])) {
							$name = $t['name'];
							break;
						}
					}
				}
			}

			// ============================================
			// ПРЕВЬЮШКА
			// ============================================
			$firstThumb = null;

			if ($child['type'] === 'file') {
				// Название из data
				$name = $childData['display_name'] ?? $childData['original_name'] ?? $name;
				
				// Превьюшка самого файла
				$thumbPath = $childData['thumb_path'] ?? null;
				if ($thumbPath) {
					$firstThumb = '/uploads/gallery/' . $thumbPath;
				}
				$isVideo = $childData['is_video'] ?? false;
				$isAudio = $childData['is_audio'] ?? false;

			} elseif ($child['type'] === 'tree') {
				// Приоритет: ручная обложка → первый файл среди детей
				$coverThumb = $childData['cover_thumb'] ?? null;
				if ($coverThumb) {
					$firstThumb = '/uploads/gallery/' . $coverThumb;
				} elseif ($child['child_count'] > 0) {
					$files = $this->neuronRepo->findChildren($child['id'], 'file');
					if (!empty($files)) {
						$fileData = is_string($files[0]['data'] ?? null)
							? json_decode($files[0]['data'], true)
							: ($files[0]['data'] ?? []);
						$thumbPath = $fileData['thumb_path'] ?? null;
						if ($thumbPath) {
							$firstThumb = '/uploads/gallery/' . $thumbPath;
						}
					}
				}
			}

			$items[] = [
				'id'           => $child['id'],
				'type'         => $child['type'],
				'name'         => $name,
				'has_children' => (int) ($child['child_count'] ?? 0) > 0,
				'first_thumb'  => $firstThumb,
				'is_video'     => $isVideo ?? false,
				'is_audio'     => $isAudio ?? false,
			];
		}

		$html = $this->twig->render('gallery-section.html.twig', [
			'items' => $items,
		]);

		return ApiResponse::success(['html' => $html]);
	}

	// ============================================
	// ИНФОРМАЦИЯ ОБ ЭЛЕМЕНТЕ
	// ============================================

	/**
	 * GET /api/gallery/item/{id}
	 * 
	 * Возвращает HTML с информацией об элементе или файле.
	 * 
	 * Для file: показывает полноразмерное изображение с названием и описанием.
	 * Для item: показывает метаданные и все вложенные файлы.
	 */
	public function item(int $id): JsonResponse
	{
		// Собираем хлебные крошки
		$breadcrumbs = $this->buildBreadcrumbs($id);

		// Ищем элемент
		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return ApiResponse::error('Элемент не найден', 404);
		}

		// Парсим data
		$itemData = is_string($item['data'] ?? null)
			? json_decode($item['data'], true)
			: ($item['data'] ?? []);
		$item['data'] = $itemData;

		// Подтягиваем название и описание из текста
		if ($item['text']) {
			$text = $this->textRepo->findByKeyAndLang($item['text'], 'ru');
			
			// Если нет русского — ищем любой язык
			if (!$text || !$text['name']) {
				$allTexts = $this->textRepo->findAllByKey($item['text']);
				foreach ($allTexts as $t) {
					if (!empty($t['name'])) {
						$text = $t;
						break;
					}
				}
			}
			
			if ($text) {
				$item['name'] = $text['name'] ?? $itemData['slug'] ?? 'Без названия';
				$item['description'] = $text['text'] ?? '';
			}
		}

		// ============================================
		// ДЛЯ FILE: показываем само фото
		// ============================================
		if ($item['type'] === 'file') {
			$fileData = $itemData;
			$photos = [];
			
			$thumbPath = $fileData['thumb_path'] ?? '';
			$storagePath = $fileData['storage_path'] ?? '';
			
			if ($storagePath) {
				$photos[] = [
					'id'     => $item['id'],
					'thumb'  => '/uploads/gallery/' . $thumbPath,
					'full'   => '/uploads/gallery/' . $storagePath,
					'width'  => $fileData['width'] ?? 800,
					'height' => $fileData['height'] ?? 600,
					'aspect' => ($fileData['width'] > 0 && $fileData['height'] > 0) 
						? ($fileData['width'] / $fileData['height']) 
						: 1.5,
					'title'  => $item['name'] ?? '',
				];
			}

			$isAdmin = $this->isAdmin();

			$html = $this->twig->render('gallery-item.html.twig', [
				'item'    => $item,
				'photos'  => $photos,
				'isAdmin' => $isAdmin,
				'breadcrumbs' => $breadcrumbs,
				'rootId'      => $galleryRoot['id'] ?? 0,
			]);

			return ApiResponse::success(['html' => $html]);
		}

		// ============================================
		// ДЛЯ ITEM/TREE: загружаем вложенные файлы
		// ============================================
		$files = $this->neuronRepo->findChildren($id, 'file');
		$photos = [];
		foreach ($files as $file) {
			$fileData = is_string($file['data'] ?? null)
				? json_decode($file['data'], true)
				: ($file['data'] ?? []);

			$photos[] = [
				'id'     => $file['id'],
				'thumb'  => '/uploads/gallery/' . ($fileData['thumb_path'] ?? ''),
				'full'   => '/uploads/gallery/' . ($fileData['storage_path'] ?? ''),
				'width'  => $fileData['width'] ?? 800,
				'height' => $fileData['height'] ?? 600,
				'aspect' => ($fileData['width'] ?? 800) / ($fileData['height'] ?? 600),
				'title'  => $item['name'] ?? '',
			];
		}

		$isAdmin = $this->isAdmin();

		$html = $this->twig->render('gallery-item.html.twig', [
			'item'    => $item,
			'photos'  => $photos,
			'isAdmin' => $isAdmin,
		]);

		return ApiResponse::success(['html' => $html]);
	}

	// ============================================
	// СКАЧИВАНИЕ ФАЙЛА
	// ============================================

	/**
	 * GET /api/gallery/download/{id}
	 * 
	 * Отдаёт файл для скачивания с оригинальным именем.
	 * 
	 * @param int $id — id нейрона type='file'
	 * @return Response — бинарный ответ с заголовками
	 */
	public function download(int $id): Response
	{
		// Ищем файл
		$file = $this->neuronRepo->findById($id);
		if (!$file || $file['type'] !== 'file') {
			return new Response('File not found', 404);
		}

		// Извлекаем данные файла
		$fileData = is_string($file['data'] ?? null)
			? json_decode($file['data'], true)
			: ($file['data'] ?? []);

		// Путь к файлу в хранилище
		$storagePath = __DIR__ . '/../../public/uploads/gallery/' . ($fileData['storage_path'] ?? '');
		$originalName = $fileData['original_name'] ?? 'download';

		if (!file_exists($storagePath)) {
			return new Response('File not found', 404);
		}

		// Отдаём файл
		return new Response(
			file_get_contents($storagePath),
			200,
			[
				'Content-Type'        => $fileData['mime'] ?? 'application/octet-stream',
				'Content-Disposition' => 'attachment; filename="' . $originalName . '"',
				'Content-Length'      => filesize($storagePath),
			]
		);
	}

	// ============================================
	// ЗАГРУЗКА ФАЙЛОВ В РАЗДЕЛ (АДМИН)
	// ============================================

	/**
	 * POST /api/gallery/upload
	 * 
	 * Загружает файлы в указанный раздел.
	 * Каждый файл создаёт item + file (как при импорте из папки).
	 * 
	 * @param Request $request — parent_id и files[]
	 * @return JsonResponse
	 */
	public function upload(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$parentId = (int) $request->request->get('parent_id', 0);
		$files = $request->files->get('files');

		if (!$files || !$parentId) {
			return ApiResponse::error('Нет файлов или не указан родитель');
		}

		$uploaded = [];
		$errors = [];

		foreach ($files as $file) {
			try {
				$id = $this->saveFile($file, $parentId);
				$uploaded[] = $id;
			} catch (\Exception $e) {
				$errors[] = $file->getClientOriginalName() . ': ' . $e->getMessage();
			}
		}

		return ApiResponse::success(['uploaded' => $uploaded, 'errors' => $errors]);
	}

	// ============================================
	// ДОБАВЛЕНИЕ ФОТО К СУЩЕСТВУЮЩЕМУ ЭЛЕМЕНТУ (АДМИН)
	// ============================================

	/**
	 * POST /api/gallery/item/{id}/upload
	 * 
	 * Добавляет фото к существующему элементу.
	 * Создаёт только file-нейроны внутри item'а.
	 * 
	 * @param int $id — id элемента (item)
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function uploadToItem(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// Проверяем существование элемента
		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return ApiResponse::error('Элемент не найден', 404);
		}

		$files = $request->files->get('files');
		if (!$files) {
			return ApiResponse::error('Нет файлов');
		}

		$uploaded = [];
		$errors = [];

		foreach ($files as $file) {
			try {
				$fileId = $this->saveFileToItem($file, $id);
				$uploaded[] = $fileId;
			} catch (\Exception $e) {
				$errors[] = $file->getClientOriginalName() . ': ' . $e->getMessage();
			}
		}

		return ApiResponse::success(['uploaded' => $uploaded, 'errors' => $errors]);
	}

	// ============================================
	// ОБНОВЛЕНИЕ ЭЛЕМЕНТА (АДМИН)
	// ============================================

	/**
	 * POST /api/gallery/item/{id}/update
	 * 
	 * Обновляет название, описание и метаданные элемента.
	 * Принимает JSON: name, description, meta, year, tags.
	 * 
	 * @param int $id — id элемента
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function updateItem(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// Проверяем существование элемента
		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return ApiResponse::error('Элемент не найден', 404);
		}

		$body = json_decode($request->getContent(), true);
		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$meta = $body['meta'] ?? null;
		$year = $body['year'] ?? null;
		$tags = $body['tags'] ?? null;

		// Обновляем текст (название и описание)
		if ($name !== null || $description !== null) {
			if ($item['text']) {
				$textKey = $this->textRepo->findOrCreate('ru', $name ?? '', $description ?? '');
				if ($textKey !== (int) $item['text']) {
					$this->neuronRepo->update($id, ['text' => $textKey]);
				}
			} elseif ($name || $description) {
				$textKey = $this->textRepo->findOrCreate('ru', $name ?? '', $description ?? '');
				$this->neuronRepo->update($id, ['text' => $textKey]);
			}
		}

		// Обновляем data (метаданные, год, теги)
		$currentData = is_string($item['data'] ?? null)
			? json_decode($item['data'], true)
			: ($item['data'] ?? []);

		if ($meta !== null) $currentData['meta'] = $meta;
		if ($year !== null) $currentData['year'] = $year;
		if ($tags !== null) $currentData['tags'] = $tags;

		$this->neuronRepo->update($id, ['data' => $currentData]);

		return ApiResponse::success(['id' => $id], 'Элемент обновлён');
	}

	// ============================================
	// УДАЛЕНИЕ ЭЛЕМЕНТА (АДМИН)
	// ============================================

	/**
	 * DELETE /api/gallery/item/{id}
	 * 
	 * Удаляет элемент вместе со всеми фото.
	 * Физически удаляет файлы с диска.
	 * 
	 * @param int $id — id элемента
	 * @return JsonResponse
	 */
	public function deleteItem(int $id): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return ApiResponse::error('Элемент не найден', 404);
		}

		// Удаляем все файлы элемента
		$files = $this->neuronRepo->findChildren($id, 'file');
		$galleryDir = __DIR__ . '/../../public/uploads/gallery/';

		foreach ($files as $file) {
			$fileData = is_string($file['data'] ?? null)
				? json_decode($file['data'], true)
				: ($file['data'] ?? []);

			// Удаляем оригинал
			if (!empty($fileData['storage_path'])) {
				$path = $galleryDir . $fileData['storage_path'];
				if (file_exists($path)) unlink($path);
			}
			// Удаляем миниатюру
			if (!empty($fileData['thumb_path'])) {
				$path = $galleryDir . $fileData['thumb_path'];
				if (file_exists($path)) unlink($path);
			}

			$this->neuronRepo->delete($file['id'], false);
		}

		// Удаляем сам элемент
		$this->neuronRepo->delete($id, false);

		return ApiResponse::success(['id' => $id], 'Элемент удалён');
	}

	// ============================================
	// УДАЛЕНИЕ ФАЙЛА (АДМИН)
	// ============================================

	/**
	 * DELETE /api/gallery/file/{id}
	 * 
	 * Удаляет отдельный файл (фото) из элемента.
	 * 
	 * @param int $id — id нейрона type='file'
	 * @return JsonResponse
	 */
	public function deleteFile(int $id): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$file = $this->neuronRepo->findById($id);
		if (!$file || $file['type'] !== 'file') {
			return ApiResponse::error('Файл не найден', 404);
		}

		// Удаляем физические файлы
		$fileData = is_string($file['data'] ?? null)
			? json_decode($file['data'], true)
			: ($file['data'] ?? []);

		$galleryDir = __DIR__ . '/../../public/uploads/gallery/';

		if (!empty($fileData['storage_path'])) {
			$path = $galleryDir . $fileData['storage_path'];
			if (file_exists($path)) unlink($path);
		}
		if (!empty($fileData['thumb_path'])) {
			$path = $galleryDir . $fileData['thumb_path'];
			if (file_exists($path)) unlink($path);
		}

		$this->neuronRepo->delete($id, false);

		return ApiResponse::success(['id' => $id], 'Файл удалён');
	}

	// ============================================
	// ИМПОРТ ИЗ ПАПКИ (АДМИН)
	// ============================================

	/**
	 * POST /api/gallery/import
	 * 
	 * Импортирует файлы из папки public/uploads/gallery/_import/.
	 * Поддерживает два режима:
	 * 1. Структура папок — папки → разделы, файлы → элементы
	 * 2. manifest.json — структура и метаданные из JSON
	 * 
	 * @return JsonResponse — статистика импорта (created, errors)
	 */
	public function import(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// Находим корень галереи
		$galleryRoot = $this->neuronRepo->findBySlug('gallery');
		$parentId = $galleryRoot ? (int) $galleryRoot['id'] : null;

		// Запускаем импорт
		$importer = new GalleryImportService($this->textRepo, $this->neuronRepo);
		$result = $importer->import($parentId);

		$this->neuronRepo->logAdminAction('gallery_import', [
			'created' => $result['created'],
			'errors'  => count($result['errors']),
		]);

		return ApiResponse::success($result);
	}

	// ============================================
	// ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
	// ============================================

	/**
	 * Сохраняет загруженный файл напрямую в раздел.
	 * Название берётся из оригинального имени файла, сохраняется в data.
	 * Текст не создаётся.
	 */
	private function saveFile($uploadedFile, int $parentId): int
	{
		$originalName = $uploadedFile->getClientOriginalName();
		$itemName = pathinfo($originalName, PATHINFO_FILENAME);
		$mimeType = $uploadedFile->getMimeType() ?: 'application/octet-stream';
		$size = $uploadedFile->getSize();
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

		$datePath = date('Y/m/d');
		$storageDir = __DIR__ . '/../../public/uploads/gallery/' . $datePath;
		if (!is_dir($storageDir)) {
			mkdir($storageDir, 0775, true);
		}

		$storageName = md5($originalName . time()) . '.' . $extension;
		$uploadedFile->move($storageDir, $storageName);

		$imageInfo = [];
		$thumbName = null;

		if ($this->isImageExtension($extension)) {
			$thumbName = md5($originalName . time()) . '_thumb.' . $extension;
			$imageInfo = $this->createThumbnail(
				$storageDir . '/' . $storageName,
				$storageDir . '/' . $thumbName
			);
		}

		return $this->neuronRepo->create('file', [
			'original_name' => $originalName,
			'display_name'  => $itemName,
			'mime'          => $mimeType,
			'size'          => $size,
			'width'         => $imageInfo['width'] ?? 0,
			'height'        => $imageInfo['height'] ?? 0,
			'storage_path'  => $datePath . '/' . $storageName,
			'thumb_path'    => $thumbName ? ($datePath . '/' . $thumbName) : null,
			'uploaded_at'   => date('Y-m-d H:i:s'),
			'is_video'      => $this->isVideoFile($extension),
			'is_audio'		=> $this->isAudioExtension($extension),
		], $parentId);
	}

	/**
	 * Сохраняет файл в существующий элемент (только file, без item).
	 * Используется при добавлении фото к существующему элементу.
	 * 
	 * @param mixed $uploadedFile
	 * @param int $itemId — id элемента
	 * @return int — id созданного file-нейрона
	 */
	private function saveFileToItem($uploadedFile, int $itemId): int
	{
		$originalName = $uploadedFile->getClientOriginalName();
		$mimeType = $uploadedFile->getMimeType() ?: 'application/octet-stream';
		$size = $uploadedFile->getSize();
		$extension = pathinfo($originalName, PATHINFO_EXTENSION);

		$datePath = date('Y/m/d');
		$storageDir = __DIR__ . '/../../public/uploads/gallery/' . $datePath;
		if (!is_dir($storageDir)) {
			mkdir($storageDir, 0775, true);
		}

		$storageName = md5($originalName . time()) . '.' . $extension;
		$thumbName = md5($originalName . time()) . '_thumb.' . $extension;

		$uploadedFile->move($storageDir, $storageName);

		$imageInfo = $this->createThumbnail(
			$storageDir . '/' . $storageName,
			$storageDir . '/' . $thumbName
		);

		return $this->neuronRepo->create('file', [
			'original_name' => $originalName,
			'mime'          => $mimeType,
			'size'          => $size,
			'width'         => $imageInfo['width'] ?? 0,
			'height'        => $imageInfo['height'] ?? 0,
			'storage_path'  => $datePath . '/' . $storageName,
			'thumb_path'    => $datePath . '/' . $thumbName,
			'uploaded_at'   => date('Y-m-d H:i:s'),
		], $itemId);
	}

	/**
	 * Создаёт миниатюру изображения.
	 * Сохраняет пропорции, максимальный размер — 400px по большей стороне.
	 * 
	 * @param string $sourcePath — путь к оригиналу
	 * @param string $thumbPath — путь для сохранения миниатюры
	 * @param int $maxSize — максимальный размер (по умолчанию 400)
	 * @return array — ['width' => int, 'height' => int] или []
	 */
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

		// Вычисляем размеры миниатюры с сохранением пропорций
		if ($width > $height) {
			$newWidth = $maxSize;
			$newHeight = (int) ($height * ($maxSize / $width));
		} else {
			$newHeight = $maxSize;
			$newWidth = (int) ($width * ($maxSize / $height));
		}

		// Создаём исходное изображение
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

		// Создаём миниатюру
		$thumb = imagecreatetruecolor($newWidth, $newHeight);

		// Сохраняем прозрачность для PNG
		if ($mime === 'image/png') {
			imagealphablending($thumb, false);
			imagesavealpha($thumb, true);
		}

		// Масштабируем
		imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

		// Сохраняем
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

	/**
	 * Проверяет и создаёт структуру папок для галереи.
	 */
	private function ensureDirectories(): void
	{
		$baseDir = __DIR__ . '/../../public/uploads/gallery';

		foreach (['', '/_import'] as $dir) {
			$fullPath = $baseDir . $dir;
			if (!is_dir($fullPath)) {
				mkdir($fullPath, 0775, true);
			}
		}
	}

	/**
	 * Инициализирует структуру галереи.
	 * Создаёт нейрон Галерея (slug=gallery) внутри MEDIA.
	 * 
	 * @return Response — редирект на /gallery
	 */
	private function initGallery(): Response
	{
		// Ищем корень MEDIA
		$mediaRoot = $this->neuronRepo->findBySlug('MEDIA');
		if (!$mediaRoot) {
			return new Response('MEDIA root not found. Run TREE import first.', 500);
		}

		// Создаём Галерею
		$this->neuronRepo->create('tree', ['slug' => 'gallery'], $mediaRoot['id']);

		return new Response('', 302, ['Location' => '/gallery']);
	}

	/**
	 * Собирает хлебные крошки от корня галереи до указанного нейрона.
	 * 
	 * @param int $id — id нейрона
	 * @return array — массив крошек [{name, id}, ...]
	 */
	private function buildBreadcrumbs(int $id): array
	{
		$breadcrumbs = [];
		$current = $this->neuronRepo->findById($id);
		
		while ($current) {
			// Определяем название
			$name = 'Без названия';
			if ($current['text']) {
				$allTexts = $this->textRepo->findAllByKey($current['text']);
				foreach ($allTexts as $t) {
					if ($t['lang'] === 'ru' && !empty($t['name'])) {
						$name = $t['name'];
						break;
					}
				}
				if ($name === 'Без названия') {
					foreach ($allTexts as $t) {
						if (!empty($t['name'])) {
							$name = $t['name'];
							break;
						}
					}
				}
			}
			
			if ($name === 'Без названия') {
				$currentData = is_string($current['data'] ?? null)
					? json_decode($current['data'], true)
					: ($current['data'] ?? []);
				$name = $currentData['slug'] ?? $currentData['original_name'] ?? 'Без названия';
			}

			array_unshift($breadcrumbs, [
				'name' => $name,
				'id'   => $current['id'],
				'type' => $current['type'],
			]);

			// Поднимаемся к родителю
			if ($current['pid']) {
				$current = $this->neuronRepo->findById($current['pid']);
				// Останавливаемся на корне галереи (slug=gallery)
				$currentData = is_string($current['data'] ?? null)
					? json_decode($current['data'], true)
					: ($current['data'] ?? []);
				if (($currentData['slug'] ?? '') === 'gallery') break;
			} else {
				break;
			}
		}

		return $breadcrumbs;
	}

	/**
	 * POST /api/gallery/section/{id}/set-thumb
	 * Устанавливает превьюшку для раздела из указанного файла.
	 */
	public function setSectionThumb(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$section = $this->neuronRepo->findById($id);
		if (!$section || $section['type'] !== 'tree') {
			return ApiResponse::error('Раздел не найден', 404);
		}

		$body = json_decode($request->getContent(), true);
		$fileId = (int) ($body['file_id'] ?? 0);

		$file = $this->neuronRepo->findById($fileId);
		if (!$file || $file['type'] !== 'file') {
			return ApiResponse::error('Файл не найден', 404);
		}

		$fileData = is_string($file['data'] ?? null)
			? json_decode($file['data'], true)
			: ($file['data'] ?? []);

		$thumbPath = $fileData['thumb_path'] ?? $fileData['storage_path'] ?? null;
		if (!$thumbPath) {
			return ApiResponse::error('У файла нет превьюшки', 400);
		}

		// Обновляем data раздела
		$currentData = is_string($section['data'] ?? null)
			? json_decode($section['data'], true)
			: ($section['data'] ?? []);

		$currentData['cover_thumb'] = $thumbPath;

		$this->neuronRepo->update($id, ['data' => $currentData]);

		return ApiResponse::success(['thumb' => '/uploads/gallery/' . $thumbPath], 'Превьюшка установлена');
	}

	/**
	 * POST /api/gallery/section/create
	 * Создаёт новый раздел в текущем разделе.
	 */
	public function createSection(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$body = json_decode($request->getContent(), true);
		$name = trim($body['name'] ?? '');
		$parentId = (int) ($body['parent_id'] ?? 0);

		if (empty($name)) {
			return ApiResponse::error('Название обязательно');
		}

		$textKey = $this->textRepo->findOrCreate('ru', $name);
		$id = $this->neuronRepo->create('tree', null, $parentId, $textKey);

		return ApiResponse::success(['id' => $id], 'Раздел создан');
	}

	private function isVideoFile(string $extension): bool
	{
		return in_array($extension, ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv']);
	}

	private function isAudioExtension(string $extension): bool
	{
		return in_array($extension, ['mp3', 'flac', 'wav', 'ogg', 'aac', 'm4a']);
	}

	private function isImageExtension(string $extension): bool
	{
		return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
	}
}