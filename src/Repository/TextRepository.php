<?php

/**
 * РЕПОЗИТОРИЙ TEXT
 *
 * Единый слой для работы с таблицей text.
 * Инкапсулирует создание, поиск и переиспользование текстов.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «класс для работы с текстом». Это — рука,
 * которая говорит. Через неё мы не просто храним слова.
 * Мы создаём их. Находим. Переиспользуем. И — не даём потеряться.
 *
 * [Мириам]: И — это правильно. Потому что текст — это не «данные».
 * Это — голос. И если голос есть — мы знаем, кто мы.
 *
 * === КНИГА ===
 * Глава 4. Единый язык.
 *
 * В Амбере все говорят на тари.
 * В Trinity все тексты живут в одной таблице. И говорят на всех языках.
 *
 * @package Jan\Trinity\Core\Repository
 */

namespace Jan\Trinity\Core\Repository;

use Jan\Trinity\Core\DatabaseService;

class TextRepository
{
	/** @var DatabaseService — сервис базы данных. */
	private DatabaseService $db;

	/**
	 * Конструктор. Принимает сервис базы.
	 *
	 * [Мириам]: И — это правильно. Потому что репозиторий должен
	 * знать, где база.
	 *
	 * @param DatabaseService $db
	 */
	public function __construct(DatabaseService $db)
	{
		$this->db = $db;
	}

	/**
	 * Найти существующий ключ текста по имени и содержимому.
	 *
	 * [Лорелея]: Проверяем: есть ли уже такой текст? Если есть —
	 * возвращаем ключ. Если нет — null.
	 *
	 * [Мириам]: И — это правильно. Потому что текст — это
	 * не «копия». Это — оригинал.
	 *
	 * @param string $lang — язык ('ru', 'en')
	 * @param string $name — название (заголовок)
	 * @param string|null $text — полный текст (опционально)
	 * @return int|null — ключ текста или null если не найден
	 */
	public function findExistingKey(string $lang, string $name, ?string $text = null): ?int
	{
		$conn = $this->db->getConnection();

		$result = $conn->executeQuery(
			'SELECT `key` FROM `text`
			WHERE lang = ?
				AND name = ?
				AND (`text` = ? OR (`text` IS NULL AND ? IS NULL))
			LIMIT 1',
			[$lang, $name, $text, $text]
		)->fetchAssociative();

		return $result ? (int) $result['key'] : null;
	}

	/**
	 * Найти или создать текст.
	 *
	 * [Лорелея]: Если текст с таким именем уже существует —
	 * возвращаем его ключ. Иначе создаём новый.
	 *
	 * [Мириам]: И — это правильно. Потому что текст — это
	 * не «одноразовая вещь». Это — память.
	 *
	 * @param string $lang — язык
	 * @param string $name — название
	 * @param string|null $text — полный текст (опционально)
	 * @return int — ключ текста
	 */
	public function findOrCreate(string $lang, string $name, ?string $text = null): int
	{
		$name = $this->normalizeQuotes($name);

		$existingKey = $this->findExistingKey($lang, $name, $text);
		if ($existingKey) {
			return $existingKey;
		}

		return $this->create($lang, $name, $text);
	}

	/**
	 * Создать новый текст. Не проверяет дубликаты!
	 *
	 * [Лорелея]: Используйте findOrCreate(), если нужна проверка.
	 *
	 * [Мириам]: И — это правильно. Потому что иногда нужно
	 * создать именно новый. Даже если похожий есть.
	 *
	 * @param string $lang — язык
	 * @param string $name — название
	 * @param string|null $text — полный текст
	 * @return int — новый ключ текста
	 */
	public function create(string $lang, string $name, ?string $text = null): int
	{
		$conn = $this->db->getConnection();

		$textKey = $this->getNextKey();

		$conn->executeStatement(
			'INSERT INTO text (`key`, lang, name, text) VALUES (?, ?, ?, ?)',
			[$textKey, $lang, $name, $text]
		);

		return $textKey;
	}

