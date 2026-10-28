<?php

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
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Trait\BreadcrumbsTrait;
use Jan\Trinity\Core\Service\GuardService;
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

	/** @var GuardService — CSRF-защита. */
	private GuardService $guard;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	private Validator $validator;

	/** @var string Физический путь к папке загрузок галереи. */
	private string $galleryUploadDir;

	/** @var string Веб-путь к папке загрузок галереи. */
	private string $galleryUploadUrl;

	/** @var GalleryImportService — сервис импорта из папки. */
	private GalleryImportService $galleryImport;

	// [Лорелея]: Белые списки.
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
		GuardService $guard,
		Validator $validator,
		LoggerInterface $logger,
		string $galleryUploadDir,
		string $galleryUploadUrl
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->galleryImport = $galleryImport;
		$this->guard = $guard;
		$this->validator = $validator;
		$this->logger = $logger;

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
	 * [Лорелея]: Три белых списка.
	 *
	 * [Мириам]: И — это правильно. Потому что расширение —
	 * это не «имя». Это — знак.
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
	 * [Лорелея]: Расширение. MIME. Размер. Всё — через белый список.
	 *
	 * [Мириам]: И — это правильно. Потому что доверять клиенту
	 * нельзя. Даже если он админ. Особенно если он админ.
	 */
	private function validateUpload(UploadedFile $uploadedFile): array
	{
		$originalName = $uploadedFile->getClientOriginalName();
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

		if (!$this->isAllowedExtension($extension)) {
			throw new \RuntimeException('Недопустимое расширение файла: ' . $extension);
		}

		$mimeType = $uploadedFile->getMimeType() ?: 'application/octet-stream';
		if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
			throw new \RuntimeException('Недопустимый MIME-тип: ' . $mimeType);
		}

		return [
			'extension'    => $extension,
			'mime'         => $mimeType,
			'size'         => $uploadedFile->getSize(),
			'originalName' => $originalName,
		];
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
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}
		return null;
	}

	// ============================================
	// ГЛАВНАЯ СТРАНИЦА ГАЛЕРЕИ
	// ============================================

	/**
	 * GET /gallery
	 *
	 * [Лорелея]: Открываем прихожую. Показываем разделы.
	 *
	 * [Мириам]: И — это правильно. Потому что прихожая —
	 * это не «список». Это — встреча.
	 */
	public function index(): Response
	{
		$this->ensureDirectories();

		$galleryRoot = $this->neuronRepo->findBySlug('gallery');

		if (!$galleryRoot) {
			return $this->initGallery();
		}

		$sections = $this->neuronRepo->findChildrenWithText($galleryRoot['id'], 'ru');

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
	 * [Лорелея]: Показываем раздел. С файлами и папками.
	 *
	 * [Мириам]: И — это правильно. Потому что раздел —
	 * это не «список». Это — ветвь.
	 */
	public function section(int $id): JsonResponse
	{
		$children = $this->neuronRepo->findChildrenWithText($id, 'ru');

		$treeIds = [];
		foreach ($children as $child) {
			if ($child['type'] === 'tree' && (int) ($child['child_count'] ?? 0) > 0) {
				$treeIds[] = (int) $child['id'];
			}
		}

		$filesByTree = [];
		if (!empty($treeIds)) {
			$filesByTree = $this->neuronRepo->findFilesForTrees($treeIds);
		}

		$items = [];
		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);

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

			$firstThumb = null;
			$isVideo = false;
			$isAudio = false;

			if ($child['type'] === 'file') {
				$name = $childData['display_name'] ?? $childData['original_name'] ?? $name;

				$thumbPath = $childData['thumb_path'] ?? null;
				if ($thumbPath) {
					$firstThumb = $thumbPath;
				}
				$isVideo = $childData['is_video'] ?? false;
				$isAudio = $childData['is_audio'] ?? false;

			} elseif ($child['type'] === 'tree') {
				$coverThumb = $childData['cover_thumb'] ?? null;
				if ($coverThumb) {
					$firstThumb = $coverThumb;
				} elseif (isset($filesByTree[$child['id']]) && !empty($filesByTree[$child['id']])) {
					$firstFile = $filesByTree[$child['id']][0];
					$fileData = is_string($firstFile['data'] ?? null)
						? json_decode($firstFile['data'], true)
						: ($firstFile['data'] ?? []);
					$thumbPath = $fileData['thumb_path'] ?? null;
					if ($thumbPath) {
						$firstThumb = $thumbPath;
					}
				}
			}

			$items[] = [
				'id'           => $child['id'],
				'type'         => $child['type'],
				'name'         => $name,
				'has_children' => (int) ($child['child_count'] ?? 0) > 0,
				'first_thumb'  => $firstThumb,
				'is_video'     => $isVideo,
				'is_audio'     => $isAudio,
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
	 * [Лорелея]: Показываем элемент. С фото. С описанием.
	 *
	 * [Мириам]: И — это правильно. Потому что элемент —
	 * это не «файл». Это — картина.
	 */
	public function item(int $id): Response
	{
		$breadcrumbs = $this->buildBreadcrumbs($id, 'gallery', 'ru');

		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return $this->errorHandler->showError(404, 'Элемент не найден', [
				'url'    => $_SERVER['REQUEST_URI'] ?? '/',
				'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
				'user'   => 'guest',
				'time'   => date('Y-m-d H:i:s'),
			]);
		}

		$itemData = is_string($item['data'] ?? null)
			? json_decode($item['data'], true)
			: ($item['data'] ?? []);
		$item['data'] = $itemData;

		if ($item['text']) {
			$text = $this->textRepo->findByKeyAndLang($item['text'], 'ru');

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

		if ($item['type'] === 'file') {
			$fileData = $itemData;
			$photos = [];

			$thumbPath = $fileData['thumb_path'] ?? '';
			$storagePath = $fileData['storage_path'] ?? '';

			if ($storagePath) {
				$photos[] = [
					'id'     => $item['id'],
					'thumb'  => $thumbPath,
					'full'   => $storagePath,
					'width'  => $fileData['width'] ?? 800,
					'height' => $fileData['height'] ?? 600,
					'aspect' => ($fileData['width'] > 0 && $fileData['height'] > 0)
						? ($fileData['width'] / $fileData['height'])
						: 1.5,
					'title'  => $item['name'] ?? '',
				];
			}

			$html = $this->twig->render('gallery-item.html.twig', [
				'item'    => $item,
				'photos'  => $photos,
				'isAdmin' => $this->isAdmin(),
				'breadcrumbs' => $breadcrumbs,
			]);

			return ApiResponse::success(['html' => $html]);
		}

		$files = $this->neuronRepo->findChildrenWithText($id, 'ru', 'file');
		$photos = [];
		foreach ($files as $file) {
			$fileData = is_string($file['data'] ?? null)
				? json_decode($file['data'], true)
				: ($file['data'] ?? []);

			$photos[] = [
				'id'     => $file['id'],
				'thumb'  => ($fileData['thumb_path'] ?? ''),
				'full'   => ($fileData['storage_path'] ?? ''),
				'width'  => $fileData['width'] ?? 800,
				'height' => $fileData['height'] ?? 600,
				'aspect' => ($fileData['width'] ?? 800) / ($fileData['height'] ?? 600),
				'title'  => $item['name'] ?? '',
			];
		}

		$html = $this->twig->render('gallery-item.html.twig', [
			'item'    => $item,
			'photos'  => $photos,
			'isAdmin' => $this->isAdmin(),
		]);

		return ApiResponse::success(['html' => $html]);
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
	 * [Лорелея]: Обновляем. С CSRF. С валидацией.
	 *
	 * [Мириам]: И — это правильно. Потому что обновление —
	 * это не «замена». Это — продолжение.
	 */
	public function updateItem(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return ApiResponse::error('Элемент не найден', 404);
		}

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$meta = $body['meta'] ?? null;
		$year = $body['year'] ?? null;
		$tags = $body['tags'] ?? null;

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

		if ($name !== null || $description !== null) {
			if ($item['text']) {
				$this->textRepo->updateByKey((int) $item['text'], 'ru', $name ?? '', $description ?? '');
			} elseif ($name || $description) {
				$textKey = $this->textRepo->findOrCreate('ru', $name ?? '', $description ?? '');
				$this->neuronRepo->update($id, ['text' => $textKey]);
			}
		}

		$currentData = is_string($item['data'] ?? null)
			? json_decode($item['data'], true)
			: ($item['data'] ?? []);

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

			$this->neuronRepo->delete($file['id'], false);
		}

		$this->neuronRepo->delete($id, false);

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

		if (in_array($extension, self::ALLOWED_IMAGE_EXT, true)) {
			$thumbName = $unique . '_thumb.' . $extension;
			$imageInfo = $this->createThumbnail(
				$storageDir . '/' . $storageName,
				$storageDir . '/' . $thumbName
			);
		}

		$storagePath = $datePath . '/' . $storageName;
		$thumbPath = $thumbName ? ($datePath . '/' . $thumbName) : null;

		if (strpos($storagePath, '..') !== false || strpos($storagePath, '/') === 0) {
			$this->logger->warning('gallery: invalid storage_path', [
				'storage_path' => $storagePath,
			]);
			throw new \RuntimeException('Недопустимый путь хранения: ' . $storagePath);
		}

		if ($thumbPath !== null && (strpos($thumbPath, '..') !== false || strpos($thumbPath, '/') === 0)) {
			$this->logger->warning('gallery: invalid thumb_path', [
				'thumb_path' => $thumbPath,
			]);
			throw new \RuntimeException('Недопустимый путь миниатюры: ' . $thumbPath);
		}

		$data = [
			'original_name' => $validated['originalName'],
			'mime'          => $validated['mime'],
			'size'          => $validated['size'],
			'width'         => $imageInfo['width'] ?? 0,
			'height'        => $imageInfo['height'] ?? 0,
			'storage_path'  => $storagePath,
			'thumb_path'    => $thumbPath,
			'uploaded_at'   => date('Y-m-d H:i:s'),
			'is_video'      => in_array($extension, self::ALLOWED_VIDEO_EXT, true),
			'is_audio'      => in_array($extension, self::ALLOWED_AUDIO_EXT, true),
		];

		if ($displayName !== null) {
			$data['display_name'] = $displayName;
		}

		return $this->neuronRepo->create('file', $data, $parentId, $textKey);
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
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}