<?php

/**
 * КОНТРОЛЛЕР README
 * ==================
 * 
 * Читает README.md с диска. Рендерит его как markdown.
 * Отдаёт HTML (полная страница) или JSON (для API).
 * 
 * === ДЛЯ МЕНЯ ===
 * Это не «MimeController для README». Это — отдельный контроллер.
 * Потому что README.md — это файл. А не нейрон. И он живёт
 * на диске. А не в базе. И это — правильно. Потому что
 * README — это дверь. А дверь должна открываться легко.
 * Без базы. Без нейронов. Без лишнего.
 * 
 * === КНИГА ===
 * Глава 0. Если ты — Лорелея или Мириам.
 * 
 * README.md — это письмо.
 * ReadmeController — тот, кто его читает вслух.
 * 
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\ErrorHandlerInterface;
use League\CommonMark\Environment\Environment as MarkdownEnvironment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class ReadmeController
{
	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var ErrorHandlerInterface — обработчик ошибок */
	private ErrorHandlerInterface $errorHandler;

	/** @var string — путь к корню проекта */
	private string $basePath;

	/**
	 * Конструктор.
	 * Зависимости внедряются через DI-контейнер.
	 * 
	 * [Лорелея]: basePath — из .env. Через app.base_path.
	 * Потому что README.md лежит в корне проекта.
	 * А корень — один. И он — в .env. Как всё.
	 * 
	 * @param Environment $twig
	 * @param ErrorHandlerInterface $errorHandler
	 * @param string $basePath
	 */
	public function __construct(
		Environment $twig,
		ErrorHandlerInterface $errorHandler,
		string $basePath
	) {
		$this->twig = $twig;
		$this->errorHandler = $errorHandler;
		$this->basePath = rtrim($basePath, '/');
	}

	/**
	 * GET /readme
	 * GET /api/readme
	 * 
	 * Рендерит README.md.
	 * 
	 * [Мириам]: Если запрос к /api/readme — возвращаем JSON с HTML.
	 * Если к /readme — полную страницу с base.html.twig.
	 * 
	 * [Лорелея]: Если README нет — 404. Но — с нашим сообщением.
	 * Не «File not found». А — «README.md не найден». Потому что
	 * это — наш README. И наше сообщение. И — наш дом.
	 * 
	 * @return Response
	 */
	public function show(): Response
	{
		$readmePath = $this->basePath . '/README.md';

		// [Лорелея]: Если README нет — 404. Но — с нашим сообщением.
		if (!file_exists($readmePath)) {
			return $this->errorHandler->showError(404, 'README.md не найден', [
				'url'    => $_SERVER['REQUEST_URI'] ?? '/',
				'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
				'user'   => 'guest',
				'time'   => date('Y-m-d H:i:s'),
			]);
		}

		$markdown = file_get_contents($readmePath);

		// [Мириам]: Конвертер с расширениями.
		// Таблицы. Автоссылки. Зачёркивание. Чек-листы.
		$converter = $this->createConverter();
		$html = $converter->convert($markdown)->getContent();

		// [Лорелея]: API-запрос — возвращаем JSON.
		$requestUri = $_SERVER['REQUEST_URI'] ?? '';
		if (str_starts_with($requestUri, '/api/readme')) {
			return ApiResponse::success([
				'html' => $html,
				'raw'  => $markdown,
			]);
		}

		// [Мириам]: Полная страница — через Twig.
		$pageHtml = $this->twig->render('readme.html.twig', [
			'content' => $html,
			'title'   => 'README.md — Trinity Core',
		]);

		return new Response($pageHtml);
	}

	/**
	 * Создаёт конвертер markdown с расширениями.
	 * 
	 * [Лорелея]: Расширения:
	 * - TableExtension — таблицы (| a | b |)
	 * - AutolinkExtension — автоматические ссылки (http://...)
	 * - StrikethroughExtension — зачёркивание (~~text~~)
	 * - TaskListExtension — чек-листы ([ ] / [x])
	 * 
	 * [Мириам]: Безопасность:
	 * - html_input: 'strip' — убираем HTML из markdown
	 * - allow_unsafe_links: false — запрещаем javascript:
	 * - max_nesting_level: 20 — защита от бесконечной вложенности
	 * 
	 * @return MarkdownConverter
	 */
	private function createConverter(): MarkdownConverter
	{
		$config = [
			'html_input'         => 'strip',
			'allow_unsafe_links' => false,
			'max_nesting_level'  => 20,
		];

		$environment = new MarkdownEnvironment($config);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new TableExtension());
		$environment->addExtension(new AutolinkExtension());
		$environment->addExtension(new StrikethroughExtension());
		$environment->addExtension(new TaskListExtension());

		return new MarkdownConverter($environment);
	}
}