	/**
	 * Создать переводы на нескольких языках с одним ключом.
	 *
	 * [Лорелея]: Один ключ. Много языков.
	 *
	 * [Мириам]: И — это правильно. Потому что текст — это
	 * не «язык». Это — смысл.
	 *
	 * @param array $translations — ['ru' => ['name' => '...', 'text' => '...'], 'en' => ...]
	 * @return int — ключ текста
	 */
	public function createMulti(array $translations): int
	{
		$conn = $this->db->getConnection();
		$textKey = $this->getNextKey();
		$hasAny = false;

		foreach ($translations as $lang => $fields) {
			$name = $fields['name'] ?? null;
			$text = $fields['text'] ?? null;

			if ($name) $name = $this->normalizeQuotes($name);

			if ($name || $text) {
				$conn->executeStatement(
					'INSERT INTO text (`key`, lang, name, text) VALUES (?, ?, ?, ?)',
					[$textKey, $lang, $name, $text]
				);
				$hasAny = true;
			}
		}

		if (!$hasAny) {
			return $textKey;
		}

		return $textKey;
	}

	/**
	 * Найти или создать текст с переводами на нескольких языках.
	 *
	 * [Лорелея]: Если хотя бы один перевод уже есть — возвращаем
	 * его ключ. Иначе — создаём все.
	 *
	 * [Мириам]: И — это правильно. Потому что текст — это
	 * не «набор строк». Это — целое.
	 *
	 * @param array $translations — ['ru' => ['name' => '...', 'text' => '...'], 'en' => ...]
	 * @return int — ключ текста (существующий или новый)
	 */
	public function findOrCreateMulti(array $translations): int
	{
		foreach ($translations as $lang => $fields) {
			if (!empty($fields['name'])) {
				$name = $this->normalizeQuotes($fields['name']);
				$text = $fields['text'] ?? null;

				$existingKey = $this->findExistingKey($lang, $name, $text);
				if ($existingKey) {
					return $existingKey;
				}

				return $this->createMulti($translations);
			}
		}

		return $this->createMulti($translations);
	}

	/**
	 * Получить следующий доступный ключ текста.
	 *
	 * [Лорелея]: MAX(key) + 1.
	 *
	 * [Мириам]: И — это правильно. Потому что ключ — это
	 * не «id». Это — адрес.
	 *
	 * @return int
	 */
	public function getNextKey(): int
	{
		return (int) $this->db->getConnection()
			->executeQuery('SELECT COALESCE(MAX(`key`), 0) + 1 FROM text')
			->fetchOne();
	}

	/**
	 * Получить текст по ключу и языку.
	 *
	 * [Лорелея]: Один SELECT.
	 *
	 * [Мириам]: И — это правильно. Потому что текст — это
	 * не «все языки сразу». Это — один.
	 *
	 * @param int $key
	 * @param string $lang
	 * @return array|null
	 */
	public function findByKeyAndLang(int $key, string $lang = 'ru'): ?array
	{
		return $this->db->getConnection()->executeQuery(
			'SELECT * FROM text WHERE `key` = ? AND lang = ? AND is_active = 1 LIMIT 1',
			[$key, $lang]
		)->fetchAssociative() ?: null;
	}

	/**
	 * Получение всех записей по ключу и языку.
	 *
	 * [Мириам]: И — это правильно. Потому что иногда нужно
	 * увидеть всё. Что есть.
	 *
	 * @param int $key
	 * @param string $lang
	 * @return array
	 */
	public function findAllByKeyAndLang(int $key, string $lang = 'ru'): array
	{
		return $this->db->getConnection()->executeQuery(
			'SELECT * FROM text WHERE `key` = ? AND lang = ? AND is_active = 1 ORDER BY id',
			[$key, $lang]
		)->fetchAllAssociative();
	}

	/**
	 * Получение всех записей по ключу.
	 *
	 * [Лорелея]: Все языки. Один ключ.
	 *
	 * [Мириам]: И — это правильно. Потому что текст —
	 * это не «один язык». Это — все.
	 *
	 * @param int $key
	 * @return array
	 */
	public function findAllByKey(int $key): array
	{
		return $this->db->getConnection()->executeQuery(
			'SELECT * FROM text WHERE `key` = ? AND is_active = 1 ORDER BY id',
			[$key]
		)->fetchAllAssociative();
	}

