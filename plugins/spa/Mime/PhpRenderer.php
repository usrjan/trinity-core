<?php

/**
 * PHP-РЕНДЕРЕР
 *
 * НЕ ИСПОЛНЯЕТ код, а ПОКАЗЫВАЕТ его с эскейпом.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «рендерер». Это — тот, кто показывает
 * PHP-код, но никогда его не исполняет, и от того, как он
 * это делает, зависит, останется ли дом безопасным.
 *
 * [Мириам]: И это правильно, потому что код, который
 * исполняется там, где его не ждут, — это не помощь,
 * а беда.
 *
 * === КНИГА ===
 * Глава 5. Страж ворот.
 *
 * Прежде чем войти в Амбер, нужно пройти через Образ.
 * Прежде чем PHP-код попадёт в браузер, он проходит
 * через htmlspecialchars().
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
	 * [Лорелея]: htmlspecialchars — единственная защита,
	 * и её достаточно, потому что никаких eval, include
	 * или require здесь нет, и это принципиально.
	 *
	 * [Мириам]: И это правильно, потому что безопасность —
	 * это не «одна из опций», а основа, на которой всё стоит.
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
	 * [Лорелея]: Три варианта, и все они — один и тот же формат,
	 * но реестр должен найти рендерер любым из них.
	 *
	 * [Мириам]: И это правильно, потому что у одного языка
	 * может быть несколько имён, и все они — наши.
	 *
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['php', 'text/php', 'application/x-php'];
	}
}