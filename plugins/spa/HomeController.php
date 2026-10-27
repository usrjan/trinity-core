<?php

/**
 * КОНТРОЛЛЕР ДОМАШНЕЙ СТРАНИЦЫ И ДИНАМИЧЕСКИХ СТРАНИЦ
 * ====================================================
 *
 * Точка входа для всех не-API запросов.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. RuntimeException заменён на
 * ResourceNotFoundException. Чтобы Kernel ловил как 404. А не 500.
 * Без «пиздеца». Без «потом». Без «завтра».
 *
 * [Мириам]: И — favicon.ico теперь отдаётся как 404 без логирования.
 * Потому что это — статика. А не «страница». И — не «ошибка».
 * Без «пиздеца». И — «навсегда».
 *
 * [Лорелея]: И — initAuth теперь получает SynapseRepository.
 * Чтобы refreshRoles() работал. Без «декорации». Без «потом».
 *
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Twig\Environment;

class HomeController
{
	use AuthMiddleware;

	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	private Validator $validator;

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 *
	 * [Лорелея]: initAuth теперь получает SynapseRepository.
	 * Чтобы refreshRoles() работал. Без «декорации». Без «потом».
	 */
	public function __construct(
		Environment $twig,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		Session $session,
		SynapseRepository $synapseRepo,
		Validator $validator
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->validator = $validator;

		$this->initAuth($session, $synapseRepo);
	}

	/**
	 * Главная или динамическая страница.
	 *
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. RuntimeException заменён
	 * на ResourceNotFoundException. Чтобы Kernel ловил как 404.
	 * А не 500. Без «пиздеца». Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — favicon.ico отдаётся как 404 без логирования.
	 * Потому что это — статика. А не «страница». И — не «ошибка».
	 * Без «пиздеца». И — «навсегда».
	 *
	 * @param string|null $slug — slug страницы (null для главной)
	 * @return Response
	 */
	public function index(?string $slug = null): Response
	{
		// ============================================
		// ГЛАВНАЯ СТРАНИЦА ( / )
		// ============================================
		if (!$slug || $slug === '/') {
			$html = $this->twig->render('base.html.twig', [
				'title'		=> 'Trinity ' . ApiResponse::getVersion(),
				'user_lang'	=> $this->getUserLang(),
				'is_admin'	=> $this->isAdmin(),
			]);
			return new Response($html);
		}

		// ============================================
		// ДИНАМИЧЕСКАЯ СТРАНИЦА ( /{slug} )
		// ============================================
		$slug = trim($slug, '/');

		// [Мириам]: favicon.ico — это статика. Не страница.
		// Отдаём 404 без логирования. Без «пиздеца». Без «потом».
		if ($slug === 'favicon.ico') {
			return new Response('', 404);
		}

		// Ищем нейрон страницы по slug
		$page = $this->neuronRepo->findBySlug($slug);

		// [Лорелея]: ResourceNotFoundException вместо RuntimeException.
		// Чтобы Kernel ловил как 404. А не 500. Без «пиздеца».
		if (!$page) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		// Извлекаем данные нейрона
		$pageData = is_string($page['data'] ?? null)
			? json_decode($page['data'], true)
			: ($page['data'] ?? []);

		// Определяем шаблон (по умолчанию — обычная SPA-страница)
		$template = $pageData['template'] ?? 'page';

		// ============================================
		// СТРАНИЦА С КАРТОЙ (template = "map")
		// ============================================
		if ($template === 'map') {
			return $this->renderMapPage($page, $pageData, $slug);
		}

		// ============================================
		// ОБЫЧНАЯ SPA-СТРАНИЦА
		// ============================================
		return $this->renderSpaPage($page, $slug);
	}

	/**
	 * Рендерит страницу с картой.
	 */
	private function renderMapPage(array $page, array $pageData, string $slug): Response
	{
		$view = $pageData['view'] ?? [];

		$mapVars = [
			'cen_lat' => $view['center'][0] ?? 45.31,
			'cen_lon' => $view['center'][1] ?? 34.63,
			'zoom'    => $view['zoom'] ?? 9,
			'codes'   => $pageData['codes'] ?? null,
			'title'   => $slug,
		];

		if ($page['text']) {
			$text = $this->textRepo->findByKeyAndLang($page['text'], 'ru');
			if ($text && $text['name']) {
				$mapVars['title'] = $text['name'];
			}
		}

		$html = $this->twig->render('map.html.twig', [
			'mapVars'				=> $mapVars,
			'yandex_maps_api_key'	=> $_ENV['YANDEX_MAPS_API_KEY'] ?? '',
			'user_lang'				=> $this->getUserLang(),
			'is_admin'				=> $this->isAdmin(),
		]);

		return new Response($html);
	}

	/**
	 * Рендерит обычную SPA-страницу.
	 */
	private function renderSpaPage(array $page, string $slug): Response
	{
		$title = $slug;

		if ($page['text']) {
			$text = $this->textRepo->findByKeyAndLang($page['text'], 'ru');
			if ($text && $text['name']) {
				$title = $text['name'];
			}
		}

		$html = $this->twig->render('base.html.twig', [
			'title'		=> $title,
			'user_lang'	=> $this->getUserLang(),
			'is_admin'	=> $this->isAdmin(),
		]);

		return new Response($html);
	}

	/**
	 * Определяет язык текущего пользователя.
	 */
	private function getUserLang(): string
	{
		$userId = $this->session->get('user_id');
		if (!$userId) return 'ru';

		$user = $this->neuronRepo->findById($userId);
		if (!$user) return 'ru';

		$userData = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		return $userData['lang'] ?? 'ru';
	}
}