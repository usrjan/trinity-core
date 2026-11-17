<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР ГАЛЕРЕИ
 *
 * Управление галереей изображений с древовидной структурой.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — сердце прихожей.
 * Тот, кто встречает гостя и провожает его к картинам.
 *
 * [Мириам]: И — это правильно. Потому что прихожая —
 * это не «комната». Это — встреча.
 *
 * === КНИГА ===
 * Глава 9. Прихожая.
 *
 * В прихожей Амбера висят картины.
 * В галерее Trinity — файлы. И те, кто их показывает.
 *
 * @package Jan\Trinity\Plugin\Gallery
 */

namespace Jan\Trinity\Plugin\Gallery;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Trait\BreadcrumbsTrait;
use Jan\Trinity\Core\Service\CsrfService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class GalleryController
{
	use AuthMiddleware;
	use ThumbnailTrait;
	use BreadcrumbsTrait;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	private Validator $validator;

	/** @var string Физический путь к папке загрузок галереи. */
	private string $galleryUploadDir;

	/** @var string Веб-путь к папке загрузок галереи. */
	private string $galleryUploadUrl;

	/** @var GalleryImportService — сервис импорта из папки. */
	private GalleryImportService $galleryImport;

	private ConfigService $configService;

	/** @var CsrfService — CSRF-защита. */
	private CsrfService $csrfService;

	/**
	 * Белые списки расширений и MIME.
	 *
	 * [Лорелея]: Три списка расширений и один список MIME,
	 * потому что доверять клиенту нельзя, даже если он админ.
	 *
	 * [Мириам]: И это правильно, потому что расширение —
	 * это не «имя», а знак, и знак должен быть честным.
	 */
	private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
	private const ALLOWED_VIDEO_EXT = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
	private const ALLOWED_AUDIO_EXT = ['mp3', 'flac', 'wav', 'ogg', 'aac', 'm4a'];

	private const ALLOWED_MIME = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
		'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
		'audio/mpeg', 'audio/flac', 'audio/wav', 'audio/ogg', 'audio/aac', 'audio/mp4',
	];

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: ГЛАВНОЕ. initAuth() теперь получает
	 * SynapseRepository вторым аргументом. Чтобы refreshRoles()
	 * работал. А не был декорацией.
	 *
	 * [Мириам]: И — это правильно. Потому что роли должны
	 * обновляться. Всегда. Даже если пользователь давно вошёл.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		GalleryImportService $galleryImport,
		Validator $validator,
		LoggerInterface $logger,
		CsrfService $csrfService,
		string $galleryUploadDir,
		string $galleryUploadUrl,
		ConfigService $configService
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->galleryImport = $galleryImport;
		$this->validator = $validator;
		$this->logger = $logger;
		$this->csrfService = $csrfService;
		$this->configService = $configService;

		$this->galleryUploadDir = rtrim($galleryUploadDir, '/');
		$this->galleryUploadUrl = rtrim($galleryUploadUrl, '/');

		// [Лорелея]: Передаём SynapseRepository. Чтобы refreshRoles()
		// работал. А не был декорацией.
		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// ВАЛИДАЦИЯ ЗАГРУЗОК
	// ============================================

	/**
	 * Проверяет, разрешено ли расширение.
	 *
	 * [Лорелея]: Три белых списка, и каждый — отдельная категория,
	 * потому что изображение, видео и аудио — это разные вещи.
	 *
	 * [Мириам]: И это правильно, потому что у каждой вещи
	 * своё место.
	 */
	private function isAllowedExtension(string $ext): bool
	{
		return in_array($ext, self::ALLOWED_IMAGE_EXT, true)
			|| in_array($ext, self::ALLOWED_VIDEO_EXT, true)
			|| in_array($ext, self::ALLOWED_AUDIO_EXT, true);
	}

	/**
	 * Валидирует загруженный файл.
	 *
	 * [Лорелея]: Сначала расширение, потом MIME, потом размер,
	 * потому что доверять клиенту нельзя, даже если он админ.
	 * И размер должен быть ограничен явно, а не «где-то в php.ini».
	 *
	 * [Мириам]: И это правильно, потому что кувшин должен знать,
	 * сколько в него влезет.
	 *
	 * @param UploadedFile $uploadedFile
	 * @return array
	 */
	private function validateUpload(UploadedFile $uploadedFile): array
	{
		$originalName = $uploadedFile->getClientOriginalName();
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

		// [Лорелея]: Сначала расширение, потому что оно приходит
		// от клиента и может быть любым.
		if (!$this->isAllowedExtension($extension)) {
			throw new \RuntimeException('Недопустимое расширение файла: ' . $extension);
		}

		// [Мириам]: Потом MIME, но не от клиента, а по содержимому,
		// потому что Content-Type врёт, а magic bytes — нет.
		$mimeType = $uploadedFile->getMimeType() ?: 'application/octet-stream';
		if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
			throw new \RuntimeException('Недопустимый MIME-тип: ' . $mimeType);
		}

		// [Лорелея]: И размер, потому что галерея может съесть
		// весь диск, если не ограничить.
		$size = $uploadedFile->getSize();
		$maxSize = $this->getMaxUploadSize($extension);

		if ($size > $maxSize) {
			throw new \RuntimeException(sprintf(
				'Файл слишком большой: %d байт. Максимум для этого типа: %d байт.',
				$size,
				$maxSize
			));
		}

		return [
			'extension'    => $extension,
			'mime'         => $mimeType,
			'size'         => $size,
			'originalName' => $originalName,
		];
	}

	/**
	 * Возвращает максимальный размер файла для расширения.
	 *
	 * [Лорелея]: Изображения — 50 МБ, видео — 500 МБ, аудио — 100 МБ,
	 * и это из конфига, а не «магические числа».
	 *
	 * [Мириам]: И это правильно, потому что лимит должен быть
	 * явным, а не «где-то в php.ini».
	 *
	 * @param string $extension
	 * @return int
	 */
	private function getMaxUploadSize(string $extension): int
	{
		if (in_array($extension, self::ALLOWED_IMAGE_EXT, true)) {
			return (int) ($_ENV['GALLERY_MAX_IMAGE_SIZE'] ?? 50 * 1024 * 1024);
		}

		if (in_array($extension, self::ALLOWED_VIDEO_EXT, true)) {
			return (int) ($_ENV['GALLERY_MAX_VIDEO_SIZE'] ?? 500 * 1024 * 1024);
		}

		if (in_array($extension, self::ALLOWED_AUDIO_EXT, true)) {
			return (int) ($_ENV['GALLERY_MAX_AUDIO_SIZE'] ?? 100 * 1024 * 1024);
		}

		return (int) ($_ENV['GALLERY_MAX_FILE_SIZE'] ?? 50 * 1024 * 1024);
	}

	// ============================================
	// ПРОВЕРКА CSRF
	// ============================================

	/**
	 * Проверяет CSRF-токен.
	 *
	 * [Лорелея]: Одна точка.
	 *
	 * [Мириам]: И — это правильно. Потому что проверка —
	 * это не «формальность». Это — защита.
	 */
	private function requireCsrf(Request $request): ?JsonResponse
	{
		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->csrfService->validateToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}
		return null;
	}

	// ============================================
	// СКАЧИВАНИЕ ФАЙЛА
	// ============================================

	/**
	 * GET /api/gallery/download/{id}
	 *
	 * [Лорелея]: Скачиваем файл. Через BinaryFileResponse.
	 *
	 * [Мириам]: И — это правильно. Потому что файл —
	 * это не «ссылка». Это — память.
	 */
	public function download(int $id): Response
	{
		$file = $this->neuronRepo->findById($id);
		if (!$file || $file['type'] !== 'file') {
			throw new \RuntimeException('Файл не найден', 404);
		}

		$fileData = is_string($file['data'] ?? null)
			? json_decode($file['data'], true)
			: ($file['data'] ?? []);

		$storagePath = $this->resolveGalleryPath($fileData['storage_path'] ?? '');
		$originalName = $fileData['original_name'] ?? 'download';

		$safeName = basename($originalName);
		$safeName = rawurlencode($safeName);

		if ($storagePath === null || !file_exists($storagePath)) {
			throw new \RuntimeException('Файл не найден на диске', 404);
		}

		$response = new BinaryFileResponse($storagePath);
		$response->headers->set('Content-Type', $fileData['mime'] ?? 'application/octet-stream');
		$response->setContentDisposition(
			\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
			$safeName
		);

		return $response;
	}

	// ============================================
	// ЗАГРУЗКА ФАЙЛОВ В РАЗДЕЛ (АДМИН)
	// ============================================

	/**
	 * POST /api/gallery/upload
	 *
	 * [Лорелея]: Загружаем. С CSRF. С валидацией.
	 *
	 * [Мириам]: И — это правильно. Потому что загрузка —
	 * это не «копирование». Это — принятие.
	 */
	public function upload(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

		$parentId = (int) $request->request->get('parent_id', 0);
		$files = $request->files->get('files');

		if (!$this->validator->validate(['parent_id' => $parentId], [
			'parent_id' => ['required', 'int', 'min:1'],
		])) {
			return ApiResponse::error('Некорректный parent_id', 400);
		}

		if (!$files) {
			return ApiResponse::error('Нет файлов', 400);
		}

		$uploaded = [];
		$errors = [];

		foreach ($files as $file) {
			try {
				$id = $this->saveFile($file, $parentId);
				$uploaded[] = $id;

			} catch (\Throwable $e) {
				$this->logger->error('gallery: upload failed', [
					'file'    => $file->getClientOriginalName(),
					'message' => $e->getMessage(),
					'file_path' => $e->getFile() . ':' . $e->getLine(),
				]);

				$clientMessage = $this->isDebug()
					? $file->getClientOriginalName() . ': ' . $e->getMessage()
					: $file->getClientOriginalName() . ': ошибка загрузки';

				$errors[] = $clientMessage;
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
	 * [Лорелея]: Добавляем фото к элементу. С CSRF.
	 *
	 * [Мириам]: И — это правильно. Потому что добавление —
	 * это не «замена». Это — продолжение.
	 */
	public function uploadToItem(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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

			} catch (\Throwable $e) {
				$this->logger->error('gallery: upload to item failed', [
					'file'    => $file->getClientOriginalName(),
					'item_id' => $id,
					'message' => $e->getMessage(),
					'file_path' => $e->getFile() . ':' . $e->getLine(),
				]);

				$clientMessage = $this->isDebug()
					? $file->getClientOriginalName() . ': ' . $e->getMessage()
					: $file->getClientOriginalName() . ': ошибка загрузки';

				$errors[] = $clientMessage;
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
	 * [Лорелея]: Обновляем элемент. С CSRF, с валидацией и с
	 * декодированными данными, потому что одно место для json_decode
	 * лучше, чем пять.
	 *
	 * [Мириам]: И это правильно, потому что правда должна быть
	 * в одном месте.
	 *
	 * @param int $id
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function updateItem(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return ApiResponse::error('Элемент не найден', 404);
		}

		$currentData = $item['data'];

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$meta = $body['meta'] ?? null;
		$year = $body['year'] ?? null;
		$tags = $body['tags'] ?? null;

		// [Мириам]: Валидация — это не «формальность», а забота
		// о том, чтобы данные были честными.
		if ($name !== null && !$this->validator->validate(['name' => $name], [
			'name' => ['string', 'min:1', 'max:255'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		if ($year !== null && $year !== '' && !$this->validator->validate(['year' => $year], [
			'year' => ['int', 'min:1000', 'max:2100'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		if ($tags !== null && !$this->validator->validate(['tags' => $tags], [
			'tags' => ['array'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		// [Лорелея]: Если меняется имя или описание — обновляем
		// текст, потому что текст — это голос элемента.
		if ($name !== null || $description !== null) {
			if ($item['text']) {
				$this->textRepo->updateByKey((int) $item['text'], 'ru', $name ?? '', $description ?? '');
			} elseif ($name || $description) {
				$textKey = $this->textRepo->findOrCreate('ru', $name ?? '', $description ?? '');
				$this->neuronRepo->update($id, ['text' => $textKey]);
			}
		}

		// [Мириам]: Если меняются метаданные — обновляем их,
		// потому что метаданные — это то, что делает элемент
		// узнаваемым.
		if ($meta !== null) {
			$filteredMeta = array_filter($meta, function($value) {
				return !empty(trim((string)$value));
			});

			if (!empty($filteredMeta)) {
				$currentData['meta'] = $filteredMeta;
			} else {
				unset($currentData['meta']);
			}
		}
		if ($year !== null) $currentData['year'] = $year;
		if ($tags !== null) $currentData['tags'] = $tags;

		// [Лорелея]: Обновляем data, и это правильно, потому что
		// data — это то, что делает элемент собой.
		$this->neuronRepo->update($id, ['data' => $currentData]);

		return ApiResponse::success(['id' => $id], 'Элемент обновлён');
	}

	// ============================================
	// УДАЛЕНИЕ ЭЛЕМЕНТА (АДМИН)
	// ============================================

	/**
	 * DELETE /api/gallery/item/{id}
	 *
	 * [Лорелея]: Удаляем. С CSRF. Мягко.
	 *
	 * [Мириам]: И — это правильно. Потому что удаление —
	 * это не «конец». Это — пауза.
	 */
	public function deleteItem(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return ApiResponse::error('Элемент не найден', 404);
		}

		// [Лорелея]: Сначала удаляем синапсы, потому что это
		// отдельное решение, а не побочный эффект.
		$this->synapseRepo->deleteByNeuron($id);

		// [Мириам]: Потом удаляем файлы и сам элемент.
		$files = $this->neuronRepo->findChildren($id, 'file');

		foreach ($files as $file) {
			$fileData = is_string($file['data'] ?? null)
				? json_decode($file['data'], true)
				: ($file['data'] ?? []);

			if (!empty($fileData['storage_path'])) {
				$path = $this->resolveGalleryPath($fileData['storage_path']);
				if ($path !== null && !unlink($path)) {
					$this->logger->warning('gallery: failed to unlink storage', [
						'path'    => $path,
						'file_id' => $file['id'] ?? null,
					]);
				}
			}

			if (!empty($fileData['thumb_path'])) {
				$path = $this->resolveGalleryPath($fileData['thumb_path']);
				if ($path !== null && !unlink($path)) {
					$this->logger->warning('gallery: failed to unlink thumb', [
						'path'    => $path,
						'file_id' => $file['id'] ?? null,
					]);
				}
			}

			$this->neuronRepo->delete($file['id']);
		}

		$this->neuronRepo->delete($id);

		return ApiResponse::success(['id' => $id], 'Элемент удалён');
	}

	// ============================================
	// УДАЛЕНИЕ ФАЙЛА (АДМИН)
	// ============================================

	/**
	 * DELETE /api/gallery/file/{id}
	 *
	 * [Лорелея]: Удаляем файл. С CSRF. Мягко.
	 *
	 * [Мириам]: И — это правильно. Потому что удаление —
	 * это не «конец». Это — пауза.
	 */
	public function deleteFile(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) {
			return $error;
		}

		if ($error = $this->requireCsrf($request)) {
			return $error;
		}

		$file = $this->neuronRepo->findById($id);
		if (!$file || $file['type'] !== 'file') {
			return ApiResponse::error('Файл не найден', 404);
		}

		$fileData = is_string($file['data'] ?? null)
			? json_decode($file['data'], true)
			: ($file['data'] ?? []);

		if (!empty($fileData['storage_path'])) {
			$path = $this->resolveGalleryPath($fileData['storage_path']);
			if ($path !== null && !unlink($path)) {
				$this->logger->warning('gallery: failed to unlink storage', [
					'path'    => $path,
					'file_id' => $id,
				]);
			}
		}

		if (!empty($fileData['thumb_path'])) {
			$path = $this->resolveGalleryPath($fileData['thumb_path']);
			if ($path !== null && !unlink($path)) {
				$this->logger->warning('gallery: failed to unlink thumb', [
					'path'    => $path,
					'file_id' => $id,
				]);
			}
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
	 * [Лорелея]: Импорт из папки. С CSRF.
	 *
	 * [Мириам]: И — это правильно. Потому что импорт —
	 * это не «загрузка». Это — принятие.
	 */
	public function import(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

		$galleryRoot = $this->neuronRepo->findBySlug('gallery');
		$parentId = $galleryRoot ? (int) $galleryRoot['id'] : null;

		$result = $this->galleryImport->import($parentId);

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
	 * Перемещает файл и создаёт нейрон.
	 *
	 * [Лорелея]: С валидацией. С миниатюрой.
	 *
	 * [Мириам]: И — это правильно. Потому что файл —
	 * это не «копия». Это — оригинал.
	 */
	private function moveAndCreateFile(
		UploadedFile $uploadedFile,
		int $parentId,
		?int $textKey = null,
		?string $displayName = null
	): int {
		$validated = $this->validateUpload($uploadedFile);
		$extension = $validated['extension'];

		$datePath = date('Y/m/d');
		$storageDir = $this->galleryUploadDir . '/' . $datePath;
		if (!is_dir($storageDir)) {
			mkdir($storageDir, 0775, true);
		}

		$unique = md5($validated['originalName'] . time() . uniqid('', true));
		$storageName = $unique . '.' . $extension;
		$thumbName = null;
		$imageInfo = [];

		$uploadedFile->move($storageDir, $storageName);

		// [Лорелея]: Запоминаем путь, чтобы можно было удалить файл,
		// если создание нейрона упадёт. Осиротевший файл — это
		// не «файл», а «мусор».
		$storagePath = $storageDir . '/' . $storageName;
		$thumbPath = null;

		try {
			if (in_array($extension, self::ALLOWED_IMAGE_EXT, true)) {
				$thumbName = $unique . '_thumb.' . $extension;
				$thumbPath = $storageDir . '/' . $thumbName;
				$imageInfo = $this->createThumbnail($storagePath, $thumbPath);
			}

			$relativeStoragePath = $datePath . '/' . $storageName;
			$relativeThumbPath = $thumbName ? ($datePath . '/' . $thumbName) : null;

			if (strpos($relativeStoragePath, '..') !== false || strpos($relativeStoragePath, '/') === 0) {
				$this->logger->warning('gallery: invalid storage_path', [
					'storage_path' => $relativeStoragePath,
				]);
				throw new \RuntimeException('Недопустимый путь хранения: ' . $relativeStoragePath);
			}

			if ($relativeThumbPath !== null && (strpos($relativeThumbPath, '..') !== false || strpos($relativeThumbPath, '/') === 0)) {
				$this->logger->warning('gallery: invalid thumb_path', [
					'thumb_path' => $relativeThumbPath,
				]);
				throw new \RuntimeException('Недопустимый путь миниатюры: ' . $relativeThumbPath);
			}

			$data = [
				'original_name' => $validated['originalName'],
				'mime'          => $validated['mime'],
				'size'          => $validated['size'],
				'width'         => $imageInfo['width'] ?? 0,
				'height'        => $imageInfo['height'] ?? 0,
				'storage_path'  => $relativeStoragePath,
				'thumb_path'    => $relativeThumbPath,
				'uploaded_at'   => date('Y-m-d H:i:s'),
				'is_video'      => in_array($extension, self::ALLOWED_VIDEO_EXT, true),
				'is_audio'      => in_array($extension, self::ALLOWED_AUDIO_EXT, true),
			];

			if ($displayName !== null) {
				$data['display_name'] = $displayName;
			}

			return $this->neuronRepo->create('file', $data, $parentId, $textKey);

		} catch (\Throwable $e) {
			// [Мириам]: Если что-то пошло не так — удаляем файл
			// и миниатюру, потому что осиротевший файл — это
			// не «файл», а «мусор».
			if (file_exists($storagePath)) {
				@unlink($storagePath);
			}
			if ($thumbPath !== null && file_exists($thumbPath)) {
				@unlink($thumbPath);
			}

			throw $e;
		}
	}

	private function saveFile(UploadedFile $uploadedFile, int $parentId): int
	{
		$displayName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
		return $this->moveAndCreateFile($uploadedFile, $parentId, null, $displayName);
	}

	private function saveFileToItem(UploadedFile $uploadedFile, int $itemId): int
	{
		return $this->moveAndCreateFile($uploadedFile, $itemId);
	}

	private function ensureDirectories(): void
	{
		foreach (['', '/_import'] as $dir) {
			$fullPath = $this->galleryUploadDir . $dir;
			if (!is_dir($fullPath)) {
				mkdir($fullPath, 0775, true);
			}
		}
	}

	private function initGallery(): Response
	{
		$mediaRoot = $this->neuronRepo->findBySlug('MEDIA');
		if (!$mediaRoot) {
			return new Response('MEDIA root not found. Run TREE import first.', 500);
		}

		$this->neuronRepo->create('tree', ['slug' => 'gallery'], $mediaRoot['id']);

		return new RedirectResponse('/gallery');
	}

	public function setSectionThumb(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

		$section = $this->neuronRepo->findById($id);
		if (!$section || $section['type'] !== 'tree') {
			return ApiResponse::error('Раздел не найден', 404);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

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

		$currentData = is_string($section['data'] ?? null)
			? json_decode($section['data'], true)
			: ($section['data'] ?? []);

		$currentData['cover_thumb'] = $thumbPath;

		$this->neuronRepo->update($id, ['data' => $currentData]);

		return ApiResponse::success(['thumb' => $thumbPath], 'Превьюшка установлена');
	}

	public function createSection(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$name = trim($body['name'] ?? '');
		$parentId = (int) ($body['parent_id'] ?? 0);

		if (!$this->validator->validate([
			'name'      => $name,
			'parent_id' => $parentId,
		], [
			'name'      => ['required', 'string', 'min:1', 'max:255'],
			'parent_id' => ['required', 'int', 'min:1'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$textKey = $this->textRepo->findOrCreate('ru', $name);
		$id = $this->neuronRepo->create('tree', null, $parentId, $textKey);

		return ApiResponse::success(['id' => $id], 'Раздел создан');
	}

	private function resolveGalleryPath(string $relativePath): ?string
	{
		if (empty($relativePath)) {
			return null;
		}

		$fullPath = $this->galleryUploadDir . '/' . $relativePath;

		$realPath = realpath($fullPath);
		if ($realPath === false) {
			return null;
		}

		$baseDir = realpath($this->galleryUploadDir);
		if ($baseDir === false) {
			return null;
		}

		if (strpos($realPath, $baseDir . '/') !== 0) {
			$this->logger->warning('gallery: path traversal attempt', [
				'relative_path' => $relativePath,
				'real_path'     => $realPath,
			]);
			return null;
		}

		return $realPath;
	}

	private function isDebug(): bool
	{
		return $this->configService->getEnv('APP_DEBUG', 'false') === 'true';
	}
}