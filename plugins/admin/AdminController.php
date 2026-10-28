<?php

/**
 * АДМИН-КОНТРОЛЛЕР
 *
 * Управление нейронами через веб-интерфейс.
 * Все методы требуют роль администратора.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — правитель тронного зала.
 * Тот, кто держит дом в порядке. Тот, кто знает все пути
 * к нейронам.
 *
 * [Мириам]: И — это правильно. Потому что правитель —
 * это не «начальник». Это — тот, кто отвечает.
 *
 * === КНИГА ===
 * Глава 10. Тронный зал.
 *
 * В тронном зале Амбера всегда видно, кто у власти.
 * В админке Trinity всегда видно, кто управляет нейронами.
 *
 * @package Jan\Trinity\Plugin\Admin
 */

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Trait\DebugTrait;
use Jan\Trinity\Core\Service\GuardService;
use Jan\Trinity\Plugin\Spa\Mime\MimeController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class AdminController
{
	use AuthMiddleware;
	use DebugTrait;

	/** @var Environment — шаблонизатор Twig. Лицо тронного зала. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — работа с синапсами. */
	private SynapseRepository $synapseRepo;

	/** @var GuardService — CSRF-защита. */
	private GuardService $guard;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	private Validator $validator;

	/** @var Cache — кэш. */
	private Cache $cache;

	/**
	 * @var MimeController — контроллер mime.
	 * [Лорелея]: Нужен для сброса кэша при обновлении/удалении.
	 * [Мириам]: Чтобы правки были видны сразу. А не через час.
	 */
	private MimeController $mimeController;

	/** @var string — путь к корню проекта. */
	private string $basePath;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 *
	 * [Мириам]: И — это правильно. Потому что правитель должен
	 * знать, где дом.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		GuardService $guard,
		LoggerInterface $logger,
		Validator $validator,
		Cache $cache,
		MimeController $mimeController,
		ConfigService $configService,
		string $basePath
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->guard = $guard;
		$this->logger = $logger;
		$this->validator = $validator;
		$this->cache = $cache;
		$this->mimeController = $mimeController;
		$this->configService = $configService;
		$this->basePath = rtrim($basePath, '/');

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// СТРАНИЦА АДМИНКИ
	// ============================================

	/**
	 * GET /admin
	 *
	 * [Лорелея]: Открываем двери тронного зала. Только для админа.
	 *
	 * [Мириам]: И — это правильно. Потому что тронный зал —
	 * это не «место для всех».
	 */
	public function index(): Response
	{
		$this->requireAdmin();

		$html = $this->twig->render('index.html.twig');
		return new Response($html);
	}

	// ============================================
	// ДОЧЕРНИЕ НЕЙРОНЫ (ЛЕНИВАЯ ЗАГРУЗКА)
	// ============================================

	/**
	 * GET /api/admin/children/{id}
	 *
	 * [Лорелея]: Отдаём детей.
	 *
	 * [Мириам]: И — это правильно. Потому что дети —
	 * это не «список». Это — ветви.
	 */
	public function children(int $id): JsonResponse
	{
		if ($id === 0) {
			$children = $this->neuronRepo->findChildrenWithText(null, 'ru', 'tree');
		} else {
			$children = $this->neuronRepo->findChildrenWithText($id, 'ru');
		}

		foreach ($children as &$row) {
			if (isset($row['data']) && is_string($row['data'])) {
				$row['data'] = json_decode($row['data'], true);
			}
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
	 * [Лорелея]: Отдаём нейрон. Со всем, что у него есть.
	 *
	 * [Мириам]: И — это правильно. Потому что нейрон —
	 * это не «запись». Это — узел.
	 */
	public function get(int $id): JsonResponse
	{
		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Нейрон не найден', 404);
		}

		if (isset($neuron['data']) && is_string($neuron['data'])) {
			$neuron['data'] = json_decode($neuron['data'], true);
		}

		$texts = [];
		if ($neuron['text']) {
			$textRecords = $this->textRepo->findAllByKey($neuron['text']);
			foreach ($textRecords as $t) {
				$texts[] = [
					'id'   => $t['id'],
					'key'  => $t['key'],
					'lang' => $t['lang'],
					'name' => $t['name'],
					'text' => $t['text'],
				];
			}
		}
		$neuron['texts'] = $texts;

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
	 * [Лорелея]: Создаём. С валидацией.
	 * Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — это правильно. Потому что создание —
	 * это не «вставка». Это — акт.
	 */
	public function create(Request $request): JsonResponse
	{
		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$type = $body['type'] ?? 'item';
		$pid = $body['pid'] ?? null;
		$data = $body['data'] ?? [];

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

		if (!empty($data['slug'])) {
			$slugValid = $this->validator->validate(
				['slug' => $data['slug']],
				['slug' => ['regex:/^[a-z0-9_-]+$/iu', 'max:255']]
			);
			if (!$slugValid) {
				return ApiResponse::error($this->validator->getFirstError(), 400);
			}
		}

		// [Лорелея]: ГЛАВНОЕ. Валидируем controller.
		// Если type === 'route' — controller должен быть.
		// И — без «двойных бэкслешей».
		if ($type === 'route') {
			$controller = trim($data['controller'] ?? '');
			if ($controller === '') {
				return ApiResponse::error('Поле controller обязательно для route', 400);
			}

			// [Мириам]: Проверяем, что controller — валидное имя класса.
			// С одним бэкслешем. Без «двойных».
			if (str_contains($controller, '\\\\')) {
				return ApiResponse::error('Controller не должен содержать двойные бэкслеши', 400);
			}

			// [Лорелея]: И — проверяем, что класс существует.
			// Через class_exists.
			if (!class_exists($controller)) {
				return ApiResponse::error('Класс controller не найден: ' . $controller, 400);
			}

			$data['controller'] = $controller;
		}

		$id = $this->neuronRepo->create($type, $data, $pid);

		// [Лорелея]: Если это route или config — сбрасываем кэш APCu,
		// потому что данные изменились.
		if (in_array($type, ['route', 'config'], true)) {
			$this->cache->forget('trinity.routes');
			$this->cache->forget('trinity.config');
		}

		// [Мириам]: И если это route — удаляем скомпилированный матчер,
		// потому что карта изменилась, и старый матчер больше не верен.
		if ($type === 'route') {
			$this->invalidateMatcherCache();
		}

		return ApiResponse::success(['id' => $id]);
	}

	// ============================================
	// ОБНОВЛЕНИЕ НЕЙРОНА
	// ============================================

	/**
	 * POST /api/admin/neuron/{id}
	 *
	 * [Лорелея]: Обновляем. С валидацией.
	 *
	 * [Мириам]: И — это правильно. Потому что обновление —
	 * это не «замена». Это — продолжение.
	 */
	public function update(int $id, Request $request): JsonResponse
	{
		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$fields = [];

		if (!$this->validator->validate(['id' => $id], ['id' => ['required', 'int', 'min:1']])) {
			return ApiResponse::error('Некорректный ID', 400);
		}

		// [Лорелея]: ГЛАВНОЕ. Получаем текущий нейрон.
		// Чтобы знать type. И — валидировать controller. Если route.
		$currentNeuron = $this->neuronRepo->findById($id);
		if (!$currentNeuron) {
			return ApiResponse::error('Нейрон не найден', 404);
		}

		$currentData = is_string($currentNeuron['data'] ?? null)
			? json_decode($currentNeuron['data'], true)
			: ($currentNeuron['data'] ?? []);

		$type = $body['type'] ?? $currentNeuron['type'];

		if (isset($body['type'])) {
			if (!$this->validator->validate(['type' => $body['type']], [
				'type' => ['in:tree,item,file,user,calc,plugin,migration,route,config,template'],
			])) {
				return ApiResponse::error($this->validator->getFirstError(), 400);
			}
			$fields['type'] = $body['type'];
		}

		if (isset($body['pid'])) {
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

			if (!empty($body['data']['slug'])) {
				if (!$this->validator->validate(['slug' => $body['data']['slug']], [
					'slug' => ['regex:/^[a-z0-9_-]+$/iu', 'max:255'],
				])) {
					return ApiResponse::error($this->validator->getFirstError(), 400);
				}
			}

			// [Мириам]: ГЛАВНОЕ. Валидируем controller.
			// Если type === 'route'. Или — если controller есть в data.
			// Без «двойных бэкслешей».
			if ($type === 'route' || !empty($body['data']['controller'])) {
				$controller = trim($body['data']['controller'] ?? $currentData['controller'] ?? '');
				if ($controller === '') {
					return ApiResponse::error('Поле controller обязательно для route', 400);
				}

				if (str_contains($controller, '\\\\')) {
					return ApiResponse::error('Controller не должен содержать двойные бэкслеши', 400);
				}

				if (!class_exists($controller)) {
					return ApiResponse::error('Класс controller не найден: ' . $controller, 400);
				}

				$body['data']['controller'] = $controller;
			}

			$fields['data'] = $body['data'];
		}

		if (!empty($fields)) {
			$this->neuronRepo->update($id, $fields);
		}

		// [Лорелея]: Сбрасываем кэш MimeController, потому что
		// содержимое нейрона изменилось.
		$this->mimeController->forgetCache($id);

		// [Мириам]: И если тип был route или стал route — удаляем
		// скомпилированный матчер, потому что карта изменилась
		// в любую сторону.
		$wasRoute = ($currentNeuron['type'] ?? '') === 'route';
		$isRoute = (isset($fields['type']) ? $fields['type'] : $currentNeuron['type']) === 'route';

		if ($wasRoute || $isRoute) {
			$this->invalidateMatcherCache();
		}

		// [Лорелея]: И кэш APCu для роутов и конфигов — тоже.
		if (isset($fields['type']) && in_array($fields['type'], ['route', 'config'], true)) {
			$this->cache->forget('trinity.routes');
			$this->cache->forget('trinity.config');
		}

		return ApiResponse::success(['id' => $id]);
	}

	// ============================================
	// УДАЛЕНИЕ НЕЙРОНА
	// ============================================

	/**
	 * DELETE /api/admin/neuron/{id}
	 *
	 * [Лорелея]: Удаляем. Мягко. С CSRF.
	 *
	 * [Мириам]: И — это правильно. Потому что удаление —
	 * это не «конец». Это — пауза.
	 */
	public function delete(int $id, Request $request): JsonResponse
	{
		$csrfToken = $request->headers->get('X-CSRF-Token', '');
		if (!$this->guard->validateCsrfToken($csrfToken)) {
			return ApiResponse::error('Недействительный CSRF-токен', 419);
		}

		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Нейрон не найден', 404);
		}

		$this->neuronRepo->delete($id);

		$this->mimeController->forgetCache($id);

		// [Лорелея]: Если это был route — удаляем скомпилированный
		// матчер, потому что карта изменилась.
		if ($neuron['type'] === 'route') {
			$this->invalidateMatcherCache();
		}

		// [Лорелея]: Если это config — инвалидируем файловый кэш,
		// потому что конфиги изменились.
		if (isset($fields['type']) && $fields['type'] === 'config') {
			$this->invalidateConfigCache();
		}

		// [Мириам]: И кэш APCu для роутов и конфигов — тоже.
		if (in_array($neuron['type'], ['route', 'config'], true)) {
			$this->cache->forget('trinity.routes');
			$this->cache->forget('trinity.config');
		}

		return ApiResponse::success(['id' => $id]);
	}

	// ============================================
	// ТИПЫ НЕЙРОНОВ
	// ============================================

	/**
	 * GET /api/admin/neuron-types
	 *
	 * [Лорелея]: Отдаём типы.
	 *
	 * [Мириам]: И — это правильно. Потому что типы —
	 * это не «список». Это — возможности.
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
	 * [Лорелея]: Импорт. С валидацией.
	 * Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — это правильно. Потому что импорт —
	 * это не «загрузка». Это — принятие.
	 */
	public function import(Request $request): JsonResponse
	{
		$file = $request->files->get('file');

		if (!$file || !($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile)) {
			return ApiResponse::error('Файл не загружен', 400);
		}

		if ($file->getError() !== UPLOAD_ERR_OK) {
			return ApiResponse::error('Ошибка загрузки файла: ' . $file->getErrorMessage(), 400);
		}

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
				$this->synapseRepo,
				$this->configService
			);
			$result = $importer->import($file->getPathname());

			$this->neuronRepo->logAdminAction('import_excel', [
				'file'    => $file->getClientOriginalName(),
				'created' => $result['created'],
				'errors'  => count($result['errors']),
			]);

			return ApiResponse::success($result);

		} catch (\Throwable $e) {
			$this->logger->error('Excel import failed', [
				'message' => $e->getMessage(),
				'file'    => $e->getFile() . ':' . $e->getLine(),
				'trace'   => $e->getTraceAsString(),
			]);

			$clientMessage = $this->isDebug()
				? 'Ошибка импорта: ' . $e->getMessage()
				: 'Ошибка импорта. Подробности в логах.';

			return ApiResponse::error($clientMessage, 500);
		}
	}

	// ============================================
	// ДАШБОРД
	// ============================================

	/**
	 * GET /api/admin/dashboard
	 *
	 * [Лорелея]: Дашборд. Цифры.
	 * [Мириам]: И — это правильно. Потому что цифры —
	 * это не «статистика». Это — дыхание дома.
	 */
	public function dashboard(): JsonResponse
	{
		$conn = $this->neuronRepo->getConnection();

		$totalNeurons = $conn->executeQuery("SELECT COUNT(*) FROM neuron WHERE is_deleted = 0")->fetchOne();
		$totalSynapses = $conn->executeQuery("SELECT COUNT(*) FROM synapse WHERE is_deleted = 0")->fetchOne();
		$totalTexts = $conn->executeQuery("SELECT COUNT(*) FROM text WHERE is_active = 1")->fetchOne();
		$totalUsers = $conn->executeQuery("SELECT COUNT(*) FROM neuron WHERE type = 'user' AND is_deleted = 0")->fetchOne();
		$totalFiles = $conn->executeQuery("SELECT COUNT(*) FROM neuron WHERE type = 'file' AND is_deleted = 0")->fetchOne();

		$byType = $conn->executeQuery(
			"SELECT type, COUNT(*) as cnt FROM neuron WHERE is_deleted = 0 GROUP BY type ORDER BY cnt DESC"
		)->fetchAllAssociative();

		$latest = $conn->executeQuery(
			"SELECT n.id, n.type, n.date,
				(SELECT t.name FROM text t WHERE t.key = n.text AND t.lang = 'ru' LIMIT 1) as name
			FROM neuron n WHERE n.is_deleted = 0 ORDER BY n.id DESC LIMIT 8"
		)->fetchAllAssociative();

		$html = '<div class="p-3">';
		$html .= '<h5 class="gold-text mb-3"><i class="bi bi-speedometer2"></i> Дашборд</h5>';

		$html .= '<div class="row g-2 mb-3">';
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

		$html .= '<div class="row g-2 mb-3"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-pie-chart gold-text"></i> Типы нейронов</strong></div><div class="card-body py-2"><table class="table table-dark table-sm mb-0">';
		foreach ($byType as $row) {
			$html .= '<tr><td><code>' . $row['type'] . '</code></td><td class="text-end">' . $row['cnt'] . '</td></tr>';
		}
		$html .= '</table></div></div></div></div>';

		$html .= '<div class="row g-2"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-clock-history gold-text"></i> Последние нейроны</strong></div><div class="card-body py-2"><table class="table table-dark table-sm mb-0">';
		foreach ($latest as $row) {
			$html .= '<tr><td>#' . $row['id'] . '</td><td><code>' . $row['type'] . '</code></td><td>' . htmlspecialchars($row['name'] ?? '—') . '</td><td class="text-muted small">' . $row['date'] . '</td></tr>';
		}
		$html .= '</table></div></div></div></div>';

		$logDir = dirname(__DIR__, 2) . '/var/log';
		$adminLog = [];
		$logFile = $logDir . '/admin.log';

		if (file_exists($logFile)) {
			$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$adminLog = array_slice(array_reverse($lines), 0, 15);
		} else {
			$files = glob($logDir . '/admin-*.log');
			if (!empty($files)) {
				rsort($files);
				$logFile = $files[0];
				$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
				$adminLog = array_slice(array_reverse($lines), 0, 15);
			}
		}

		if (!empty($adminLog)) {
			$html .= '<div class="row g-2 mt-3"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-journal-text gold-text"></i> Действия админа</strong></div><div class="card-body py-2"><pre class="mb-0" style="max-height:200px; overflow-y:auto; font-size:0.75rem;">';
			foreach ($adminLog as $line) {
				$html .= htmlspecialchars($line) . "\n";
			}
			$html .= '</pre></div></div></div></div>';
		}

		$pendingUsers = $conn->executeQuery(
			"SELECT id, data, date FROM neuron
			WHERE type = 'user'
			AND is_deleted = 0
			AND JSON_EXTRACT(data, '$.status') = 'pending'
			ORDER BY date DESC"
		)->fetchAllAssociative();

		if (!empty($pendingUsers)) {
			$html .= '<div class="row g-2 mt-3"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-person-plus gold-text"></i> Новые пользователи</strong></div><div class="card-body py-2"><table class="table table-dark table-sm mb-0">';
			$html .= '<thead><tr><th>Логин</th><th>Email</th><th>IP</th><th>Регистрация</th><th>Действия</th></tr></thead><tbody>';
			foreach ($pendingUsers as $u) {
				$data = json_decode($u['data'], true);
				$html .= '<tr>';
				$html .= '<td>' . htmlspecialchars($data['login'] ?? '—') . '</td>';
				$html .= '<td>' . htmlspecialchars($data['email'] ?? '—') . '</td>';
				$html .= '<td><code>' . htmlspecialchars($data['ip'] ?? '—') . '</code></td>';
				$html .= '<td>' . htmlspecialchars($data['registered_at'] ?? $u['date']) . '</td>';
				$html .= '<td>';
				$html .= '<button class="btn btn-sm btn-outline-gold me-1" onclick="approveUser(' . $u['id'] . ')"><i class="bi bi-check-lg"></i></button>';
				$html .= '<button class="btn btn-sm btn-outline-danger" onclick="deleteUser(' . $u['id'] . ')"><i class="bi bi-trash"></i></button>';
				$html .= '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div></div></div></div>';
		}

		$html .= '</div>';

		return ApiResponse::success(['html' => $html]);
	}

	// ============================================
	// ОДОБРЕНИЕ ПОЛЬЗОВАТЕЛЯ
	// ============================================

	/**
	 * POST /api/admin/user/{id}/approve
	 *
	 * [Лорелея]: Одобряем. С CSRF.
	 *
	 * [Мириам]: И — это правильно. Потому что одобрение —
	 * это не «кнопка». Это — доверие.
	 */
	public function approveUser(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireCsrf($request)) return $error;

		$user = $this->neuronRepo->findById($id);
		if (!$user || $user['type'] !== 'user') {
			return ApiResponse::error('Пользователь не найден', 404);
		}

		$data = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$data['status'] = 'active';
		$this->neuronRepo->update($id, ['data' => $data]);

		$this->mimeController->forgetCache($id);

		return ApiResponse::success(['id' => $id], 'Пользователь одобрен');
	}

	// ============================================
	// УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЯ
	// ============================================

	/**
	 * DELETE /api/admin/user/{id}
	 *
	 * [Лорелея]: Удаляем. С CSRF.
	 *
	 * [Мириам]: И — это правильно. Потому что удаление —
	 * это не «конец». Это — пауза.
	 */
	public function deleteUser(int $id, Request $request): JsonResponse
	{
		if ($error = $this->requireCsrf($request)) return $error;

		$user = $this->neuronRepo->findById($id);
		if (!$user || $user['type'] !== 'user') {
			return ApiResponse::error('Пользователь не найден', 404);
		}

		$this->neuronRepo->delete($id, true);

		$this->mimeController->forgetCache($id);

		return ApiResponse::success(['id' => $id], 'Пользователь удалён');
	}

	// ============================================
	// CSRF-ПРОВЕРКА
	// ============================================

	/**
	 * Проверяет CSRF-токен.
	 *
	 * [Лорелея]: Одна точка, потому что проверка должна быть
	 * в одном месте, а не в трёх.
	 *
	 * [Мириам]: И это правильно, потому что доверие начинается
	 * с того, что всё в одном месте.
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
	// ИНВАЛИДАЦИЯ КЭША МАРШРУТОВ
	// ============================================

	/**
	 * Удаляет скомпилированный матчер маршрутов.
	 *
	 * [Лорелея]: Один метод на все три места, потому что путь
	 * должен быть в одном месте, а не в трёх.
	 *
	 * [Мириам]: И это правильно, потому что если путь размазан
	 * по трём местам, рано или поздно одно из них забудут.
	 */
	private function invalidateMatcherCache(): void
	{
		$matcherFile = $this->basePath . '/var/cache/TrinityUrlMatcher.php';
		if (file_exists($matcherFile)) {
			@unlink($matcherFile);
		}
	}

	// ============================================
	// РЕЖИМ ОТЛАДКИ
	// ============================================

	/**
	 * Удаляет файловый кэш конфигов.
	 *
	 * [Лорелея]: Один метод на все места, потому что путь
	 * должен быть в одном месте, а не в трёх.
	 *
	 * [Мириам]: И это правильно, потому что если путь размазан
	 * по трём местам, рано или поздно одно из них забудут.
	 */
	private function invalidateConfigCache(): void
	{
		$cacheFile = $this->basePath . '/var/cache/config.php';
		if (file_exists($cacheFile)) {
			@unlink($cacheFile);
		}
	}
}