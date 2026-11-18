<?php
declare(strict_types=1);

/**
 * HTML-РЕНДЕРЕР
 *
 * Санитизирует HTML через HTMLPurifier. Пропускает только безопасные теги.
 *
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;

class HtmlRenderer implements MimeRendererInterface
{
	/**
	 * @var \HTMLPurifier|null — очиститель. Создаётся один раз,
	 * при первом вызове, и живёт вместе с рендерером.
	 */
	private ?\HTMLPurifier $purifier = null;

	/**
	 * Очищает HTML и оборачивает его в контейнер.
	 *
	 * @param string $content — HTML-контент
	 * @param array $data — данные нейрона
	 * @return string — безопасный HTML
	 */
	public function render(string $content, array $data = []): string
	{
		$cleanHtml = $this->getPurifier()->purify($content);

		return '<div class="html-content">' . $cleanHtml . '</div>';
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
		return ['html', 'text/html'];
	}
}