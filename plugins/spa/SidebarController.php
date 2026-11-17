<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\TextRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class SidebarController
{
	use AuthMiddleware;

	private Environment $twig;
	private NeuronRepository $neuronRepo;
	private TextRepository $textRepo;

	public function __construct(
		Environment $twig,
		Session $session,
		NeuronRepository $neuronRepo,
		TextRepository $textRepo
	) {
		$this->twig = $twig;
		$this->neuronRepo = $neuronRepo;
		$this->textRepo = $textRepo;

		$this->initAuth($session);
	}

	/**
	 * GET /api/menu/sidebar
	 */
	public function sidebar(Request $request): Response
	{
		$lang = $request->query->get('lang', 'ru');
		$currentUrl = $request->query->get('current_url', '/');

		$menu = $this->getMenu($lang);

		$user = $this->session->get('user_login');
		$userRoles = $this->getUserRoles();

		$html = $this->twig->render('sidebar.html.twig', [
			'menu'        => $menu,
			'user'        => $user,
			'user_roles'  => $userRoles,
			'current_url' => $currentUrl,
		]);

		return new Response($html);
	}

	private function getMenu(string $lang = 'ru'): array
	{
		$menuRoot = $this->neuronRepo->findBySlug('MENU');
		if (!$menuRoot) {
			return ['items' => []];
		}

		$public = $this->neuronRepo->findBySlugAndPid('PUBLIC', $menuRoot['id']);
		if (!$public) {
			return ['items' => []];
		}

		$children = $this->neuronRepo->findChildrenWithText($public['id'], $lang);

		$items = [];
		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);

			$items[] = [
				'id'        => $child['id'],
				'name'      => $child['name'] ?? $childData['slug'] ?? 'Без названия',
				'route'     => $childData['route'] ?? '/',
				'icon'      => $childData['icon'] ?? 'bi-file',
				'slug'      => $childData['slug'] ?? '',
				'sort'      => $childData['sort'] ?? 999999,
				'is_plugin' => $childData['is_plugin'] ?? false,
			];
		}

		return ['items' => $items];
	}
}