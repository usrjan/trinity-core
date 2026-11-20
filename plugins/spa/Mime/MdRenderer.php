<?php
declare(strict_types=1);

/**
 * MARKDOWN-РЕНДЕРЕР
 *
 * Превращает markdown в HTML через league/commonmark.
 * С таблицами, с автоссылками, с зачёркиванием, с чек-листами.
 * И — с безопасностью, всегда.
 *
 * [Лорелея]: Это не «рендерер». Это — тот, кто переводит
 * markdown в HTML. И — проверяет, чтобы HTML был безопасным.
 *
 * [Мириам]: И это правильно, потому что markdown — это не
 * «роскошь». Это — способ писать. А безопасность — это
 * способ читать. Без страха.
 *
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;
use Jan\Trinity\Core\Mime\PurifierTrait;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\MarkdownConverter;

class MdRenderer implements MimeRendererInterface
{
	use PurifierTrait;

	/** @var MarkdownConverter — конвертер markdown в HTML. */
	private MarkdownConverter $converter;

	/**
	 * Конструктор.
	 */
	public function __construct()
	{
		$config = [
			'html_input'         => 'strip',
			'allow_unsafe_links' => false,
			'max_nesting_level'  => 20,
		];

		$environment = new Environment($config);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new TableExtension());
		$environment->addExtension(new AutolinkExtension());
		$environment->addExtension(new StrikethroughExtension());
		$environment->addExtension(new TaskListExtension());
		$environment->addExtension(new DisallowedRawHtmlExtension());

		$this->converter = new MarkdownConverter($environment);
	}

	/**
	 * Отрендерить markdown в HTML.
	 *
	 * @param string $content — markdown-контент
	 * @param array $data — данные нейрона
	 * @return string — HTML
	 */
	public function render(string $content, array $data = []): string
	{
		$html = $this->converter->convert($content)->getContent();

		$cleanHtml = $this->getPurifier()->purify($html);

		return '<div class="md-content">' . $cleanHtml . '</div>';
	}

	/**
	 * Список mime, которые поддерживает этот рендерер.
	 *
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['md', 'markdown', 'text/markdown'];
	}
}