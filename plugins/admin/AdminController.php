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
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Plugin\Guard\GuardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

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
		GuardController $guard
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->guard = $guard;

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

		$body = json_decode($request->getContent(), true);
		$type = $body['type'] ?? 'item';
		$pid = $body['pid'] ?? null;
		$data = $body['data'] ?? [];

		// Валидация типа и JSON
		$validator = new Validator();
		$isValid = $validator->validate([
			'type' => $type,
			'data' => json_encode($data),
		], [
			'type' => ['required', 'in:tree,item,file,user,calc,plugin,migration,route,config,template'],
			'data' => ['json'],
		]);

		if (!$isValid) {
			return ApiResponse::error(implode('; ', $validator->getErrors()), 400);
		}

		// Валидация slug (если указан)
		if (!empty($data['slug']) && !preg_match('/^[a-z0-9_-]+$/i', $data['slug'])) {
			return ApiResponse::error(
				'Slug должен содержать только латиницу, цифры, дефис и подчёркивание',
				400
			);
		}

		// Создаём нейрон
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

		$body = json_decode($request->getContent(), true);
		$fields = [];

		// Валидация типа (если передан)
		if (isset($body['type'])) {
			$validator = new Validator();
			$isValid = $validator->validate(['type' => $body['type']], [
				'type' => ['in:tree,item,file,user,calc,plugin,migration,route,config,template'],
			]);
			if (!$isValid) {
				return ApiResponse::error(implode('; ', $validator->getErrors()), 400);
			}
			$fields['type'] = $body['type'];
		}

		// Изменение родителя
		if (isset($body['pid'])) {
			$fields['pid'] = $body['pid'] ? (int) $body['pid'] : null;
		}

		// Изменение data
		if (isset($body['data'])) {
			// Валидация slug (если есть в data)
			if (!empty($body['data']['slug']) && !preg_match('/^[a-z0-9_-]+$/i', $body['data']['slug'])) {
				return ApiResponse::error(
					'Slug должен содержать только латиницу, цифры, дефис и подчёркивание',
					400
				);
			}
			$fields['data'] = $body['data'];
		}

		// Применяем изменения
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
		if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
			return ApiResponse::error('Файл не загружен', 400);
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
			error_log('[Trinity Import] ' . $e->getMessage());
			return ApiResponse::error('Ошибка импорта: ' . $e->getMessage(), 500);
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
}