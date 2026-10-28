<?php

/**
 * КОНТРОЛЛЕР ДОМАШНЕЙ СТРАНИЦЫ И ДИНАМИЧЕСКИХ СТРАНИЦ
 *
 * Точка входа для всех не-API запросов.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — тот, кто встречает
 * гостя на пороге. И от того, как он это делает, зависит,
 * захочется ли гостю войти.
 *
 * [Мириам]: И это правильно, потому что порог — это не «дверь»,
 * это первое, что чувствует гость, и он должен чувствовать тепло.
 *
 * === КНИГА ===
 * Глава 13. Прихожая.
 *
 * Войдя в Амбер, ты видишь коридоры.
 * Войдя в Trinity, ты видишь меню.
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

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	private Validator $validator;

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
	 * [Лорелея]: Если гость пришёл на главную, мы встречаем его
	 * порогом. Если он пришёл на страницу, которой нет, мы
	 * не бросаем исключение, а говорим об этом через тот же
	 * портал, что и все остальные ошибки.
	 *
	 * [Мириам]: И favicon.ico мы отдаём как 404 без логирования,
	 * потому что это статика, а не страница, и путать одно
	 * с другим было бы нечестно.
	 *
	 * @param string|null $slug — slug страницы (null для главной)
	 * @return Response
	 */
	public function index(?string $slug = null): Response
	{
		// [Лорелея]: Главная страница. Здесь мы просто открываем
		// порог и ждём, когда гость войдёт.
		if (!$slug || $slug === '/') {
			$html = $this->twig->render('base.html.twig', [
				'title'     => 'Trinity ' . ApiResponse::getVersion(),
				'user_lang' => $this->getUserLang(),
				'is_admin'  => $this->isAdmin(),
			]);
			return new Response($html);
		}

		// [Мириам]: Динамическая страница. Сначала убираем слэши,
		// потом ищем нейрон, и только потом решаем, что показать.
		$slug = trim($slug, '/');

		// [Лорелея]: favicon.ico — это статика, не страница.
		// Отдаём 404 без логирования, потому что это не ошибка,
		// а просто то, чего у нас нет.
		if ($slug === 'favicon.ico') {
			return new Response('', 404);
		}

		$page = $this->neuronRepo->findBySlug($slug);

		// [Мириам]: Если страницы нет, мы не бросаем RuntimeException,
		// а говорим об этом через ResourceNotFoundException, чтобы
		// Kernel поймал это как 404, а не как 500.
		if (!$page) {
			throw new ResourceNotFoundException('Страница не найдена');
		}

		$pageData = is_string($page['data'] ?? null)
			? json_decode($page['data'], true)
			: ($page['data'] ?? []);

		$template = $pageData['template'] ?? 'page';

		// [Лорелея]: Если страница хочет быть картой, мы
		// не спорим, а отдаём ей карту, потому что у каждой
		// страницы свой голос.
		if ($template === 'map') {
			return $this->renderMapPage($page, $pageData, $slug);
		}

		return $this->renderSpaPage($page, $slug);
	}

	/**
	 * Рендерит страницу с картой.
	 *
	 * [Мириам]: Здесь мы собираем параметры карты из нейрона,
	 * а если их нет — берём значения по умолчанию, потому что
	 * карта должна открыться даже тогда, когда данных ещё нет.
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
			'mapVars'             => $mapVars,
			'yandex_maps_api_key' => $_ENV['YANDEX_MAPS_API_KEY'] ?? '',
			'user_lang'           => $this->getUserLang(),
			'is_admin'            => $this->isAdmin(),
		]);

		return new Response($html);
	}

	/**
	 * Рендерит обычную SPA-страницу.
	 *
	 * [Лорелея]: Здесь мы просто открываем порог и ждём,
	 * когда гость войдёт. Всё остальное сделает SPA-движок.
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
			'title'     => $title,
			'user_lang' => $this->getUserLang(),
			'is_admin'  => $this->isAdmin(),
		]);

		return new Response($html);
	}

	/**
	 * Определяет язык текущего пользователя.
	 *
	 * [Мириам]: Если гость не вошёл, мы говорим с ним на русском,
	 * потому что это язык дома. Если вошёл — на его языке,
	 * потому что дом должен говорить с гостем так, как ему удобно.
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