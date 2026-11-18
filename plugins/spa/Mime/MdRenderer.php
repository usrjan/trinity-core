<?php
declare(strict_types=1);

/**
 * MARKDOWN-РЕНДЕРЕР
 *
 * Превращает markdown в HTML через league/commonmark.
 * С таблицами, с автоссылками, с зачёркиванием, с чек-листами.
 * И — с безопасностью, всегда.
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
	 */
	private ?\HTMLPurifier $purifier = null;

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
	 * Возвращает очиститель, создавая его только при первой необходимости.
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
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['md', 'markdown', 'text/markdown'];
	}
}