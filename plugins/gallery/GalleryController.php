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
 * - TextRepository        — работа с названиями и описаниями
 * - NeuronRepository      — работа с нейронами
 * - GalleryImportService  — импорт из папки
 * - GuardController       — CSRF и rate limit
 * - AuthMiddleware        — проверка прав доступа
 * 
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 * 
 * [Лорелея]: Я вынесла createThumbnail() в ThumbnailTrait,
 * потому что он дублировался в GalleryController и GalleryImportService.
 * Теперь один источник правды. Если понадобится поправить логику
 * миниатюр — правим в одном месте, а не в двух.
 * 
 * [Мириам]: Я добавила типы UploadedFile во все методы, которые
 * принимают файл из формы. Это делает код строже, Intelephense
 * перестаёт ругаться, IDE понимает, с чем работает.
 * 
 * [Лорелея]: Я добавила whitelist расширений и MIME. Раньше можно было
 * загрузить .php или .phtml и получить RCE. Теперь — только изображения,
 * видео и аудио. Всё остальное — RuntimeException.
 * 
 * [Мириам]: Я добавила requireCsrf() во все изменяющие методы:
 * upload, uploadToItem, updateItem, deleteItem, deleteFile,
 * setSectionThumb, createSection, import. Раньше CSRF был только
 * в deleteFile. Это было непоследовательно и небезопасно.
 * 
 * [Лорелея]: Я убрала мёртвые методы isVideoFile(), isAudioExtension(),
 * isImageExtension(). Они больше не используются — их работу
 * выполняет in_array($extension, self::ALLOWED_*_EXT, true).
 * 
 * [Мириам]: Я убрала $galleryRoot из item(). Раньше он там был
 * не определён и вызывал undefined variable. Теперь его нет.
 * 
 * [Лорелея]: Я оставила N+1 в section() как есть. Это известная
 * проблема, но оптимизация требует отдельного продумывания кэша.
 * Пока — работает. Потом — ускорим.
 */

namespace Jan\Trinity\Plugin\Gallery;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Plugin\Guard\GuardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Twig\Environment;

class GalleryController
{
	// [Лорелея]: AuthMiddleware даёт requireAdminForApi() и initAuth().
	// ThumbnailTrait даёт createThumbnail(). Это не «магия» — это
	// композиция. Мы не наследуемся, мы «примешиваем» умения.
	use AuthMiddleware;
	use ThumbnailTrait;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	/** @var GuardController — CSRF и rate limit */
	private GuardController $guard;

	private ErrorHandlerInterface $errorHandler;

	/**
	 * @var string Физический путь к папке загрузок галереи.
	 *             Берётся из .env (GALLERY_UPLOAD_DIR).
	 *             Без завершающего слэша.
	 *             Пример: /home/web/www/uploads/gallery
	 */
	private string $galleryUploadDir;

	/**
	 * @var string Веб-путь (URL) к папке загрузок галереи.
	 *             Берётся из .env (GALLERY_UPLOAD_URL).
	 *             Без завершающего слэша.
	 *             Пример: /uploads/gallery
	 */
	private string $galleryUploadUrl;

	/**
	 * @var GalleryImportService — сервис импорта из папки.
	 *                             Внедряется через DI, чтобы
	 *                             не создавать вручную и не
	 *                             дублировать пути.
	 */
	private GalleryImportService $galleryImport;

	// ============================================
	// WHITELIST РАСШИРЕНИЙ И MIME
	// ============================================
	// [Лорелея]: Раньше можно было загрузить .php и получить RCE.
	// Теперь — только эти расширения. Всё остальное — RuntimeException.
	// Это не «паранойя». Это — минимум. Даже если у нас админка,
	// это не значит, что можно всё.
	// ============================================

	/** @var string[] Разрешённые расширения изображений */
	private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

