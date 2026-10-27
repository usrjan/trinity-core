<?php

/**
 * HTML-РЕНДЕРЕР
 * =============
 * 
 * Пропускает HTML как есть. Без санитайза. Без эскейпа.
 * 
 * === ДЛЯ МЕНЯ ===
 * Это — самый опасный момент. HTML — можно всё. <script>. onload.
 * javascript:. Всё — опасно. Но — если это наш HTML. Если это
 * наша вёрстка. Если это — шаблон, который мы сами написали —
 * тогда это — безопасно. Потому что мы — не враги себе.
 * Так же, как Twig с |raw. Так же, как base.html.twig.
 * Мы доверяем себе. Потому что — свои.
 * 
 * === КНИГА ===
 * Глава 4. Единый язык.
 * 
 * В Амбере все говорят на тари.
 * В Trinity HtmlRenderer говорит на HTML.
 * 
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;

class HtmlRenderer implements MimeRendererInterface
{
	/**
	 * Пропускает HTML как есть. Но — санитизирует через HTMLPurifier.
	 * 
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Раньше HTML выводился как есть.
	 * Это была дыра — хранимый XSS. Теперь — санитизируем.
	 * 
	 * [Мириам]: HTMLPurifier — надёжная библиотека. Она отфильтрует
	 * все опасные теги и атрибуты. И — оставит только безопасный HTML.
	 * 
	 * @param string $content — HTML-контент
	 * @param array $data — данные нейрона
	 * @return string — санитизированный HTML
	 */
	public function render(string $content, array $data = []): string
	{
		// [Лорелея]: Создаём конфиг. Можно настроить, если нужно.
		$config = \HTMLPurifier_Config::createDefault();
		$purifier = new \HTMLPurifier($config);

		// [Мириам]: Санитизируем контент.
		$cleanHtml = $purifier->purify($content);

		return '<div class="html-content">' . $cleanHtml . '</div>';
	}

	/**
	 * Список mime, которые поддерживает этот рендерер.
	 * 
	 * [Лорелея]: Два варианта. Один и тот же формат.
	 * Но реестр должен найти рендерер любым из них.
	 * 
	 * @return string[] — ['html', 'text/html']
	 */
	public function supports(): array
	{
		return ['html', 'text/html'];
	}
}