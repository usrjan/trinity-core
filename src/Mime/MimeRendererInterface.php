<?php
declare(strict_types=1);

/**
 * ИНТЕРФЕЙС MIME-РЕНДЕРЕРА
 *
 * Каждый mime-рендерер реализует этот интерфейс.
 * Один договор, два метода, много реализаций.
 *
 * @package Jan\Trinity\Core\Mime
 */

namespace Jan\Trinity\Core\Mime;

interface MimeRendererInterface
{
	/**
	 * Отрендерить контент в HTML.
	 *
	 * @param string $content — контент (markdown, php, html...)
	 * @param array $data — данные нейрона (для дополнительного контекста)
	 * @return string — HTML
	 */
	public function render(string $content, array $data = []): string;

	/**
	 * Список mime, которые поддерживает этот рендерер.
	 *
	 * @return string[] — ['text/markdown', 'md', 'markdown']
	 */
	public function supports(): array;
}