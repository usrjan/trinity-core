<?php

/**
 * КОНТРОЛЛЕР ИНСТРУМЕНТОВ
 * ========================
 *
 * Предоставляет веб-интерфейс для работы с инструментами Trinity.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: initAuth() теперь получает SynapseRepository вторым
 * аргументом. Чтобы refreshRoles() работал. А не был декорацией.
 * Без «потом». Без «завтра».
 *
 * [Мириам]: И — это правильно. Потому что роли должны обновляться.
 * Всегда. Даже если пользователь давно вошёл. Даже если роль
 * изменилась. Без «перезагрузи». Без «пиздеца».
 *
 * @package Jan\Trinity\Plugin\Tools
 */

namespace Jan\Trinity\Plugin\Tools;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Plugin\Tools\Handlers\ReportSocialHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class ToolsController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	private Validator $validator;

	/** @var LoggerInterface — логгер */
	private LoggerInterface $logger;

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. initAuth() теперь получает
	 * SynapseRepository вторым аргументом. Чтобы refreshRoles()
	 * работал. А не был декорацией.
	 *
	 * [Мириам]: И — это правильно. Потому что роли должны обновляться.
	 * Всегда. Даже если пользователь давно вошёл. Даже если роль
	 * изменилась. Без «перезагрузи». Без «пиздеца».
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		Validator $validator,
		LoggerInterface $logger,
		SynapseRepository $synapseRepo
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->validator = $validator;
		$this->logger = $logger;

		// [Лорелея]: Передаём SynapseRepository. Чтобы refreshRoles()
		// работал. А не был декорацией.
		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// СТРАНИЦА ИНСТРУМЕНТОВ
	// ============================================

	/**
	 * GET /tools
	 *
	 * Отображает страницу со списком доступных инструментов.
	 * Доступ только для администраторов.
	 *
	 * @return Response — HTML страница
	 */
	public function index(): Response
	{
		$this->requireAdmin();

		$html = $this->twig->render('tools.html.twig');
		return new Response($html);
	}

	// ============================================
	// ОТЧЁТ ПО СОЦСЕТЯМ (ЦУР)
	// ============================================

	/**
	 * POST /api/tools/report-social
	 *
	 * Формирует отчёт по соцсетям на основе загруженных Excel-файлов.
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function reportSocial(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		ini_set('memory_limit', '2056M');
		set_time_limit(0);

		$files = [];
		foreach (['im', 'pos', 'imp'] as $key) {
			$file = $request->files->get($key);
			if ($file && $file->getError() === UPLOAD_ERR_OK) {
				$files[$key] = $file->getPathname();
			}
		}

		$params = [
			'type_out' => $request->request->get('type_out', 'alltheme'),
			'area'     => $request->request->get('area'),
			'to_word'  => $request->request->get('to_word') === '1',
		];

		if (!$this->validator->validate([
			'type_out' => $params['type_out'],
			'area'     => $params['area'],
		], [
			'type_out' => ['required', 'in:alltheme,top5theme,combined,org,chk'],
			'area'     => ['nullable', 'string', 'max:255'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		try {
			$handler = new ReportSocialHandler();
			$data = $handler->process($files, $params);

			$html = $this->twig->render('tool-report-social.html.twig', [
				'data' => $data,
			]);

			return ApiResponse::success([
				'html'      => $html,
				'word_file' => $data['word_file'] ?? null,
			]);

		} catch (\Throwable $e) {
			// [Лорелея]: Убираем error_log(). Используем только логгер.
			$this->logger->error('Tools: ReportSocial error', [
				'message' => $e->getMessage(),
				'file'    => $e->getFile() . ':' . $e->getLine(),
			]);

			$clientMessage = $this->isDebug()
				? 'Ошибка обработки: ' . $e->getMessage()
				: 'Ошибка обработки. Подробности в логах.';

			return ApiResponse::error($clientMessage, 500);
		}
	}

	/**
	 * Режим отладки из .env.
	 *
	 * [Лорелея]: Тот же метод, что и в Kernel, и в AdminController.
	 * Локальный. Потому что ToolsController не наследуется от Kernel.
	 *
	 * [Мириам]: Если появится четвёртое место — вынесем в trait
	 * `DebugAwareTrait`. Пока — трёх достаточно. И — дублирование
	 * в три строки — это не «дублирование». Это — «три строки».
	 *
	 * @return bool
	 */
	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}