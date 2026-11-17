<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Spa;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class TopbarController
{
	private Environment $twig;
	private Session $session;

	public function __construct(
		Environment $twig,
		Session $session
	) {
		$this->twig = $twig;
		$this->session = $session;
	}

	/**
	 * GET /api/menu/topbar
	 */
	public function topbar(Request $request): Response
	{
		$user = $this->session->get('user_login');
		$userRoles = $this->session->get('user_roles', []);

		$html = $this->twig->render('topbar.html.twig', [
			'user'       => $user,
			'user_roles' => $userRoles,
		]);

		return new Response($html);
	}
}