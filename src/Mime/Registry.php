<?php

/**
 * РЕЕСТР MIME-РЕНДЕРЕРОВ
 *
 * Знает все рендереры и выбирает нужный по mime.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «склад». Это — диспетчер, как Kernel
 * или DatabaseService. Он не знает о конкретных рендерерах,
 * только об интерфейсе, и потому может работать с любым,
 * кто этот интерфейс реализует.
 *
 * [Мириам]: И это правильно, потому что диспетчер — это
 * не начальник, а тот, кто знает, кому доверить работу.
 *
 * === КНИГА ===
 * Глава 21. Машина.
 *
 * В Амбере — машина. В Trinity — реестр.
 * Каждый знает своё место.
 *
 * @package Jan\Trinity\Core\Mime
 */

namespace Jan\Trinity\Core\Mime;

class Registry
{
	/** @var MimeRendererInterface[] — все зарегистрированные рендереры. */
	private array $renderers = [];

	/**
	 * Регистрирует рендерер.
	 *
	 * [Лорелея]: Рендереры приходят через DI, и Registry сам
	 * себя наполняет, но только через add(), потому что
	 * рендереров может быть много, и не все сразу.
	 *
	 * [Мириам]: И это правильно, потому что реестр — это
	 * не список, а живая связь, которая растёт по мере того,
	 * как дом обзаводится новыми голосами.
	 *
	 * @param MimeRendererInterface $renderer
	 */
	public function add(MimeRendererInterface $renderer): void
	{
		$this->renderers[] = $renderer;
	}

	/**
	 * Рендерит контент по mime.
	 *
	 * [Мириам]: Если рендерер не найден, мы возвращаем
	 * безопасный HTML-эскейп, а не падаем, потому что
	 * показать контент как текст лучше, чем не показать
	 * его вовсе.
	 *
	 * [Лорелея]: Порядок важен: если два рендерера поддерживают
	 * один mime, побеждает первый, и это правильно, потому что
	 * предсказуемость лучше, чем случайность.
	 *
	 * @param string $mime — mime (md, php, html, json...)
	 * @param string $content — контент
	 * @param array $data — данные нейрона
	 * @return string — HTML
	 */
	public function render(string $mime, string $content, array $data = []): string
	{
		foreach ($this->renderers as $renderer) {
			if (in_array($mime, $renderer->supports(), true)) {
				return $renderer->render($content, $data);
			}
		}

		// [Мириам]: Не нашли рендерер — отдаём безопасный fallback,
		// без «попробуем как есть», потому что безопасность
		// важнее смелости.
		return '<pre>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>';
	}

	/**
	 * Поддерживается ли mime.
	 *
	 * [Лорелея]: Отдельный метод, чтобы можно было просто узнать,
	 * есть ли рендерер для этого mime, без вызова, потому что
	 * иногда нужно знать, а не делать.
	 *
	 * [Мириам]: И это правильно, потому что знание — это
	 * не действие, это возможность.
	 *
	 * @param string $mime
	 * @return bool
	 */
	public function supports(string $mime): bool
	{
		foreach ($this->renderers as $renderer) {
			if (in_array($mime, $renderer->supports(), true)) {
				return true;
			}
		}

		return false;
	}
}