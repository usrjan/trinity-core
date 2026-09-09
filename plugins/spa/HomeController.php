<?php

/**
 * КОНТРОЛЛЕР ДОМАШНЕЙ СТРАНИЦЫ И ДИНАМИЧЕСКИХ СТРАНИЦ
 * ====================================================
 * 
 * Точка входа для всех не-API запросов.
 * 
 * Обрабатывает:
 * - Главную страницу (/) — SPA-каркас
 * - Динамические страницы (/{slug}) — SPA или карта
 * 
 * Если страница имеет template=map — рендерит карту
 * (map.html.twig) с параметрами из data нейрона.
 * Иначе — отдаёт SPA-каркас (base.html.twig).
 * 
 * Зависимости:
 * - TextRepository — для получения названий страниц
 * - NeuronRepository — для поиска страниц по slug
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Middleware\AuthMiddleware;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class HomeController
{
	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	use AuthMiddleware;

	/**
	 * Конструктор.
	 * Зависимости внедряются автоматически через DI-контейнер.
	 */
	public function __construct(
		Environment $twig,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		Session $session
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->initAuth($session);
	}

	/**
	 * Главная или динамическая страница.
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
				'title'		=> 'Trinity ' . ApiResponse::VERSION,
				'user_lang'	=> $this->getUserLang(),
				'is_admin'	=> $this->isAdmin(),
			]);
			return new Response($html);
		}

		// ============================================
		// ДИНАМИЧЕСКАЯ СТРАНИЦА ( /{slug} )
		// ============================================
		$slug = trim($slug, '/');

		// Ищем нейрон страницы по slug
		$page = $this->neuronRepo->findBySlug($slug);

		if (!$page) {
			return new Response('Not Found', 404);
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
	 * 
	 * Параметры карты берутся из data нейрона:
	 * - view.center — координаты центра
	 * - view.zoom — масштаб
	 * - codes — фильтр для GeoJSON (например, BUILD)
	 * 
	 * @param array $page — нейрон страницы из базы
	 * @param array $pageData — распарсенные data нейрона
	 * @param string $slug — slug страницы
	 * @return Response
	 */
	private function renderMapPage(array $page, array $pageData, string $slug): Response
	{
		// Параметры отображения карты
		$view = $pageData['view'] ?? [];

		$mapVars = [
			'cen_lat' => $view['center'][0] ?? 45.31,   // центр карты: широта
			'cen_lon' => $view['center'][1] ?? 34.63,   // центр карты: долгота
			'zoom'    => $view['zoom'] ?? 9,             // масштаб
			'codes'   => $pageData['codes'] ?? null,     // фильтр объектов (BUILD, ...)
			'title'   => $slug,                          // заголовок (по умолчанию slug)
		];

		// Если у нейрона есть текст — используем его как заголовок
		if ($page['text']) {
			$text = $this->textRepo->findByKeyAndLang($page['text'], 'ru');
			if ($text && $text['name']) {
				$mapVars['title'] = $text['name'];
			}
		}

		// Рендерим шаблон карты (без боковых панелей)
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
	 * 
	 * Отдаёт каркас base.html.twig с заголовком.
	 * Контент загружается асинхронно через SPA-движок.
	 * 
	 * @param array $page — нейрон страницы из базы
	 * @param string $slug — slug страницы
	 * @return Response
	 */
	private function renderSpaPage(array $page, string $slug): Response
	{
		// Заголовок страницы (по умолчанию slug)
		$title = $slug;

		// Если у нейрона есть текст — используем его название
		if ($page['text']) {
			$text = $this->textRepo->findByKeyAndLang($page['text'], 'ru');
			if ($text && $text['name']) {
				$title = $text['name'];
			}
		}

		// Рендерим SPA-каркас
		$html = $this->twig->render('base.html.twig', [
			'title'		=> $title,
			'user_lang'	=> $this->getUserLang(),
			'is_admin'	=> $this->isAdmin(),
		]);

		return new Response($html);
	}

	/**
	 * Определяет язык текущего пользователя.
	 * Если не авторизован — язык по умолчанию 'ru'.
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