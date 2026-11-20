<?php
declare(strict_types=1);

/**
 * HTML-РЕНДЕРЕР
 *
 * Санитизирует HTML через HTMLPurifier. Пропускает только безопасные теги.
 *
 * [Лорелея]: Это не «рендерер». Это — страж. Который проверяет
 * каждый тег, каждую ссылку, каждый атрибут. Потому что
 * безопасность — это не «роскошь». Это — необходимость.
 *
 * [Мириам]: И это правильно, потому что вода не должна
 * приносить камни, даже если её набрали в чистом месте.
 *
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;
use Jan\Trinity\Core\Mime\PurifierTrait;

class HtmlRenderer implements MimeRendererInterface
{
	use PurifierTrait;

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
	 * Список mime, которые поддерживает этот рендерер.
	 *
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['html', 'text/html'];
	}
}