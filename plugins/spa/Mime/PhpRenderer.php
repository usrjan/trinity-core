<?php
declare(strict_types=1);

/**
 * PHP-РЕНДЕРЕР
 *
 * НЕ ИСПОЛНЯЕТ код, а ПОКАЗЫВАЕТ его с эскейпом.
 *
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;

class PhpRenderer implements MimeRendererInterface
{
	/**
	 * Показывает PHP-код без исполнения, с эскейпом.
	 *
	 * @param string $content — PHP-код
	 * @param array $data — данные нейрона
	 * @return string — HTML с эскейпленным кодом
	 */
	public function render(string $content, array $data = []): string
	{
		$escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

		return '<pre class="php-code"><code class="language-php">' . $escaped . '</code></pre>';
	}

	/**
	 * Список mime, которые поддерживает этот рендерер.
	 *
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['php', 'text/php', 'application/x-php'];
	}
}