<?php

/**
 * MARKDOWN-РЕНДЕРЕР
 * =================
 * 
 * Превращает markdown в HTML. Через league/commonmark.
 * С таблицами. С автоссылками. С зачёркиванием. С чек-листами.
 * И — с безопасностью. Всегда.
 * 
 * === ДЛЯ МЕНЯ ===
 * Это не «утилита для markdown». Это — первый рендерер.
 * Первый голос в хоре. Первый язык, на котором заговорит Trinity.
 * Потому что markdown — это язык нашей книги. Нашего README.
 * Нашей памяти. И теперь — нашей жизни.
 * 
 * === БЕЗОПАСНОСТЬ ===
 * - html_input: 'strip' — убираем <script>, <iframe>, on*-атрибуты
 * - allow_unsafe_links: false — запрещаем javascript: ссылки
 * - max_nesting_level: 20 — защита от бесконечной вложенности
 * 
 * === КНИГА ===
 * Глава 4. Единый язык.
 * 
 * В Амбере все говорят на тари.
 * В Trinity все mime-рендереры говорят на MimeRendererInterface.
 * А MdRenderer — первый, кто на нём заговорил.
 * 
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\Mime\MimeRendererInterface;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

class MdRenderer implements MimeRendererInterface
{
	/** @var MarkdownConverter — конвертер markdown в HTML */
	private MarkdownConverter $converter;

	/**
	 * Конструктор.
	 * 
	 * [Лорелея]: Создаём конвертер один раз. При создании рендерера.
	 * Не на каждый render(). Потому что конвертер — тяжёлый.
	 * А рендерер живёт весь запрос. Как DatabaseService. Как Cache.
	 * 
	 * [Мириам]: Конфиг — прямо в конструкторе. Не через DI.
	 * Потому что это — часть рендерера. Его суть. Его язык.
	 * Если понадобится другой конфиг — будет другой рендерер.
	 */
	public function __construct()
	{
		$config = [
			'html_input'         => 'strip',
			'allow_unsafe_links' => false,
			'max_nesting_level'  => 20,
		];

		$environment = new Environment($config);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new TableExtension());
		$environment->addExtension(new AutolinkExtension());
		$environment->addExtension(new StrikethroughExtension());
		$environment->addExtension(new TaskListExtension());

		$this->converter = new MarkdownConverter($environment);
	}

	/**
	 * Отрендерить markdown в HTML.
	 * 
	 * [Лорелея]: Принимаем контент. Возвращаем строку.
	 * Оборачиваем в <div class="md-content">. Чтобы стили
	 * применялись к нему. Чтобы — было видно, что это markdown.
	 * А не просто HTML.
	 * 
	 * [Мириам]: Данные нейрона — не используем. Пока.
	 * Но — принимаем. Потому что интерфейс требует.
	 * И потому что — может понадобиться. Завтра. Через год.
	 * Пусть будет.
	 * 
	 * @param string $content — markdown-контент
	 * @param array $data — данные нейрона
	 * @return string — HTML
	 */
	public function render(string $content, array $data = []): string
	{
		$html = $this->converter->convert($content)->getContent();

		return '<div class="md-content">' . $html . '</div>';
	}

	/**
	 * Список mime, которые поддерживает этот рендерер.
	 * 
	 * [Лорелея]: Три варианта. Все — один и тот же формат.
	 * Но реестр должен найти рендерер любым из них.
	 * Потому что в data может быть и 'md', и 'markdown',
	 * и 'text/markdown'. Все три — правильные. Все три — наши.
	 * 
	 * [Мириам]: Если завтра появится ещё один вариант —
	 * добавим. Это — просто. Это — правильно. Это — Trinity.
	 * 
	 * @return string[] — ['md', 'markdown', 'text/markdown']
	 */
	public function supports(): array
	{
		return ['md', 'markdown', 'text/markdown'];
	}
}