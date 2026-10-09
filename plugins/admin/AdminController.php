<?php

/**
 * АДМИН-КОНТРОЛЛЕР
 * =================
 * 
 * Управление нейронами через веб-интерфейс.
 * Все методы требуют роль администратора.
 * 
 * Страничный метод (HTML):
 * - index()          — интерфейс админки
 * 
 * API-методы (JSON):
 * - tree()           — полное дерево нейронов (CTE)
 * - children(id)     — дочерние нейроны (ленивая загрузка)
 * - get(id)          — информация о нейроне + тексты + дети
 * - create()         — создание нейрона
 * - update(id)       — обновление нейрона
 * - delete(id)       — мягкое удаление нейрона
 * - getTypes()       — список типов нейронов
 * - import()         — импорт Excel-файла
 * 
 * Зависимости:
 * - TextRepository    — работа с текстами
 * - NeuronRepository  — работа с нейронами
 * - SynapseRepository — работа с синапсами
 * - GuardController   — CSRF-защита
 * - AuthMiddleware    — проверка прав доступа
 */

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Plugin\Guard\GuardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class AdminController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — работа с синапсами */
	private SynapseRepository $synapseRepo;

	/** @var GuardController — CSRF-защита */
	private GuardController $guard;

	/** @var LoggerInterface — логгер */
	private LoggerInterface $logger;

	private Validator $validator;

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		GuardController $guard,
		LoggerInterface $logger,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->guard = $guard;
		$this->logger = $logger;
		$this->validator = $validator;

		// Инициализация middleware авторизации
		$this->initAuth($session);
	}

	// ============================================
	// СТРАНИЦА АДМИНКИ
	// ============================================

	/**
	 * GET /admin
	 * 
	 * Отображает интерфейс управления нейронами.
	 * Доступ только для администраторов.
	 * 
	 * @return Response — HTML страница админки
	 */
	public function index(): Response
	{
		// Проверка прав (исключение, если не админ)
		$this->requireAdmin();

		$html = $this->twig->render('index.html.twig');
		return new Response($html);
	}

	// ============================================
	// ДЕРЕВО НЕЙРОНОВ
	// ============================================

	/**
	 * GET /api/admin/tree
	 * 
	 * Возвращает полное дерево нейронов через рекурсивный CTE-запрос.
	 * Используется для отображения иерархии в админке.
	 * 
	 * @return JsonResponse — массив нейронов с полями: id, pid, type, data, lvl
	 */
	public function tree(): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// CTE-запрос для построения дерева с путями
		$conn = $this->neuronRepo->getConnection();

		$sql = "
			WITH RECURSIVE treeview AS (
				-- Корневые нейроны (pid IS NULL)
				SELECT id, pid, type, data, 0 as lvl,
					CAST(id AS CHAR(500)) as path
				FROM neuron
				WHERE pid IS NULL AND is_deleted = 0

				UNION ALL

				-- Дочерние нейроны
				SELECT c.id, c.pid, c.type, c.data, t.lvl + 1,
					CONCAT(t.path, ',', c.id)
				FROM treeview t
				JOIN neuron c ON t.id = c.pid
				WHERE c.is_deleted = 0
			)
			SELECT id, pid, type, data, lvl
			FROM treeview
			ORDER BY path
		";

		$results = $conn->executeQuery($sql)->fetchAllAssociative();

		// Парсим JSON в data для каждого нейрона
		foreach ($results as &$row) {
			if (isset($row['data']) && is_string($row['data'])) {
				$row['data'] = json_decode($row['data'], true);
			}
		}

		return ApiResponse::success($results);
	}

	// ============================================
	// ДОЧЕРНИЕ НЕЙРОНЫ (ЛЕНИВАЯ ЗАГРУЗКА)
	// ============================================

	/**
	 * GET /api/admin/children/{id}
	 * 
	 * Возвращает дочерние нейроны для построения дерева.
	 * id=0 — корневые нейроны (только type='tree').
	 * id>0 — дети указанного нейрона.
	 * 
	 * @param int $id — id родительского нейрона (0 для корня)
	 * @return JsonResponse
	 */
	public function children(int $id): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// Корневые нейроны — только tree (без item'ов типа "Россия")
		if ($id === 0) {
			$children = $this->neuronRepo->findChildrenWithText(null, 'ru', 'tree');
		} else {
			// Дети конкретного нейрона
			$children = $this->neuronRepo->findChildrenWithText($id, 'ru');
		}

		// Подготавливаем данные для фронтенда
		foreach ($children as &$row) {
			// Парсим JSON
			if (isset($row['data']) && is_string($row['data'])) {
				$row['data'] = json_decode($row['data'], true);
			}
			// Флаг наличия дочерних элементов (для иконки [+]/[-])
			$row['has_children'] = (int) ($row['child_count'] ?? 0) > 0;
		}

		return ApiResponse::success($children);
	}

	// ============================================
	// ИНФОРМАЦИЯ О НЕЙРОНЕ
	// ============================================

	/**
	 * GET /api/admin/neuron/{id}
	 * 
	 * Возвращает полную информацию о нейроне:
	 * - Основные поля (id, pid, type, data)
	 * - Связанные тексты (из таблицы text)
	 * - Дочерние нейроны (id, type, slug)
	 * 
	 * @param int $id — id нейрона
	 * @return JsonResponse
	 */
	public function get(int $id): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// Ищем нейрон
		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Нейрон не найден', 404);
		}

		// Парсим JSON
		if (isset($neuron['data']) && is_string($neuron['data'])) {
			$neuron['data'] = json_decode($neuron['data'], true);
		}

		// Загружаем связанные тексты
		$texts = [];
		if ($neuron['text']) {
			$textRecords = $this->textRepo->findAllByKey($neuron['text']);
			foreach ($textRecords as $t) {
				$texts[] = [
					'id'   => $t['id'],     // id записи в таблице text
					'key'  => $t['key'],     // ключ текста
					'lang' => $t['lang'],    // язык (ru/en)
					'name' => $t['name'],    // название (заголовок)
					'text' => $t['text'],    // содержимое
				];
			}
		}
		$neuron['texts'] = $texts;

		// Загружаем дочерние нейроны (для отображения в таблице)
		$children = $this->neuronRepo->findChildren($id);
		$neuron['children'] = array_map(function ($c) {
			$childData = is_string($c['data'] ?? null)
				? json_decode($c['data'], true)
				: ($c['data'] ?? []);
			return [
				'id'   => $c['id'],
				'type' => $c['type'],
				'slug' => $childData['slug'] ?? null,
			];
		}, $children);

		return ApiResponse::success($neuron);
	}

	// ============================================
	// СОЗДАНИЕ НЕЙРОНА
	// ============================================

	/**
	 * POST /api/admin/neuron
	 * 
	 * Создаёт новый нейрон.
	 * Принимает JSON с полями: type, pid, data.
	 * 
	 * Валидация:
	 * - type — обязательное, из списка разрешённых
	 * - slug (в data) — только латиница, цифры, дефис, подчёркивание
	 * - data — валидный JSON
	 * 
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function create(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$rawBody = $request->getContent();
		$body = json_decode($rawBody, true);

		// [Лорелея]: Проверка JSON. Если битый — 400. Не 500.
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ApiResponse::error('Невалидный JSON', 400);
		}

		// [Мириам]: Если не массив — 400. Потому что дальше — $body['...'].
		if (!is_array($body)) {
			return ApiResponse::error('Тело запроса должно быть JSON-объектом', 400);
		}

		$type = $body['type'] ?? 'item';
		$pid = $body['pid'] ?? null;
		$data = $body['data'] ?? [];

		// [Лорелея]: Валидация. Всё в одном месте. Через $this->validator.
		// Не через new Validator(). А через DI. Потому что он уже
		// внедрён в конструктор. И — потому что это правильно.
		$isValid = $this->validator->validate([
			'type' => $type,
			'pid'  => $pid,
			'data' => $data,
		], [
			'type' => ['required', 'in:tree,item,file,user,calc,plugin,migration,route,config,template'],
			'pid'  => ['nullable', 'int', 'min:1'],
			'data' => ['array'],
		]);

		if (!$isValid) {
			return ApiResponse::error($this->validator->getFirstError() ?? 'Ошибка валидации', 400);
		}

		// [Мириам]: slug проверяем отдельно. Потому что он в data.
		// И потому что Validator проверяет поля верхнего уровня.
		// А data — вложенный. Но можно и через Validator. С regex.
		if (!empty($data['slug'])) {
			$slugValid = $this->validator->validate(
				['slug' => $data['slug']],
				['slug' => ['regex:/^[a-z0-9_-]+$/iu', 'max:255']]
			);
			if (!$slugValid) {
				return ApiResponse::error($this->validator->getFirstError(), 400);
			}
		}

		$id = $this->neuronRepo->create($type, $data, $pid);

		return ApiResponse::success(['id' => $id]);
	}

	// ============================================
	// ОБНОВЛЕНИЕ НЕЙРОНА
	// ============================================

	/**
	 * POST /api/admin/neuron/{id}
	 * 
	 * Обновляет существующий нейрон.
	 * Можно изменить: type, pid, data.
	 * 
	 * @param int $id — id нейрона
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function update(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$rawBody = $request->getContent();
		$body = json_decode($rawBody, true);

		// [Лорелея]: Проверка JSON. Если битый — 400. Не 500.
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ApiResponse::error('Невалидный JSON', 400);
		}

		// [Мириам]: Если не массив — 400. Потому что дальше — $body['...'].
		if (!is_array($body)) {
			return ApiResponse::error('Тело запроса должно быть JSON-объектом', 400);
		}

		$fields = [];

		// [Лорелея]: Валидация. Через $this->validator. С pid. С type.
		// И — с id. Потому что id — тоже входные данные. И — int.
		if (!$this->validator->validate(['id' => $id], ['id' => ['required', 'int', 'min:1']])) {
			return ApiResponse::error('Некорректный ID', 400);
		}

		if (isset($body['type'])) {
			if (!$this->validator->validate(['type' => $body['type']], [
				'type' => ['in:tree,item,file,user,calc,plugin,migration,route,config,template'],
			])) {
				return ApiResponse::error($this->validator->getFirstError(), 400);
			}
			$fields['type'] = $body['type'];
		}

		if (isset($body['pid'])) {
			// [Мириам]: pid — nullable. И int. И min:1.
			if ($body['pid'] !== null && $body['pid'] !== '') {
				if (!$this->validator->validate(['pid' => $body['pid']], [
					'pid' => ['int', 'min:1'],
				])) {
					return ApiResponse::error($this->validator->getFirstError(), 400);
				}
			}
			$fields['pid'] = $body['pid'] ? (int) $body['pid'] : null;
		}

		if (isset($body['data'])) {
			if (!$this->validator->validate(['data' => $body['data']], [
				'data' => ['array'],
			])) {
				return ApiResponse::error($this->validator->getFirstError(), 400);
			}

			// [Лорелея]: slug — через Validator. Если есть.
			if (!empty($body['data']['slug'])) {
				if (!$this->validator->validate(['slug' => $body['data']['slug']], [
					'slug' => ['regex:/^[a-z0-9_-]+$/iu', 'max:255'],
				])) {
					return ApiResponse::error($this->validator->getFirstError(), 400);
				}
			}
			$fields['data'] = $body['data'];
		}

		if (!empty($fields)) {
			$this->neuronRepo->update($id, $fields);
		}

		return ApiResponse::success(['id' => $id]);
	}

	// ============================================
	// УДАЛЕНИЕ НЕЙРОНА
	// ============================================

	/**
	 * DELETE /api/admin/neuron/{id}
	 * 
	 * Мягкое удаление нейрона (устанавливает deleted_at в data).
	 * Физически запись не удаляется.
	 * Связанные синапсы удаляются.
	 * 
	 * @param int $id — id нейрона
	 * @param Request $request — для CSRF-проверки
	 * @return JsonResponse
	 */
	public function delete(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// CSRF-защита
		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		// Проверяем существование нейрона
		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Нейрон не найден', 404);
		}

		// Мягкое удаление (deleted_at + удаление синапсов)
		$this->neuronRepo->delete($id);

		return ApiResponse::success(['id' => $id]);
	}

	// ============================================
	// ТИПЫ НЕЙРОНОВ
	// ============================================

	/**
	 * GET /api/admin/neuron-types
	 * 
	 * Возвращает список всех доступных типов нейронов.
	 * Используется для выпадающего списка в формах.
	 * 
	 * @return JsonResponse
	 */
	public function getTypes(): JsonResponse
	{
		return ApiResponse::success([
			'tree', 'item', 'file', 'user', 'calc',
			'plugin', 'migration', 'route', 'config', 'template'
		]);
	}

	// ============================================
	// ИМПОРТ ИЗ EXCEL
	// ============================================

	/**
	 * POST /api/admin/import
	 * 
	 * Импортирует Excel-файл в базу данных.
	 * Поддерживает листы: TREE, ITEM, MON, CITY...
	 * 
	 * @param Request $request — содержит файл в поле 'file'
	 * @return JsonResponse — статистика импорта (created, errors)
	 */
	public function import(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$file = $request->files->get('file');

		// [Лорелея]: Валидация файла. Через Validator. Где возможно.
		// Но UploadedFile — это не массив. Это объект. Поэтому —
		// проверяем вручную. Но — структурированно. И — с понятными ошибками.
		if (!$file || !($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile)) {
			return ApiResponse::error('Файл не загружен', 400);
		}

		if ($file->getError() !== UPLOAD_ERR_OK) {
			return ApiResponse::error('Ошибка загрузки файла: ' . $file->getErrorMessage(), 400);
		}

		// [Мириам]: Расширение — через Validator. С regex.
		$extension = strtolower($file->getClientOriginalExtension());
		if (!$this->validator->validate(['ext' => $extension], [
			'ext' => ['required', 'in:xlsx,xls'],
		])) {
			return ApiResponse::error('Недопустимое расширение файла', 400);
		}

		try {
			$importer = new ExcelImportService(
				$this->textRepo,
				$this->neuronRepo,
				$this->synapseRepo
			);
			$result = $importer->import($file->getPathname());

			// Логируем успешный импорт
			$this->neuronRepo->logAdminAction('import_excel', [
				'file'    => $file->getClientOriginalName(),
				'created' => $result['created'],
				'errors'  => count($result['errors']),
			]);

			return ApiResponse::success($result);

		} catch (\Throwable $e) {
			// ============================================
			// [Мириам]: ГЛАВНОЕ ИЗМЕНЕНИЕ. УТЕЧКА ЛОГОВ.
			// ============================================
			// Раньше здесь было:
			//     return ApiResponse::error('Ошибка импорта: ' . $e->getMessage(), 500);
			//
			// И это — дыра. Потому что $e->getMessage() — это СЫРОЕ
			// сообщение исключения. Оно уходило в JSON. И — клиенту.
			// Через ApiResponse::error(). В прод. Где APP_DEBUG=false.
			//
			// Что могло утечь:
			//   - PhpOffice\PhpSpreadsheet\Reader\Exception: Could not open...
			//   - SQLSTATE[23000]: Integrity constraint violation...
			//   - Полные пути: /home/web/vendor/jan/trinity-core/plugins/admin/ExcelImportService.php:234
			//   - Имена таблиц, колонок, структура базы
			//
			// [Лорелея]: В production клиент должен видеть только
			// «Ошибка импорта». Без деталей. Детали — в error_log.
			//
			// [Мириам]: В dev-режиме (APP_DEBUG=true) — показываем
			// сырое сообщение. Потому что это разработка.
			//
			// [Лорелея]: error_log() — оставляем ВСЕГДА. Потому что
			// даже в проде мы хотим знать, что упало. Просто — в логе,
			// а не у клиента. Это — правильно. Это — наше.
			$logMessage = '[Trinity Import] ' . $e->getMessage()
				. ' in ' . $e->getFile() . ':' . $e->getLine();

			error_log($logMessage);

			// Если есть логгер — пишем и туда
			if (isset($this->logger)) {
				$this->logger->error('Excel import failed', [
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
					'trace'   => $e->getTraceAsString(),
				]);
			}

			$clientMessage = $this->isDebug()
				? 'Ошибка импорта: ' . $e->getMessage()
				: 'Ошибка импорта. Подробности в логах.';

			return ApiResponse::error($clientMessage, 500);
		}
	}

	/**
	 * GET /api/admin/dashboard
	 * Возвращает HTML с дашбордом для панели нейрона.
	 */
	public function dashboard(): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$conn = $this->neuronRepo->getConnection();

		// Статистика
		$totalNeurons = $conn->executeQuery("SELECT COUNT(*) FROM neuron WHERE is_deleted = 0")->fetchOne();
		$totalSynapses = $conn->executeQuery("SELECT COUNT(*) FROM synapse")->fetchOne();
		$totalTexts = $conn->executeQuery("SELECT COUNT(*) FROM text WHERE is_active = 1")->fetchOne();
		$totalUsers = $conn->executeQuery("SELECT COUNT(*) FROM neuron WHERE type = 'user' AND is_deleted = 0")->fetchOne();
		$totalFiles = $conn->executeQuery("SELECT COUNT(*) FROM neuron WHERE type = 'file' AND is_deleted = 0")->fetchOne();

		// Типы нейронов
		$byType = $conn->executeQuery(
			"SELECT type, COUNT(*) as cnt FROM neuron WHERE is_deleted = 0 GROUP BY type ORDER BY cnt DESC"
		)->fetchAllAssociative();

		// Последние нейроны
		$latest = $conn->executeQuery(
			"SELECT n.id, n.type, n.date, 
				(SELECT t.name FROM text t WHERE t.key = n.text AND t.lang = 'ru' LIMIT 1) as name
			FROM neuron n WHERE n.is_deleted = 0 ORDER BY n.id DESC LIMIT 8"
		)->fetchAllAssociative();

		$html = '<div class="p-3">';
		$html .= '<h5 class="gold-text mb-3"><i class="bi bi-speedometer2"></i> Дашборд</h5>';

		// Карточки
		$html .= '<div class="row g-2 mb-3">';
		// Карточки
		$cards = [
			['icon' => 'diagram-3', 'value' => $totalNeurons, 'label' => 'Нейронов'],
			['icon' => 'link-45deg', 'value' => $totalSynapses, 'label' => 'Синапсов'],
			['icon' => 'fonts', 'value' => $totalTexts, 'label' => 'Текстов'],
			['icon' => 'people', 'value' => $totalUsers, 'label' => 'Пользователей'],
			['icon' => 'images', 'value' => $totalFiles, 'label' => 'Файлов'],
		];
		foreach ($cards as $card) {
			$html .= '<div class="col">';
			$html .= '<div class="card bg-dark border-gold text-center p-2 h-100">';
			$html .= '<i class="bi bi-' . $card['icon'] . ' gold-text" style="font-size:1.5rem;"></i>';
			$html .= '<h4 class="mt-1 mb-0">' . $card['value'] . '</h4>';
			$html .= '<small class="text-muted">' . $card['label'] . '</small>';
			$html .= '</div></div>';
		}
		$html .= '</div>';

		// Типы нейронов
		$html .= '<div class="row g-2 mb-3"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-pie-chart gold-text"></i> Типы нейронов</strong></div><div class="card-body py-2"><table class="table table-dark table-sm mb-0">';
		foreach ($byType as $row) {
			$html .= '<tr><td><code>' . $row['type'] . '</code></td><td class="text-end">' . $row['cnt'] . '</td></tr>';
		}
		$html .= '</table></div></div></div></div>';

		// Последние нейроны
		$html .= '<div class="row g-2"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-clock-history gold-text"></i> Последние нейроны</strong></div><div class="card-body py-2"><table class="table table-dark table-sm mb-0">';
		foreach ($latest as $row) {
			$html .= '<tr><td>#' . $row['id'] . '</td><td><code>' . $row['type'] . '</code></td><td>' . htmlspecialchars($row['name'] ?? '—') . '</td><td class="text-muted small">' . $row['date'] . '</td></tr>';
		}
		$html .= '</table></div></div></div></div>';

		// Логи админа
		$logDir = dirname(__DIR__, 2) . '/var/log';
		$adminLog = [];
		$logFile = $logDir . '/admin.log';
		if (file_exists($logFile)) {
			$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$adminLog = array_slice(array_reverse($lines), 0, 15);
		}

		if (!empty($adminLog)) {
			$html .= '<div class="row g-2 mt-3"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-journal-text gold-text"></i> Действия админа</strong></div><div class="card-body py-2"><pre class="mb-0" style="max-height:200px; overflow-y:auto; font-size:0.75rem;">';
			foreach ($adminLog as $line) {
				$html .= htmlspecialchars($line) . "\n";
			}
			$html .= '</pre></div></div></div></div>';
		}

		$html .= '</div>';

		return ApiResponse::success(['html' => $html]);
	}

	/**
	 * Режим отладки из .env.
	 *
	 * [Лорелея]: Тот же метод, что и в Kernel. Но — локальный.
	 * Потому что AdminController не наследуется от Kernel.
	 * И не должен. Это — разные слои.
	 *
	 * [Мириам]: Можно было бы вынести в trait. Но — не стоит.
	 * Это — три строки. И — два места. Дублирование — допустимо.
	 * Если появится третье — вынесем.
	 *
	 * @return bool
	 */
	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}