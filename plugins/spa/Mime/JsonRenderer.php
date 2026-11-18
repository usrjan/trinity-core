<?php
declare(strict_types=1);

/**
 * JSON-РЕНДЕРЕР
 *
 * Парсит JSON, форматирует с отступами и показывает с эскейпом.
 *
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;

class JsonRenderer implements MimeRendererInterface
{
	/**
	 * Форматирует JSON и показывает его с эскейпом.
	 *
	 * @param string $content — JSON-контент
	 * @param array $data — данные нейрона
	 * @return string — HTML с форматированным JSON
	 */
	public function render(string $content, array $data = []): string
	{
		$decoded = json_decode($content, true);

		if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
			$escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
			return '<pre class="json-error">' . $escaped . '</pre>';
		}

		$pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		$escaped = htmlspecialchars($pretty, ENT_QUOTES, 'UTF-8');

		return '<pre class="json-code"><code class="language-json">' . $escaped . '</code></pre>';
	}

	/**
	 * Список mime, которые поддерживает этот рендерер.
	 *
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['json', 'application/json'];
	}
}