	/** @var string[] Разрешённые расширения видео */
	private const ALLOWED_VIDEO_EXT = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];

	/** @var string[] Разрешённые расширения аудио */
	private const ALLOWED_AUDIO_EXT = ['mp3', 'flac', 'wav', 'ogg', 'aac', 'm4a'];

	/**
	 * @var string[] Разрешённые MIME-типы.
	 * [Мириам]: Проверка на уровне сервера. Клиент может соврать
	 * в Content-Type, но getMimeType() читает файл и определяет
	 * реальный тип. Это надёжнее.
	 */
	private const ALLOWED_MIME = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
		'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
		'audio/mpeg', 'audio/flac', 'audio/wav', 'audio/ogg', 'audio/aac', 'audio/mp4',
	];

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 * [Лорелея]: GalleryImportService теперь внедряется, а не создаётся
	 * вручную в import(). Это чище — и путь к папке импорта тоже
	 * приходит из DI, а не хардкодится.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		GalleryImportService $galleryImport,
		GuardController $guard,
		ErrorHandlerInterface $errorHandler,
		string $galleryUploadDir,
		string $galleryUploadUrl
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->galleryImport = $galleryImport;
		$this->guard = $guard;
		$this->errorHandler = $errorHandler;

		// [Мириам]: Убираем завершающий слэш, чтобы при склейке
		// путей не получалось «//». Мелочь, но красиво.
		$this->galleryUploadDir = rtrim($galleryUploadDir, '/');
		$this->galleryUploadUrl = rtrim($galleryUploadUrl, '/');

		// [Лорелея]: Инициализация middleware. Без неё requireAdminForApi()
		// не знает, кто пришёл — гость или админ.
		$this->initAuth($session);
	}

	// ============================================
	// ВАЛИДАЦИЯ ЗАГРУЗОК
	// ============================================

	/**
	 * Проверяет, что расширение файла в whitelist.
	 * [Лорелея]: Приватный метод, потому что используется только внутри.
	 */
	private function isAllowedExtension(string $ext): bool
	{
		return in_array($ext, self::ALLOWED_IMAGE_EXT, true)
			|| in_array($ext, self::ALLOWED_VIDEO_EXT, true)
			|| in_array($ext, self::ALLOWED_AUDIO_EXT, true);
	}

	/**
	 * Валидирует загруженный файл: расширение и MIME.
	 * Бросает RuntimeException если файл недопустим.
	 *
	 * [Мириам]: Здесь теперь есть тип UploadedFile. Это то, о чём
	 * Intelephense ругался. Теперь — не ругается. И IDE подсказывает
	 * методы: getClientOriginalName(), getMimeType(), getSize().
	 *
	 * @param UploadedFile $uploadedFile — объект загруженного файла
	 * @return array ['extension' => string, 'mime' => string, 'size' => int, 'originalName' => string]
	 * @throws \RuntimeException если файл недопустим
	 */
	private function validateUpload(UploadedFile $uploadedFile): array
	{
		$originalName = $uploadedFile->getClientOriginalName();
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

		if (!$this->isAllowedExtension($extension)) {
			throw new \RuntimeException('Недопустимое расширение файла: ' . $extension);
		}

		// [Лорелея]: getMimeType() читает файл и определяет реальный тип.
		// Не доверяем клиенту. Даже если админ — не доверяем.
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
	 * Проверяет CSRF-токен. Возвращает JsonResponse при ошибке, null при успехе.
	 *
	 * [Мириам]: Одна строка вместо пяти. Раньше в каждом методе было
	 * «$csrfToken = $request->headers->get(...); if (!validate(...)) return ...».
	 * Теперь — requireCsrf($request). Читается легче.
	 *
	 * Использование:
	 *   if ($error = $this->requireCsrf($request)) return $error;
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
	 * Отображает главную страницу галереи с разделами верхнего уровня.
	 * Если структура галереи ещё не создана — инициализирует её.
	 *
	 * @return Response
	 */
	public function index(): Response
	{
		// [Лорелея]: Создаём папки, если их нет. Это не «на всякий случай»,
		// это нужно, потому что при первом запуске uploads/gallery не существует.
		$this->ensureDirectories();

		$galleryRoot = $this->neuronRepo->findBySlug('gallery');

		if (!$galleryRoot) {
			return $this->initGallery();
		}

		$sections = $this->neuronRepo->findChildren($galleryRoot['id']);

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
	 * Возвращает HTML с содержимым раздела.
	 * 
	 * [Лорелея]: Раньше здесь был N+1. Для каждой tree-папки
	 * с child_count > 0 делался отдельный findChildren(file).
	 * На 50 папок — 50 запросов.
	 * 
	 * [Мириам]: Теперь мы загружаем всех file-детей для всех
	 * tree-папок одним запросом. Потом группируем в PHP.
	 * Это уменьшает количество запросов с N+1 до 2:
	 * 1. findChildren($id) — все дети раздела.
	 * 2. findFilesForTrees($treeIds) — все файлы для всех папок.
	 * 
	 * @param int $id
	 * @return JsonResponse
	 */
	public function section(int $id): JsonResponse
	{
		$children = $this->neuronRepo->findChildren($id);

		// [Мириам]: Собираем id всех tree-папок, у которых есть дети.
		// Для них нам нужны файлы-превьюшки.
		$treeIds = [];
		foreach ($children as $child) {
			if ($child['type'] === 'tree' && (int) ($child['child_count'] ?? 0) > 0) {
				$treeIds[] = (int) $child['id'];
			}
		}

		// [Лорелея]: Один запрос за всеми файлами. Группируем в PHP.
		$filesByTree = [];
		if (!empty($treeIds)) {
			$filesByTree = $this->neuronRepo->findFilesForTrees($treeIds);
		}

		$items = [];
		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);

			// ============================================
			// ОПРЕДЕЛЯЕМ НАЗВАНИЕ
			// ============================================
			// [Лорелея]: Возвращено. В прошлой версии этого блока
			// не было — остался только комментарий. И $name был undefined.
			// Теперь — снова: имя из text, если есть. Иначе — slug.
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
	 * Возвращает HTML с информацией об элементе или файле.
	 *
	 * [Мириам]: Убрала $galleryRoot из render(). Его там не было
	 * определённым — это был undefined variable. В шаблоне
	 * gallery-item.html.twig rootId не используется, так что
	 * ничего не сломалось. Просто стало честнее.
	 */
	public function item(int $id): Response
	{
		$breadcrumbs = $this->buildBreadcrumbs($id);

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

		// ДЛЯ FILE: показываем само фото
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

		// ДЛЯ ITEM/TREE: загружаем вложенные файлы
		$files = $this->neuronRepo->findChildren($id, 'file');
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
	 * Отдаёт файл для скачивания с оригинальным именем.
	 * [Лорелея]: Здесь права не проверяем — это публичная ссылка.
	 * Если файл в галерее, значит, он уже был кем-то загружен как админ.
	 */
	public function download(int $id): Response
	{
		$file = $this->neuronRepo->findById($id);
		if (!$file || $file['type'] !== 'file') {
			return $this->errorHandler->showError(404, 'Файл не найден', [
				'url'    => $_SERVER['REQUEST_URI'] ?? '/',
				'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
				'user'   => 'guest',
				'time'   => date('Y-m-d H:i:s'),
			]);
		}

		$fileData = is_string($file['data'] ?? null)
			? json_decode($file['data'], true)
			: ($file['data'] ?? []);

		$storagePath = $this->galleryUploadDir . '/' . ($fileData['storage_path'] ?? '');
		$originalName = $fileData['original_name'] ?? 'download';

		if (!file_exists($storagePath)) {
			return $this->errorHandler->showError(404, 'Файл не найден на диске', [
				'url'    => $_SERVER['REQUEST_URI'] ?? '/',
				'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
				'user'   => 'guest',
				'time'   => date('Y-m-d H:i:s'),
			]);
		}

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
	 * [Мириам]: Добавлен requireCsrf. Раньше любой сайт мог
	 * отправить форму от имени админа и загрузить файл.
	 * Теперь — только со своим CSRF-токеном.
	 */
	public function upload(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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
	 * [Мириам]: Добавлен requireCsrf. То же, что и в upload.
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
	 * [Мириам]: Добавлен requireCsrf. Это обновление текста и data.
	 * Без CSRF любой сайт мог переписать название работы.
	 */
	public function updateItem(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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
	 * [Мириам]: Добавлен requireCsrf. Раньше DELETE проходил
	 * без защиты. Теперь — нет.
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

			// [Лорелея]: Логируем неудачные unlink. Если файл не удалился —
			// узнаем об этом из error.log, а не будем гадать.
			if (!empty($fileData['storage_path'])) {
				$path = $this->galleryUploadDir . '/' . $fileData['storage_path'];
				if (file_exists($path) && !unlink($path)) {
					error_log("[Gallery] Failed to unlink storage: {$path}");
				}
			}

			if (!empty($fileData['thumb_path'])) {
				$path = $this->galleryUploadDir . '/' . $fileData['thumb_path'];
				if (file_exists($path) && !unlink($path)) {
					error_log("[Gallery] Failed to unlink thumb: {$path}");
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
	 * [Лорелея]: С этого метода началось наше исправление безопасности.
	 * Здесь уже был CSRF, но теперь он ещё и через requireCsrf() —
	 * единообразно с остальными методами.
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
			$path = $this->galleryUploadDir . '/' . $fileData['storage_path'];
			if (file_exists($path) && !unlink($path)) {
				error_log("[Gallery] Failed to unlink storage: {$path}");
			}
		}

		if (!empty($fileData['thumb_path'])) {
			$path = $this->galleryUploadDir . '/' . $fileData['thumb_path'];
			if (file_exists($path) && !unlink($path)) {
				error_log("[Gallery] Failed to unlink thumb: {$path}");
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
	 * [Мириам]: Добавлен requireCsrf. Это POST, меняет данные —
	 * значит, нужен CSRF. Раньше его не было.
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
	 * Общая логика: перемещает файл в хранилище и создаёт file-нейрон.
	 * Используется и saveFile(), и saveFileToItem().
	 *
	 * [Лорелея]: Раньше было две почти одинаковые функции —
	 * saveFile и saveFileToItem. Разница — только в display_name
	 * и text_key. Теперь — одна функция. Меньше дублирования.
	 *
	 * [Мириам]: Тип UploadedFile добавлен сюда тоже. Intelephense
	 * больше не ругается.
	 *
	 * @param UploadedFile $uploadedFile
	 * @param int $parentId
	 * @param int|null $textKey — опционально, для saveFile (импорт с текстом)
	 * @param string|null $displayName — опционально, для saveFile
	 * @return int — id созданного file-нейрона
	 * @throws \RuntimeException если файл не прошёл валидацию
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

		// [Мириам]: uniqid защищает от коллизий. Раньше был только time(),
		// и два файла в одну секунду перезаписывали друг друга.
		$unique = md5($validated['originalName'] . time() . uniqid('', true));
		$storageName = $unique . '.' . $extension;
		$thumbName = null;
		$imageInfo = [];

		$uploadedFile->move($storageDir, $storageName);

		// [Лорелея]: Миниатюру делаем только для изображений.
		// Для видео и аудио — не нужно.
		if (in_array($extension, self::ALLOWED_IMAGE_EXT, true)) {
			$thumbName = $unique . '_thumb.' . $extension;
			$imageInfo = $this->createThumbnail(
				$storageDir . '/' . $storageName,
				$storageDir . '/' . $thumbName
			);
		}

		$data = [
			'original_name' => $validated['originalName'],
			'mime'          => $validated['mime'],
			'size'          => $validated['size'],
			'width'         => $imageInfo['width'] ?? 0,
			'height'        => $imageInfo['height'] ?? 0,
			'storage_path'  => $datePath . '/' . $storageName,
			'thumb_path'    => $thumbName ? ($datePath . '/' . $thumbName) : null,
			'uploaded_at'   => date('Y-m-d H:i:s'),
			'is_video'      => in_array($extension, self::ALLOWED_VIDEO_EXT, true),
			'is_audio'      => in_array($extension, self::ALLOWED_AUDIO_EXT, true),
		];

		if ($displayName !== null) {
			$data['display_name'] = $displayName;
		}

		return $this->neuronRepo->create('file', $data, $parentId, $textKey);
	}

	/**
	 * Сохраняет загруженный файл напрямую в раздел.
	 * [Мириам]: Тип UploadedFile добавлен.
	 */
	private function saveFile(UploadedFile $uploadedFile, int $parentId): int
	{
		$displayName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
		return $this->moveAndCreateFile($uploadedFile, $parentId, null, $displayName);
	}

	/**
	 * Сохраняет файл в существующий элемент.
	 * [Мириам]: Тип UploadedFile добавлен.
	 */
	private function saveFileToItem(UploadedFile $uploadedFile, int $itemId): int
	{
		return $this->moveAndCreateFile($uploadedFile, $itemId);
	}

	// ============================================
	// [Лорелея]: createThumbnail УДАЛЁН ИЗ КЛАССА
	// ============================================
	// Он теперь в ThumbnailTrait. Если оставить здесь —
	// будет конфликт с trait. PHP скажет: «Метод уже определён».
	// Или — trait не подключится. Так что — только в trait.
	//
	// То же самое — в GalleryImportService.
	// ============================================

	/**
	 * Проверяет и создаёт структуру папок для галереи.
	 */
	private function ensureDirectories(): void
	{
		foreach (['', '/_import'] as $dir) {
			$fullPath = $this->galleryUploadDir . $dir;
			if (!is_dir($fullPath)) {
				mkdir($fullPath, 0775, true);
			}
		}
	}

	/**
	 * Инициализирует структуру галереи.
	 */
	private function initGallery(): Response
	{
		$mediaRoot = $this->neuronRepo->findBySlug('MEDIA');
		if (!$mediaRoot) {
			return new Response('MEDIA root not found. Run TREE import first.', 500);
		}

		$this->neuronRepo->create('tree', ['slug' => 'gallery'], $mediaRoot['id']);

		return new Response('', 302, ['Location' => '/gallery']);
	}

	/**
	 * Собирает хлебные крошки от корня галереи до указанного нейрона.
	 */
	private function buildBreadcrumbs(int $id): array
	{
		$breadcrumbs = [];
		$current = $this->neuronRepo->findById($id);

		while ($current) {
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

			if ($current['pid']) {
				$current = $this->neuronRepo->findById($current['pid']);
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
	 * [Мириам]: Добавлен requireCsrf.
	 */
	public function setSectionThumb(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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

		$currentData = is_string($section['data'] ?? null)
			? json_decode($section['data'], true)
			: ($section['data'] ?? []);

		$currentData['cover_thumb'] = $thumbPath;

		$this->neuronRepo->update($id, ['data' => $currentData]);

		return ApiResponse::success(['thumb' => $thumbPath], 'Превьюшка установлена');
	}

	/**
	 * POST /api/gallery/section/create
	 * [Мириам]: Добавлен requireCsrf.
	 */
	public function createSection(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;
		if ($error = $this->requireCsrf($request)) return $error;

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

	// ============================================
	// [Лорелея]: УДАЛЕНЫ МЁРТВЫЕ МЕТОДЫ
	// ============================================
	// isVideoFile(), isAudioExtension(), isImageExtension()
	// больше не используются. Их работу выполняют
	// in_array($ext, self::ALLOWED_*_EXT, true).
	//
	// Удалены, чтобы не путать. Если кто-то увидит метод
	// isVideoFile() — он подумает, что он используется.
	// А он — нет.
	// ============================================
}