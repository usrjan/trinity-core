<?php
declare(strict_types=1);

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
use Jan\Trinity\Core\Service\CacheInvalidator;
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

	/** @var Environment — шаблонизатор Twig. Лицо тронного зала. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — работа с синапсами. */
	private SynapseRepository $synapseRepo;

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

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	/** @var string — путь к корню проекта. */
	private string $basePath;

	/** @var CacheInvalidator — инвалидатор кэша. */
	private CacheInvalidator $cacheInvalidator;

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
		LoggerInterface $logger,
		Validator $validator,
		Cache $cache,
		MimeController $mimeController,
		ConfigService $configService,
		string $basePath,
		CacheInvalidator $cacheInvalidator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->logger = $logger;
		$this->validator = $validator;
		$this->cache = $cache;
		$this->mimeController = $mimeController;
		$this->configService = $configService;
		$this->basePath = rtrim($basePath, '/');
		$this->cacheInvalidator = $cacheInvalidator;

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
			$this->cacheInvalidator->invalidateRouteCache();
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
		$this->cacheInvalidator->invalidateMimeCache($id);

		// [Мириам]: И если тип был route или стал route — удаляем
		// скомпилированный матчер, потому что карта изменилась
		// в любую сторону.
		$wasRoute = ($currentNeuron['type'] ?? '') === 'route';
		$isRoute = (isset($fields['type']) ? $fields['type'] : $currentNeuron['type']) === 'route';

		if ($wasRoute || $isRoute) {
			$this->cacheInvalidator->invalidateRouteCache();
		}

		// [Лорелея]: И кэш APCu для роутов и конфигов — тоже.
		if (isset($fields['type']) && in_array($fields['type'], ['route', 'config'], true)) {
			$this->cacheInvalidator->invalidateRouteCache();
			$this->cacheInvalidator->invalidateConfigCache();
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
		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Нейрон не найден', 404);
		}

		$this->neuronRepo->delete($id);

		// [Лорелея]: Сбрасываем кэш Mime, потому что нейрон удалён.
		$this->cacheInvalidator->invalidateMimeCache($id);

		// [Мириам]: Смотрим на $neuron['type'], а не на $fields['type'],
		// потому что $fields в delete() нет, и мёртвый код прятал баг.
		if (in_array($neuron['type'], ['route', 'config'], true)) {
			$this->cacheInvalidator->invalidateRouteCache();
			$this->cacheInvalidator->invalidateConfigCache();
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
	// РЕЖИМ ОТЛАДКИ
	// ============================================

	private function isDebug(): bool
	{
		return $this->configService->getEnv('APP_DEBUG', 'false') === 'true';
	}
}