<?php

/**
 * MARKDOWN-РЕНДЕРЕР
 *
 * Превращает markdown в HTML через league/commonmark.
 * С таблицами, с автоссылками, с зачёркиванием, с чек-листами.
 * И — с безопасностью, всегда.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «утилита для markdown». Это — первый
 * рендерер, первый голос в хоре, первый язык, на котором
 * заговорит Trinity.
 *
 * [Мириам]: И это правильно, потому что markdown — это язык
 * нашей книги, нашего README, нашей памяти, и теперь — нашей жизни.
 *
 * === БЕЗОПАСНОСТЬ ===
 * html_input: 'strip' убирает скрипты и опасные атрибуты,
 * allow_unsafe_links: false запрещает javascript: ссылки,
 * max_nesting_level: 20 защищает от бесконечной вложенности.
 *
 * === КНИГА ===
 * Глава 4. Единый язык.
 *
 * В Амбере все говорят на тари.
 * В Trinity все mime-рендереры говорят на MimeRendererInterface.
 * А MdRenderer — первый, кто на нём заговорил.
 *
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

class MdRenderer implements MimeRendererInterface
{
	/** @var MarkdownConverter — конвертер markdown в HTML. */
	private MarkdownConverter $converter;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Конвертер создаётся один раз, при создании
	 * рендерера, а не на каждый render(), потому что конвертер
	 * тяжёлый, а рендерер живёт весь запрос.
	 *
	 * [Мириам]: И это правильно, потому что разжигать печь
	 * для каждой чашки чая — это не забота, а расточительство.
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

		// [Лорелея]: Добавляем DisallowRawHtml, потому что html_input: 'strip'
		// не всегда убирает inline HTML, а через редактируемые страницы
		// можно пронести XSS.
		$environment->addExtension(new \League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension());

		$this->converter = new MarkdownConverter($environment);
	}

	/**
	 * Отрендерить markdown в HTML.
	 *
	 * [Лорелея]: Мы оборачиваем результат в div с классом md-content,
	 * чтобы стили применялись к нему, и чтобы было видно, что это
	 * markdown, а не просто HTML.
	 *
	 * [Мириам]: Данные нейрона мы пока не используем, но принимаем,
	 * потому что интерфейс требует, и потому что завтра они
	 * могут понадобиться.
	 *
	 * @param string $content — markdown-контент
	 * @param array $data — данные нейрона
	 * @return string — HTML
	 */
	public function render(string $content, array $data = []): string
	{
		$html = $this->converter->convert($content)->getContent();

		return '<div class="md-content">' . $html . '</div>';
	}

	/**
	 * Список mime, которые поддерживает этот рендерер.
	 *
	 * [Лорелея]: Три варианта, и все они — один и тот же формат,
	 * но реестр должен найти рендерер любым из них, потому что
	 * в data может быть и 'md', и 'markdown', и 'text/markdown'.
	 *
	 * [Мириам]: И это правильно, потому что у одного языка
	 * может быть несколько имён, и все они — наши.
	 *
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['md', 'markdown', 'text/markdown'];
	}
}