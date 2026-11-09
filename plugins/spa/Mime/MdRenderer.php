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
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\MarkdownConverter;

class MdRenderer implements MimeRendererInterface
{
	/** @var MarkdownConverter — конвертер markdown в HTML. */
	private MarkdownConverter $converter;

	/**
	 * @var \HTMLPurifier|null — очиститель. Создаётся один раз,
	 * при первом вызове, и живёт вместе с рендерером.
	 *
	 * [Лорелея]: Лениво, потому что если markdown на странице
	 * так и не появится, мы не будем зря строить граф правил
	 * и тратить память.
	 */
	private ?\HTMLPurifier $purifier = null;

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
		$environment->addExtension(new DisallowedRawHtmlExtension());

		$this->converter = new MarkdownConverter($environment);
	}

	/**
	 * Отрендерить markdown в HTML.
	 *
	 * [Лорелея]: Сначала CommonMark — markdown в HTML. Потом
	 * HTMLPurifier — проверка, что в HTML осталось только
	 * безопасное. «Двойная защита», потому что strip — это
	 * «убрать теги», а purify — «проверить, что осталось».
	 *
	 * [Мириам]: И это правильно, потому что доверять одному
	 * слою — это как доверять одной двери в доме, где есть окна.
	 *
	 * @param string $content — markdown-контент
	 * @param array $data — данные нейрона
	 * @return string — HTML
	 */
	public function render(string $content, array $data = []): string
	{
		$html = $this->converter->convert($content)->getContent();

		// [Лорелея]: Второй слой — HTMLPurifier. Если CommonMark
		// пропустит что-то (например, img src с data:), purify
		// это уберёт.
		$cleanHtml = $this->getPurifier()->purify($html);

		return '<div class="md-content">' . $cleanHtml . '</div>';
	}

	/**
	 * Возвращает очиститель, создавая его только при первой необходимости.
	 *
	 * [Мириам]: Те же правила, что в HtmlRenderer, — один источник
	 * правды для безопасного HTML. Если правила поменяются —
	 * поменяются в одном месте.
	 *
	 * @return \HTMLPurifier
	 */
	private function getPurifier(): \HTMLPurifier
	{
		if ($this->purifier === null) {
			$config = \HTMLPurifier_Config::createDefault();

			$cacheDir = dirname(__DIR__, 3) . '/var/cache/htmlpurifier';
			if (!is_dir($cacheDir)) {
				mkdir($cacheDir, 0775, true);
			}

			$config->set('Cache.DefinitionImpl', 'Serializer');
			$config->set('Cache.SerializerPath', $cacheDir);

			$config->set('HTML.Allowed', implode(',', [
				'p', 'br', 'strong', 'em', 'u', 's', 'del', 'ins',
				'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
				'ul', 'ol', 'li',
				'blockquote', 'pre', 'code',
				'table', 'thead', 'tbody', 'tr', 'th', 'td',
				'a[href|title|target|rel]',
				'img[src|alt|title|width|height]',
				'hr',
				'span', 'div',
			]));

			$config->set('HTML.ForbiddenElements', 'iframe,form,script,style,object,embed,link,meta,base');

			$config->set('URI.AllowedSchemes', [
				'http' => true,
				'https' => true,
				'mailto' => true,
			]);

			$this->purifier = new \HTMLPurifier($config);
		}

		return $this->purifier;
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