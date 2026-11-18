<?php
declare(strict_types=1);

/**
 * РЕЕСТР MIME-РЕНДЕРЕРОВ
 *
 * Знает все рендереры и выбирает нужный по mime.
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
	 * @param MimeRendererInterface $renderer
	 */
	public function add(MimeRendererInterface $renderer): void
	{
		$this->renderers[] = $renderer;
	}

	/**
	 * Рендерит контент по mime.
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

		return '<pre>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>';
	}

	/**
	 * Поддерживается ли mime.
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