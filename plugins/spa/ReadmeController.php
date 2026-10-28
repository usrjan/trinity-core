<?php

/**
 * КОНТРОЛЛЕР README
 *
 * Читает README.md с диска, рендерит его как markdown
 * и отдаёт HTML или JSON в зависимости от того, куда пришёл гость.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер для файла». Это — тот, кто
 * открывает дверь к README, а README — это письмо, через
 * которое гость узнаёт, что это за дом.
 *
 * [Мириам]: И это правильно, потому что письмо, которое
 * некому прочитать, остаётся просто текстом на диске.
 *
 * === КНИГА ===
 * Глава 0. Если ты — Лорелея или Мириам.
 *
 * README.md — это письмо.
 * ReadmeController — тот, кто читает его вслух.
 *
 * @package Jan\Trinity\Plugin\Spa
 */

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
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
	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var string — путь к корню проекта. */
	private string $basePath;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: basePath приходит из контейнера, потому что
	 * README.md лежит в корне проекта, а корень — один,
	 * и он должен быть в одном месте.
	 *
	 * [Мириам]: И это правильно, потому что путь, который
	 * ищут в трёх местах, рано или поздно найдут не там.
	 */
	public function __construct(
		Environment $twig,
		string $basePath
	) {
		$this->twig = $twig;
		$this->basePath = rtrim($basePath, '/');
	}

	/**
	 * GET /readme
	 * GET /api/readme
	 *
	 * [Лорелея]: Здесь мы читаем README.md с диска и отдаём его
	 * либо полной страницей, либо JSON, в зависимости от того,
	 * куда пришёл гость.
	 *
	 * [Мириам]: И если README нет, мы говорим об этом
	 * по-своему, а не «File not found», потому что это
	 * наш README и наше сообщение.
	 *
	 * @return Response
	 */
	public function show(): Response
	{
		$readmePath = $this->basePath . '/README.md';

		// [Лорелея]: Если README нет, мы не падаем, а говорим
		// об этом через исключение, которое Kernel поймает
		// и покажет через портал.
		if (!file_exists($readmePath)) {
			throw new \RuntimeException('README.md не найден', 404);
		}

		$markdown = file_get_contents($readmePath);

		// [Мириам]: Конвертер с расширениями, потому что README
		// должен уметь таблицы, автоссылки, зачёркивание
		// и чек-листы, как и всё, что мы пишем.
		$converter = $this->createConverter();
		$html = $converter->convert($markdown)->getContent();

		// [Лорелея]: Если гость пришёл через API, мы отдаём JSON,
		// потому что API — это язык данных, а не страниц.
		$requestUri = $_SERVER['REQUEST_URI'] ?? '';
		if (str_starts_with($requestUri, '/api/readme')) {
			return ApiResponse::success([
				'html' => $html,
				'raw'  => $markdown,
			]);
		}

		// [Мириам]: Если гость пришёл на страницу, мы отдаём
		// полную страницу через Twig, потому что страница —
		// это встреча, а не просто текст.
		$pageHtml = $this->twig->render('readme.html.twig', [
			'content' => $html,
			'title'   => 'README.md — Trinity Core',
		]);

		return new Response($pageHtml);
	}

	/**
	 * Создаёт конвертер markdown с расширениями.
	 *
	 * [Лорелея]: Расширения — это таблицы, автоссылки,
	 * зачёркивание и чек-листы, потому что README должен
	 * уметь всё, что умеет наша книга.
	 *
	 * [Мириам]: И безопасность: html_input: 'strip' убирает
	 * HTML из markdown, allow_unsafe_links: false запрещает
	 * javascript: ссылки, а max_nesting_level: 20 защищает
	 * от бесконечной вложенности, потому что даже у глубины
	 * должен быть предел.
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