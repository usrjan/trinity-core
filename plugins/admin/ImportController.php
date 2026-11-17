<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Psr\Log\LoggerInterface;

class ImportController
{
	use AuthMiddleware;

	private TextRepository $textRepo;
	private NeuronRepository $neuronRepo;
	private SynapseRepository $synapseRepo;
	private ConfigService $configService;
	private Validator $validator;
	private LoggerInterface $logger;

	public function __construct(
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		ConfigService $configService,
		Validator $validator,
		LoggerInterface $logger
	) {
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->configService = $configService;
		$this->validator = $validator;
		$this->logger = $logger;

		$this->initAuth($session);
	}

	/**
	 * POST /api/admin/import
	 */
	public function import(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

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

	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}