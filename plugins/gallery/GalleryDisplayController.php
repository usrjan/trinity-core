<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Gallery;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Trait\BreadcrumbsTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class GalleryDisplayController
{
	use AuthMiddleware;
	use BreadcrumbsTrait;

	private Environment $twig;
	private TextRepository $textRepo;
	private NeuronRepository $neuronRepo;
	private LoggerInterface $logger;

	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		LoggerInterface $logger
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->logger = $logger;

		$this->initAuth($session);
	}

	/**
	 * GET /gallery
	 */
	public function index(): Response
	{
		$galleryRoot = $this->neuronRepo->findBySlug('gallery');
		if (!$galleryRoot) {
			return $this->initGallery();
		}

		$sections = $this->neuronRepo->findChildrenWithText($galleryRoot['id'], 'ru');

		$items = [];
		foreach ($sections as $child) {
			$childData = $child['data'];
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

	/**
	 * GET /api/gallery/section/{id}
	 */
	public function section(int $id): JsonResponse
	{
		$children = $this->neuronRepo->findChildrenWithText($id, 'ru');

		$items = [];
		foreach ($children as $child) {
			$childData = $child['data'];
			$name = $child['name'] ?? $childData['slug'] ?? 'Без названия';

			if ($child['type'] === 'file') {
				$name = $childData['display_name'] ?? $childData['original_name'] ?? $name;
			}

			$items[] = [
				'id'           => $child['id'],
				'type'         => $child['type'],
				'name'         => $name,
				'has_children' => (int) ($child['child_count'] ?? 0) > 0,
			];
		}

		$html = $this->twig->render('gallery-section.html.twig', [
			'items' => $items,
		]);

		return ApiResponse::success(['html' => $html]);
	}

	/**
	 * GET /api/gallery/item/{id}
	 */
	public function item(int $id): Response
	{
		$item = $this->neuronRepo->findById($id);
		if (!$item) {
			return ApiResponse::error('Элемент не найден', 404);
		}

		$itemData = $item['data'];

		$html = $this->twig->render('gallery-item.html.twig', [
			'item'    => $item,
			'isAdmin' => $this->isAdmin(),
		]);

		return ApiResponse::success(['html' => $html]);
	}

	private function initGallery(): Response
	{
		$mediaRoot = $this->neuronRepo->findBySlug('MEDIA');
		if (!$mediaRoot) {
			return new Response('MEDIA root not found. Run TREE import first.', 500);
		}

		$this->neuronRepo->create('tree', ['slug' => 'gallery'], $mediaRoot['id']);

		return new RedirectResponse('/gallery');
	}
}