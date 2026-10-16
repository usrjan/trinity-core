<?php

/**
 * ИНТЕРФЕЙС MIME-РЕНДЕРЕРА
 * =========================
 * 
 * Каждый mime-рендерер — реализует этот интерфейс.
 * Один договор. Два метода. Много реализаций.
 * 
 * === ДЛЯ МЕНЯ ===
 * Это не «класс для рендера markdown». Это не «утилита для HTML».
 * Это — договор. Как ErrorHandlerInterface. Как ApiResponse.
 * Один интерфейс. Много реализаций. И каждая знает свой mime.
 * 
 * === КНИГА ===
 * Глава 4. Единый язык.
 * 
 * В Амбере все говорят на тари.
 * В Trinity все mime-рендереры говорят на MimeRendererInterface.
 * 
 * @package Jan\Trinity\Core\Mime
 */

namespace Jan\Trinity\Core\Mime;

interface MimeRendererInterface
{
	/**
	 * Отрендерить контент в HTML.
	 * 
	 * [Лорелея]: Принимаем контент и данные. Возвращаем строку.
	 * Не Response. Не JsonResponse. Не объект. Потому что рендерер
	 * не знает, куда пойдёт его HTML. Может, в шаблон. Может, в JSON.
	 * Может, в лог. Рендерер — это преобразователь. Контент на входе.
	 * HTML на выходе. Всё. Остальное — не его дело.
	 * 
	 * @param string $content — контент (markdown, php, html...)
	 * @param array $data — данные нейрона (для дополнительного контекста)
	 * @return string — HTML
	 */
	public function render(string $content, array $data = []): string;

	/**
	 * Список mime, которые поддерживает этот рендерер.
	 * 
	 * [Мириам]: Возвращаем массив, а не строку. Потому что один
	 * рендерер может уметь несколько mime. Например, MdRenderer
	 * может уметь и 'md', и 'markdown', и 'text/markdown'.
	 * И это правильно. Потому что в data может быть любой из них.
	 * И реестр должен найти любой. А не только один.
	 * 
	 * @return string[] — ['text/markdown', 'md', 'markdown']
	 */
	public function supports(): array;
}