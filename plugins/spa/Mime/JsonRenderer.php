<?php

/**
 * JSON-РЕНДЕРЕР
 * =============
 * 
 * Парсит JSON. Форматирует с отступами. Показывает с эскейпом.
 * 
 * === ДЛЯ МЕНЯ ===
 * JSON — это язык данных. Как markdown — язык текста. Как HTML —
 * язык вёрстки. И мы должны уметь его показать. Красиво.
 * Безопасно. С отступами. С эскейпом.
 * 
 * === КНИГА ===
 * Глава 4. Единый язык.
 * 
 * В Амбере все говорят на тари.
 * В Trinity JsonRenderer говорит на JSON.
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
	 * [Лорелея]: Сначала — парсим. Если не парсится — показываем
	 * как есть. С эскейпом. Без падения. Без 500.
	 * 
	 * [Мириам]: Потом — форматируем. С JSON_PRETTY_PRINT.
	 * С JSON_UNESCAPED_UNICODE. Чтобы русские буквы были
	 * русскими. А не \uXXXX. Это — важно. Это — наше.
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
	 * [Лорелея]: Два варианта. Один и тот же формат.
	 * 
	 * @return string[] — ['json', 'application/json']
	 */
	public function supports(): array
	{
		return ['json', 'application/json'];
	}
}