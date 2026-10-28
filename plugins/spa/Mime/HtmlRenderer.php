<?php

/**
 * HTML-РЕНДЕРЕР
 *
 * Санитизирует HTML через HTMLPurifier. Пропускает только безопасные теги.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «рендерер». Это — страж на пороге HTML.
 * Тот, кто впускает, но только после проверки, потому что
 * через эту дверь может пронести что угодно.
 *
 * [Мириам]: И это правильно, потому что доверять HTML
 * без проверки — это как впустить гостя, не посмотрев,
 * что у него в руках.
 *
 * === КНИГА ===
 * Глава 4. Единый язык.
 *
 * В Амбере все говорят на тари.
 * В Trinity HtmlRenderer говорит на HTML. Безопасно.
 *
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;

class HtmlRenderer implements MimeRendererInterface
{
	/**
	 * @var \HTMLPurifier|null — очиститель. Создаётся один раз,
	 * при первом вызове, и живёт вместе с рендерером.
	 *
	 * [Лорелея]: Лениво, потому что если HTML на странице так
	 * и не появится, мы не будем зря строить граф правил
	 * и тратить память.
	 */
	private ?\HTMLPurifier $purifier = null;

	/**
	 * Очищает HTML и оборачивает его в контейнер.
	 *
	 * [Лорелея]: Один вызов — один готовый очиститель,
	 * без повторной сборки, потому что HTMLPurifier —
	 * тяжёлая штука, и собирать его заново для каждой
	 * страницы было бы расточительством.
	 *
	 * [Мириам]: И это правильно, потому что даже у огня
	 * должна быть одна печь, а не сто.
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
	 * Возвращает очиститель, создавая его только при первой необходимости.
	 *
	 * [Лорелея]: Вот здесь вся суть: один раз, и навсегда,
	 * потому что Purifier тяжёлый, и держать его в готовности
	 * дешевле, чем собирать заново.
	 *
	 * [Мириам]: И это правильно, потому что подготовка инструмента
	 * — это не работа, а работа начинается, когда инструмент
	 * уже в руках.
	 *
	 * @return \HTMLPurifier
	 */
	private function getPurifier(): \HTMLPurifier
	{
		if ($this->purifier === null) {
			$config = \HTMLPurifier_Config::createDefault();
			$this->purifier = new \HTMLPurifier($config);
		}

		return $this->purifier;
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
		return ['html', 'text/html'];
	}
}