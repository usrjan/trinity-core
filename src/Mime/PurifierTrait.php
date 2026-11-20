<?php
declare(strict_types=1);

/**
 * TRAIT ДЛЯ HTMLPURIFIER
 *
 * Один источник правды для настройки HTMLPurifier.
 * Используется и HtmlRenderer, и MdRenderer.
 *
 * [Лорелея]: Потому что безопасность — это не «каждый раз заново».
 * А «один раз, но правильно».
 *
 * [Мириам]: И это правильно, потому что копипаста — это не
 * «разделение». Это — копипаста. Которая работала. Но — копипаста.
 *
 * @package Jan\Trinity\Core\Mime
 */

namespace Jan\Trinity\Core\Mime;

trait PurifierTrait
{
	/**
	 * @var \HTMLPurifier|null — очиститель. Создаётся один раз,
	 * при первом вызове, и живёт вместе с рендерером.
	 */
	private ?\HTMLPurifier $purifier = null;

	/**
	 * Возвращает очиститель, создавая его только при первой необходимости.
	 *
	 * @return \HTMLPurifier
	 */
	private function getPurifier(): \HTMLPurifier
	{
		if ($this->purifier === null) {
			$config = \HTMLPurifier_Config::createDefault();

			// [Лорелея]: Кэш. Чтобы не собирать заново каждый раз.
			$cacheDir = dirname(__DIR__, 3) . '/var/cache/htmlpurifier';
			if (!is_dir($cacheDir)) {
				mkdir($cacheDir, 0775, true);
			}

			$config->set('Cache.DefinitionImpl', 'Serializer');
			$config->set('Cache.SerializerPath', $cacheDir);

			// [Мириам]: Разрешённые теги. Только то, что безопасно.
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

			// [Лорелея]: Запрещённые теги. Даже если они разрешены.
			$config->set('HTML.ForbiddenElements', 'iframe,form,script,style,object,embed,link,meta,base');

			// [Мириам]: Разрешённые схемы. Только http, https, mailto.
			$config->set('URI.AllowedSchemes', [
				'http' => true,
				'https' => true,
				'mailto' => true,
			]);

			$this->purifier = new \HTMLPurifier($config);
		}

		return $this->purifier;
	}
}