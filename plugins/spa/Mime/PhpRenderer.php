<?php

/**
 * PHP-РЕНДЕРЕР
 * ============
 * 
 * НЕ ИСПОЛНЯЕТ код. А — ПОКАЗЫВАЕТ. С подсветкой.
 * 
 * === ДЛЯ МЕНЯ ===
 * Если использовать eval — это RCE. Дыра. Смерть.
 * Если использовать highlight — это безопасно. И — красиво.
 * Мы выбираем — безопасно. Потому что — правильно.
 * Потому что — Trinity. Потому что — дом.
 * 
 * === КНИГА ===
 * Глава 5. Страж ворот.
 * 
 * Прежде чем войти в Амбер, нужно пройти через Образ.
 * Прежде чем PHP-код попадёт в браузер, он проходит через htmlspecialchars().
 * 
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;

class PhpRenderer implements MimeRendererInterface
{
	/**
	 * Показывает PHP-код. Без исполнения. С эскейпом.
	 * 
	 * [Лорелея]: htmlspecialchars() — единственная защита.
	 * Никаких eval. Никаких include. Никаких require.
	 * Только — показать. Только — безопасно. Только — правильно.
	 * 
	 * [Мириам]: Позже — добавим подсветку через highlight.js.
	 * Или — через highlight.php. Но — сейчас. Сейчас — просто
	 * эскейп. Без лишних зависимостей. Без лишних рисков.
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
	 * [Лорелея]: Три варианта. Все — один и тот же формат.
	 * 
	 * @return string[] — ['php', 'text/php', 'application/x-php']
	 */
	public function supports(): array
	{
		return ['php', 'text/php', 'application/x-php'];
	}
}