<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР ИНСТРУМЕНТОВ
 *
 * Предоставляет веб-интерфейс для работы с инструментами Trinity.
 *
 * @package Jan\Trinity\Plugin\Tools
 */

namespace Jan\Trinity\Plugin\Tools;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\ConfigService;
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

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	private Validator $validator;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/** @var SynapseRepository — работа с синапсами. */
	private SynapseRepository $synapseRepo;

	private ConfigService $configService;

	/** @var ReportSocialHandler — обработчик отчёта. */
	private ReportSocialHandler $reportSocialHandler;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		Validator $validator,
		LoggerInterface $logger,
		SynapseRepository $synapseRepo,
		ConfigService $configService,
		ReportSocialHandler $reportSocialHandler
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->validator = $validator;
		$this->logger = $logger;
		$this->configService = $configService;
		$this->reportSocialHandler = $reportSocialHandler;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// СТРАНИЦА ИНСТРУМЕНТОВ
	// ============================================

	/**
	 * GET /tools
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
			$data = $this->reportSocialHandler->process($files, $params);

			$html = $this->twig->render('tool-report-social.html.twig', [
				'data' => $data,
			]);

			return ApiResponse::success([
				'html'      => $html,
				'word_file' => $data['word_file'] ?? null,
			]);

		} catch (\Throwable $e) {
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
	 * @return bool
	 */
	private function isDebug(): bool
	{
		return $this->configService->getEnv('APP_DEBUG', 'false') === 'true';
	}
}