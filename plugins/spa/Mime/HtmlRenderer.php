<?php

/**
 * HTML-РЕНДЕРЕР
 * =============
 *
 * Очищает HTML от всего, что может ранить. Пропускает только безопасные теги,
 * только те атрибуты, которые не притворяются чем-то другим.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Раньше здесь каждый раз заново разжигали печь.
 * Теперь — печь одна, и она уже горячая. Быстро. Чисто. Правильно.
 *
 * [Мириам]: HTML — это дверь. А Purifier — тот, кто стоит на пороге
 * и смотрит, чтобы через дверь не пронесли ничего лишнего.
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
	 * [Лорелея]: Лениво. Потому что если HTML на странице так и не
	 * появится, мы не будем зря строить граф правил и тратить память.
	 */
	private ?\HTMLPurifier $purifier = null;

	/**
	 * Очищает HTML и оборачивает его в контейнер.
	 *
	 * [Лорелея]: Один вызов — один готовый очиститель.
	 * Без повторной сборки. Без лишнего огня.
	 *
	 * [Мириам]: И — безопасно. Потому что очиститель знает своё дело,
	 * а мы ему доверяем. Как доверяют воде, которая течёт по руслу.
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
	 * [Лорелея]: Вот здесь — вся суть. Один раз. И — навсегда.
	 * Потому что Purifier — тяжёлый, и держать его в готовности
	 * дешевле, чем собирать заново.
	 *
	 * [Мириам]: И — чисто. Потому что создание инструмента —
	 * это не работа рендерера. Это — его подготовка. А работа —
	 * это когда инструмент уже в руках.
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
	 * [Лорелея]: Два варианта. Один и тот же формат.
	 * Но реестр должен найти рендерер любым из них.
	 *
	 * [Мириам]: И — это правильно. Потому что в data может быть
	 * и 'html', и 'text/html'. Оба — наши. Оба — правильные.
	 *
	 * @return string[]
	 */
	public function supports(): array
	{
		return ['html', 'text/html'];
	}
}