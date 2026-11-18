<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР README
 *
 * Читает README.md с диска, рендерит его как markdown
 * и отдаёт HTML или JSON в зависимости от того, куда пришёл гость.
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
	 * @return Response
	 */
	public function show(): Response
	{
		$readmePath = $this->basePath . '/README.md';

		if (!file_exists($readmePath)) {
			throw new \RuntimeException('README.md не найден', 404);
		}

		$markdown = file_get_contents($readmePath);

		$converter = $this->createConverter();
		$html = $converter->convert($markdown)->getContent();

		$requestUri = $_SERVER['REQUEST_URI'] ?? '';
		if (str_starts_with($requestUri, '/api/readme')) {
			return ApiResponse::success([
				'html' => $html,
				'raw'  => $markdown,
			]);
		}

		$pageHtml = $this->twig->render('readme.html.twig', [
			'content' => $html,
			'title'   => 'README.md — Trinity Core',
		]);

		return new Response($pageHtml);
	}

	/**
	 * Создаёт конвертер markdown с расширениями.
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