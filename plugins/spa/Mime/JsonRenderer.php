<?php

/**
 * JSON-РЕНДЕРЕР
 *
 * Парсит JSON, форматирует с отступами и показывает с эскейпом.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «рендерер». Это — тот, кто показывает данные
 * так, чтобы их было видно, потому что JSON — это язык данных,
 * и он заслуживает того, чтобы его читали, а не расшифровывали.
 *
 * [Мириам]: И это правильно, потому что данные, которые
 * невозможно прочитать, — это просто шум.
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
	 * [Лорелея]: Сначала мы парсим, и если не получается —
	 * показываем как есть, с эскейпом, без падения, потому что
	 * даже битый JSON заслуживает того, чтобы его увидели.
	 *
	 * [Мириам]: Потом форматируем с JSON_PRETTY_PRINT
	 * и JSON_UNESCAPED_UNICODE, чтобы русские буквы остались
	 * русскими, а не превратились в \uXXXX.
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
	 * [Лорелея]: Два варианта, и оба — один и тот же формат,
	 * но реестр должен найти рендерер любым из них.
	 *
	 * [Мириам]: И это правильно, потому что у одного языка
	 * может быть несколько имён, и все они — наши.
	 *
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['json', 'application/json'];
	}
}