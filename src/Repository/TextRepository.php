<?php

/**
 * РЕПОЗИТОРИЙ TEXT
 *
 * Единый слой для работы с таблицей text.
 * Инкапсулирует создание, поиск и переиспользование текстов.
 *
 * Используется всеми плагинами вместо прямых SQL-запросов.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. addLang() удалён из репозитория.
 * Раньше он делал ALTER TABLE во время рантайма. Из админки.
 * И — это была скрытая миграция. И — права MySQL. И — «пиздец».
 * Теперь — DDL только в миграциях. Через migration-плагин.
 * Без «пиздеца». Без «потом». Без «завтра».
 *
 * [Мириам]: И — getAvailableLangs() оставлен. Потому что это —
 * чтение. А не DDL. И — не «миграция». И — «навсегда».
 *
 * @package Jan\Trinity\Core\Repository
 */

namespace Jan\Trinity\Core\Repository;

use Jan\Trinity\Core\DatabaseService;

class TextRepository
{
	private DatabaseService $db;

	public function __construct(DatabaseService $db)
	{
		$this->db = $db;
	}

	/**
	 * Найти существующий ключ текста по имени и содержимому.
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
	 * Найти или создать текст. Если текст с таким именем уже существует,
	 * возвращает его ключ. Иначе создаёт новый.
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
	 * Используйте findOrCreate(), если нужна проверка.
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
	 */
	public function getNextKey(): int
	{
		return (int) $this->db->getConnection()
			->executeQuery('SELECT COALESCE(MAX(`key`), 0) + 1 FROM text')
			->fetchOne();
	}

	/**
	 * Получить текст по ключу и языку.
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
	 * И — «навсегда».
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