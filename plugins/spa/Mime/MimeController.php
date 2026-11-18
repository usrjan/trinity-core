<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР MIME
 *
 * Показывает нейрон в том виде, в котором он хочет быть показанным.
 * Через mime, через рендереры, через Registry.
 *
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\Mime\Registry;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\ErrorHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Twig\Environment;

class MimeController
{
	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var Registry — реестр mime-рендереров. */
	private Registry $registry;

	private Cache $cache;

	/** @var ErrorHandlerInterface — портал для страницы ошибки. */
	private ErrorHandlerInterface $errorHandler;

	/**
	 * Конструктор.
	 */
	public function __construct(
		Environment $twig,
		NeuronRepository $neuronRepo,
		TextRepository $textRepo,
		Registry $registry,
		Cache $cache,
		ErrorHandlerInterface $errorHandler
	) {
		$this->twig = $twig;
		$this->neuronRepo = $neuronRepo;
		$this->textRepo = $textRepo;
		$this->registry = $registry;
		$this->cache = $cache;
		$this->errorHandler = $errorHandler;
	}

	/**
	 * GET /mime/{mime}/{id}
	 * GET /api/mime/{mime}/{id}
	 *
	 * @param string $mime
	 * @param int $id
	 * @param Request $request
	 * @return Response
	 */
	public function show(string $mime, int $id, Request $request): Response
	{
		$cacheKey = "mime_{$id}_{$mime}";

		$result = $this->cache->remember($cacheKey, function () use ($mime, $id) {
			$neuron = $this->neuronRepo->findById($id);

			if (!$neuron) {
				return null;
			}

			$data = is_string($neuron['data'] ?? null)
				? json_decode($neuron['data'], true)
				: ($neuron['data'] ?? []);

			if (empty($mime)) {
				$mime = $data['mime'] ?? 'text/plain';
			}

			$content = '';
			$html = '';

			if ($neuron['type'] === 'file') {
				$storagePath = $data['storage_path'] ?? '';
				$mimeType = $data['mime'] ?? 'application/octet-stream';
				$galleryUrl = rtrim($_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery', '/');

				if ($storagePath) {
					$fullUrl = $galleryUrl . '/' . $storagePath;

					if (str_starts_with($mimeType, 'image/')) {
						$html = '<img src="' . htmlspecialchars($fullUrl) . '" class="img-fluid" alt="' . htmlspecialchars($data['original_name'] ?? '') . '">';
					} elseif (str_starts_with($mimeType, 'video/')) {
						$html = '<video controls autoplay muted loop class="img-fluid"><source src="' . htmlspecialchars($fullUrl) . '" type="' . htmlspecialchars($mimeType) . '"></video>';
					} elseif (str_starts_with($mimeType, 'audio/')) {
						$html = '<audio controls autoplay class="w-100"><source src="' . htmlspecialchars($fullUrl) . '" type="' . htmlspecialchars($mimeType) . '"></audio>';
					} else {
						$html = '<a href="' . htmlspecialchars($fullUrl) . '" target="_blank" class="btn btn-outline-gold">Скачать файл</a>';
					}
				} else {
					$html = '<div class="alert alert-info">Файл не найден</div>';
				}
			} else {
				if (!empty($neuron['text'])) {
					$text = $this->textRepo->findByKeyAndLang($neuron['text'], 'ru');
					if ($text && !empty($text['text'])) {
						$content = $text['text'];
					}
				}
				if (empty($content) && !empty($data['content'])) {
					$content = $data['content'];
				}

				$html = $this->registry->render($mime, $content, $data);
			}

			return [
				'html'  => $html,
				'mime'  => $mime,
				'title' => $data['title'] ?? ($data['slug'] ?? 'Mime'),
				'id'    => $id,
			];
		}, 3600);

		if ($result === null) {
			return $this->errorHandler->showError(404, 'Нейрон не найден', [
				'url'    => $_SERVER['REQUEST_URI'] ?? '/',
				'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
				'user'   => 'guest',
				'time'   => date('Y-m-d H:i:s'),
			]);
		}

		if (str_starts_with($request->getPathInfo(), '/api/mime/')) {
			return ApiResponse::success([
				'html' => $result['html'],
				'mime' => $result['mime'],
				'id'   => $result['id'],
			]);
		}

		$pageHtml = $this->twig->render('mime.html.twig', [
			'content' => $result['html'],
			'title'   => $result['title'],
			'mime'    => $result['mime'],
			'id'      => $result['id'],
		]);

		return new Response($pageHtml);
	}

	/**
	 * Забывает кэш для нейрона по всем mime.
	 *
	 * @param int $id — id нейрона
	 */
	public function forgetCache(int $id): void
	{
		$this->cache->forgetByPrefix("mime_{$id}_");
	}
}