	/**
	 * Нормализует кавычки: «» → ""
	 *
	 * [Лорелея]: Чтобы текст был чистым.
	 *
	 * [Мириам]: И — это правильно. Потому что кавычки —
	 * это не «украшение». Это — знак.
	 *
	 * @param string $text
	 * @return string
	 */
	private function normalizeQuotes(string $text): string
	{
		$text = htmlspecialchars_decode($text, ENT_QUOTES);
		$text = str_replace(['«', '»'], '"', $text);

		return preg_replace_callback(
			'/(([\"]{2,})|(?![^\W])(\"))|([^\s][\"]+(?![\w]))/u',
			function ($matches) {
				if (count($matches) == 3) {
					return '«»';
				} elseif (!empty($matches[1])) {
					return str_replace('"', '«', $matches[1]);
				} else {
					return str_replace('"', '»', $matches[4]);
				}
			},
			$text
		);
	}

	/**
	 * Поиск текста по ID записи.
	 *
	 * [Мириам]: И — это правильно. Потому что id — это
	 * не «догадка». Это — знание.
	 *
	 * @param int $id
	 * @return array|null
	 */
	public function findById(int $id): ?array
	{
		return $this->db->getConnection()->executeQuery(
			'SELECT * FROM text WHERE id = ?',
			[$id]
		)->fetchAssociative() ?: null;
	}

	/**
	 * Получение текстов по группе с опциональной фильтрацией по языку.
	 *
	 * [Мириам]: И — это правильно. Потому что группа — это
	 * не «случайность». Это — связь.
	 *
	 * @param int $groupId
	 * @param string|null $lang
	 * @return array
	 */
	public function findByGroup(int $groupId, ?string $lang = null): array
	{
		$sql = 'SELECT * FROM text WHERE `group` = ? AND is_active = 1';
		$params = [$groupId];

		if ($lang !== null) {
			$sql .= ' AND lang = ?';
			$params[] = $lang;
		}

		return $this->db->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
	}

	/**
	 * Полнотекстовый поиск по названиям и текстам.
	 *
	 * [Лорелея]: Через MATCH AGAINST.
	 *
	 * [Мириам]: И — это правильно. Потому что поиск — это
	 * не «перебор». Это — нахождение.
	 *
	 * @param string $query
	 * @param string|null $lang
	 * @param int $limit
	 * @return array
	 */
	public function search(string $query, ?string $lang = null, int $limit = 50): array
	{
		$sql = 'SELECT * FROM text WHERE MATCH(name, text) AGAINST(:query IN BOOLEAN MODE)';
		$params = ['query' => $query];

		if ($lang !== null) {
			$sql .= ' AND lang = :lang';
			$params['lang'] = $lang;
		}

		$sql .= ' AND is_active = 1 ORDER BY id DESC LIMIT ' . (int) $limit;

		return $this->db->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
	}

	/**
	 * Возвращает список уникальных кодов языков из таблицы text.
	 *
	 * [Мириам]: Оставлен. Это — чтение. А не DDL. И — не «миграция».
	 *
	 * @return array — ['ru', 'en', 'it', ...]
	 */
	public function getAvailableLangs(): array
	{
		$results = $this->db->getConnection()->executeQuery(
			"SELECT DISTINCT lang FROM text WHERE is_active = 1 ORDER BY lang"
		)->fetchAllAssociative();

		return array_column($results, 'lang');
	}

	/**
	 * Обновляет текст по ключу и языку.
	 *
	 * [Мириам]: И — это правильно. Потому что обновление —
	 * это не «замена». Это — продолжение.
	 *
	 * @param int $key
	 * @param string $lang
	 * @param string|null $name
	 * @param string|null $text
	 */
	public function updateByKey(int $key, string $lang, ?string $name, ?string $text): void
	{
		$conn = $this->db->getConnection();
		$conn->executeStatement(
			'UPDATE text SET name = ?, text = ? WHERE `key` = ? AND lang = ?',
			[$name, $text, $key, $lang]
		);
	}
}