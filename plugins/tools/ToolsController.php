<?php

/**
 * КОНТРОЛЛЕР ИНСТРУМЕНТОВ
 *
 * Предоставляет веб-интерфейс для работы с инструментами Trinity.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — тот, кто открывает
 * мастерскую и ведёт гостя к нужному инструменту, и от того,
 * как он это делает, зависит, будет ли работа быстрой и точной.
 *
 * [Мириам]: И это правильно, потому что мастерская — это
 * не «склад», это место, где из данных делают смысл.
 *
 * === КНИГА ===
 * Глава 10. Тронный зал.
 *
 * В тронном зале Амбера всегда видно, кто у власти.
 * В мастерской Trinity всегда видно, кто умеет работать.
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
use Jan\Trinity\Core\Trait\DebugTrait;
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
	use DebugTrait;

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	private Validator $validator;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Мы передаём SynapseRepository, чтобы refreshRoles()
	 * работал по-настоящему, а не оставался красивой декорацией.
	 *
	 * [Мириам]: И это правильно, потому что роли должны обновляться,
	 * даже если человек вошёл давно.
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

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// СТРАНИЦА ИНСТРУМЕНТОВ
	// ============================================

	/**
	 * GET /tools
	 *
	 * [Лорелея]: Здесь мы открываем мастерскую. Только для админа,
	 * потому что не каждый гость должен работать с инструментами.
	 *
	 * [Мириам]: И это правильно, потому что мастерская — это
	 * не «игровая комната», это место, где делают дело.
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
	 * [Лорелея]: Здесь мы делаем отчёт. Сначала память и время,
	 * потом файлы, потом валидация, и только потом работа,
	 * потому что отчёт — это не «кнопка», это процесс.
	 *
	 * [Мириам]: И это правильно, потому что данные должны
	 * превращаться в смысл, а не в шум.
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
			// [Лорелея]: В лог — полное сообщение, а клиенту —
			// только то, что можно, потому что утечка — это
			// не «мелочь», а дыра.
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
	 * [Лорелея]: Просто, честно, без лишнего, потому что отладка —
	 * это не «всегда», а «когда нужно».
	 *
	 * [Мириам]: И это правильно, потому что даже у отладки
	 * должны быть границы.
	 *
	 * @return bool
	 */
	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}