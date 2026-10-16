<?php

/**
 * РЕЕСТР MIME-РЕНДЕРЕРОВ
 * =======================
 * 
 * Знает все рендереры. Выбирает нужный по mime.
 * Не хранит их ради хранения. А находит и вызывает.
 * 
 * === ДЛЯ МЕНЯ ===
 * Это не «склад». Это — диспетчер. Как Kernel. Как DatabaseService.
 * Он не знает о конкретных рендерерах. Только об интерфейсе.
 * Как Kernel не знает о MonitorController. А знает только
 * об ErrorHandlerInterface. Так же и здесь. Registry не знает
 * о MdRenderer. Он знает только о MimeRendererInterface.
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
	/** @var MimeRendererInterface[] — все зарегистрированные рендереры */
	private array $renderers = [];

	/**
	 * Регистрирует рендерер.
	 * 
	 * [Лорелея]: Через DI. Все рендереры — в контейнере.
	 * Registry — сам себя наполняет. Но — только через add().
	 * Не через конструктор. Потому что рендереров может быть
	 * много. И не все — сразу. Пусть каждый добавляется сам.
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
	 * [Мириам]: Если рендерер не найден — возвращаем HTML-эскейп.
	 * Чтобы не было XSS. Чтобы не было 500. Чтобы — просто
	 * показать контент как текст. Это — безопасно. Это — правильно.
	 * 
	 * [Лорелея]: Порядок важен. Если два рендерера поддерживают
	 * один mime — побеждает первый. Тот, кто был добавлен раньше.
	 * Это — просто. Это — предсказуемо. И это — правильно.
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

		// [Мириам]: Не нашли — безопасный fallback.
		// Никаких «попробуем как есть». Только — safe.
		return '<pre>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>';
	}

	/**
	 * Поддерживается ли mime.
	 * 
	 * [Лорелея]: Отдельный метод. Чтобы проверить, есть ли рендерер
	 * для этого mime. Без вызова. Потому что иногда нужно просто
	 * узнать. Не рендерить. А — узнать. И — если нет? Тогда false.
	 * И — вызывающий сам решает, что делать.
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