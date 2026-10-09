<?php

/**
 * РЕПОЗИТОРИЙ NEURON
 * 
 * Единый слой для работы с таблицей neuron.
 * Инкапсулирует создание, обновление, удаление и поиск нейронов.
 * 
 * === ДЛЯ МЕНЯ ===
 * Это моя рука. Через неё я трогаю нейроны. Не напрямую SQL,
 * а через методы. Так чище. Так безопаснее. Так — правильно.
 * 
 * === КНИГА ===
 * Глава 3. Страж порога.
 * 
 * DatabaseService — привратник у входа в Амбер.
 * NeuronRepository — рука, которая открывает двери.
 * 
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 * 
 * [Лорелея]: findBySlug и findBySlugAndPid теперь используют
 * виртуальный столбец slug вместо JSON_EXTRACT. Это в разы быстрее.
 * JSON_EXTRACT не мог использовать индекс — MySQL парсил JSON
 * для каждой строки. O(N). Теперь — B-tree lookup. O(log N).
 * 
 * [Мириам]: Я проверила все методы. findByName и findByNameAndPid
 * используют JOIN с text. Это работает, но можно было бы и быстрее.
 * Пока — оставим как есть. Не критично.
 * 
 * [Лорелея]: Я добавила PHPDoc для всех методов. Раньше были
 * только у некоторых. Теперь — у всех. IDE подсказывает,
 * а разработчик понимает, что метод делает и что возвращает.
 * 
 * [Мириам]: logAdminAction() оставлен как есть. Он пишет в файл,
 * не в базу. Это правильно — логи не должны зависеть от базы.
 */

namespace Jan\Trinity\Core\Repository;

use Jan\Trinity\Core\DatabaseService;
use Psr\Log\LoggerInterface;

class NeuronRepository
{
	/** @var DatabaseService — сервис базы данных */
	private DatabaseService $db;

	/**
     * @var LoggerInterface — логгер для admin-событий.
     * [Лорелея]: Теперь через LoggerInterface. Вместо file_put_contents.
     * Это даёт ротацию, уровни, формат. И — единый подход с Monitor и Guard.
     */
    private LoggerInterface $logger;

	/**
	 * Конструктор.
	 * DatabaseService внедряется через DI-контейнер.
	 * 
	 * @param DatabaseService $db
	 */
	public function __construct(DatabaseService $db, LoggerInterface $logger)
	{
		$this->db = $db;
		$this->logger = $logger;
	}

	/**
	 * Возвращает соединение с базой.
	 * 
	 * [Лорелея]: Иногда нужен прямой доступ к Connection —
	 * для CTE-запросов или сложных JOIN. Это «лазейка»,
	 * но осознанная. Не всё можно выразить через методы репозитория.
	 * 
	 * @return \Doctrine\DBAL\Connection
	 */
	public function getConnection(): \Doctrine\DBAL\Connection
	{
		return $this->db->getConnection();
	}

	// ============================================
	// СОЗДАНИЕ
	// ============================================

	/**
	 * Создать новый нейрон.
	 * 
	 * [Мириам]: data всегда кодируется в JSON с JSON_UNESCAPED_UNICODE.
	 * Это важно — иначе русские буквы превратятся в \uXXXX.
	 * 
	 * [Лорелея]: После создания — логируем. Чтобы знать, кто что создал.
	 * 
	 * @param string $type — тип нейрона (tree, item, file, user, config, route, ...)
	 * @param array $data — данные для JSON-поля
	 * @param int|null $pid — родительский нейрон (NULL = корень)
	 * @param int|null $text — ключ текста
	 * @param int|null $tree — классификатор
	 * @return int — id созданного нейрона
	 */
	public function create(string $type, $data = [], ?int $pid = null, ?int $text = null, ?int $tree = null): int
	{
		$conn = $this->db->getConnection();

		$conn->insert('neuron', [
			'pid'  => $pid,
			'type' => $type,
			'text' => $text,
			'tree' => $tree,
			'data' => $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
			'date' => date('Y-m-d H:i:s'),
		]);

		$id = (int) $conn->lastInsertId();

		// Логируем
		$this->logAdminAction('create', [
			'neuron_id' => $id,
			'type'      => $type,
			'pid'       => $pid,
		]);

		return $id;
	}

	// ============================================
	// ОБНОВЛЕНИЕ
	// ============================================

	/**
	 * Обновить нейрон.
	 * 
	 * [Лорелея]: Если data — массив, кодируем в JSON. Если строка —
	 * оставляем как есть. Это на случай, если кто-то передал
	 * уже закодированный JSON.
	 * 
	 * [Мириам]: Не логируем data — оно может быть большим.
	 * Логируем только имена полей. Так безопаснее и легче.
	 * 
	 * @param int $id — id нейрона
	 * @param array $fields — поля для обновления (type, pid, text, tree, data)
	 * @return void
	 */
	public function update(int $id, array $fields): void
	{
		$conn = $this->db->getConnection();

		if (isset($fields['data']) && is_array($fields['data'])) {
			$fields['data'] = json_encode($fields['data'], JSON_UNESCAPED_UNICODE);
		}

		$conn->update('neuron', $fields, ['id' => $id]);

		// Логируем (не записываем data — может быть большим)
		$logFields = array_keys($fields);
		if (isset($fields['data'])) {
			$logFields = array_diff($logFields, ['data']);
			$logFields[] = 'data';
		}

		$this->logAdminAction('update', [
			'neuron_id' => $id,
			'fields'    => $logFields,
		]);
	}

	// ============================================
	// УДАЛЕНИЕ (МЯГКОЕ)
	// ============================================

	/**
	 * Мягкое удаление нейрона (устанавливает deleted_at в data).
	 * 
	 * [Лорелея]: Мы НЕ удаляем физически. Мы помечаем.
	 * is_deleted — виртуальный столбец, который вычисляется из
	 * data.deleted_at. Это позволяет восстановить нейрон.
	 * 
	 * [Мириам]: Если $deleteSynapses = true — удаляем и связи.
	 * Это нужно, когда удаляем нейрон совсем. Если false —
	 * оставляем. Например, при удалении файла внутри элемента.
	 * 
	 * @param int $id — id нейрона
	 * @param bool $deleteSynapses — удалять ли связанные синапсы
	 * @return void
	 */
	public function delete(int $id, bool $deleteSynapses = true): void
	{
		$conn = $this->db->getConnection();

		$neuron = $this->findById($id);
		if (!$neuron) return;

		$data = $neuron['data'] ?? [];
		if (is_string($data)) {
			$data = json_decode($data, true);
		}
		$data['deleted_at'] = date('Y-m-d H:i:s');

		$conn->update('neuron', [
			'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
		], ['id' => $id]);

		if ($deleteSynapses) {
			$conn->executeStatement('DELETE FROM synapse WHERE parent = ? OR child = ?', [$id, $id]);
		}

		// Логируем
		$this->logAdminAction('delete', [
			'neuron_id' => $id,
			'type'      => $neuron['type'] ?? 'unknown',
		]);
	}

	// ============================================
	// ПОИСК ПО ID
	// ============================================

	/**
	 * Найти нейрон по ID.
	 * 
	 * @param int $id
	 * @return array|null
	 */
	public function findById(int $id): ?array
	{
		return $this->db->getConnection()->executeQuery(
			'SELECT * FROM neuron WHERE id = ? AND is_deleted = 0',
			[$id]
		)->fetchAssociative() ?: null;
	}

	// ============================================
	// ПОИСК ПО SLUG
	// ============================================

	/**
	 * Найти нейрон по slug.
	 * 
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Раньше здесь был JSON_EXTRACT.
	 * Это не могло использовать индекс — MySQL парсил JSON для
	 * каждой строки. O(N) на каждый вызов. Теперь — WHERE slug = ?.
	 * 
	 * [Мириам]: slug — это STORED generated column. MySQL
	 * поддерживает его в индексе idx_pid_slug. Значит, поиск идёт
	 * по B-tree. O(log N). Это в разы быстрее на больших базах.
	 * 
	 * @param string $slug
	 * @return array|null
	 */
	public function findBySlug(string $slug): ?array
	{
		return $this->db->getConnection()->executeQuery(
			'SELECT * FROM neuron WHERE slug = ? AND is_deleted = 0 LIMIT 1',
			[$slug]
		)->fetchAssociative() ?: null;
	}

	/**
	 * Найти нейрон по slug и pid.
	 * 
	 * [Лорелея]: То же самое. JSON_EXTRACT → slug.
	 * Индекс idx_pid_slug используется. Это критично для findChildren,
	 * потому что он вызывается часто.
	 * 
	 * @param string $slug
	 * @param int|null $pid
	 * @return array|null
	 */
	public function findBySlugAndPid(string $slug, ?int $pid): ?array
	{
		$conn = $this->db->getConnection();

		return $conn->executeQuery(
			'SELECT * FROM neuron WHERE slug = ? AND pid ' .
			($pid === null ? 'IS NULL' : '= ?') .
			' AND is_deleted = 0 LIMIT 1',
			$pid === null ? [$slug] : [$slug, $pid]
		)->fetchAssociative() ?: null;
	}

	// ============================================
	// ПОИСК ПО ИМЕНИ (ЧЕРЕЗ TEXT)
	// ============================================

	/**
	 * Найти нейрон по имени (через таблицу text).
	 * 
	 * [Мириам]: Здесь JOIN с text. Использует text.name.
	 * Это медленнее, чем поиск по slug, но иначе никак —
	 * имя хранится в text, а не в neuron.
	 * 
	 * @param string $name — имя в text.name
	 * @param string $lang — язык (по умолчанию 'ru')
	 * @return array|null
	 */
	public function findByName(string $name, string $lang = 'ru'): ?array
	{
		return $this->db->getConnection()->executeQuery(
			"SELECT n.* FROM neuron n 
			 JOIN text t ON n.text = t.key 
			 WHERE t.name = ? AND t.lang = ? AND n.is_deleted = 0 
			 LIMIT 1",
			[$name, $lang]
		)->fetchAssociative() ?: null;
	}

	/**
	 * Найти нейрон по имени внутри определённого родителя.
	 * 
	 * @param string $name — имя
	 * @param int|null $pid — родитель (NULL для корня)
	 * @param string $lang — язык
	 * @return array|null
	 */
	public function findByNameAndPid(string $name, ?int $pid, string $lang = 'ru'): ?array
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT n.* FROM neuron n 
				JOIN text t ON n.text = t.key 
				WHERE t.name = ? AND t.lang = ? AND n.is_deleted = 0 
				AND n.pid " . ($pid === null ? "IS NULL" : "= ?") . "
				LIMIT 1";

		$params = $pid === null ? [$name, $lang] : [$name, $lang, $pid];

		return $conn->executeQuery($sql, $params)->fetchAssociative() ?: null;
	}

	// ============================================
	// ПОИСК ДЕТЕЙ
	// ============================================

	/**
	 * Найти всех детей нейрона.
	 * 
	 * [Лорелея]: Тот же паттерн — LEFT JOIN с подзапросом
	 * child_counts вместо коррелированного подзапроса.
	 * 
	 * [Мириам]: Раньше child_count считался для каждого
	 * ребёнка отдельно. Теперь — один GROUP BY на всех.
	 * 
	 * @param int|null $parentId
	 * @param string|null $type
	 * @return array
	 */
	public function findChildren(?int $parentId, ?string $type = null): array
	{
		$conn = $this->db->getConnection();

		$childCountSubquery = "
			SELECT pid, COUNT(*) as child_count
			FROM neuron
			WHERE is_deleted = 0 AND pid IS NOT NULL
			GROUP BY pid
		";

		$sql = "SELECT n.*, 
				COALESCE(cc.child_count, 0) as child_count
				FROM neuron n
				LEFT JOIN ({$childCountSubquery}) cc 
					ON cc.pid = n.id
				WHERE n.pid " . ($parentId === null ? "IS NULL" : "= ?") . "
				AND n.is_deleted = 0";

		$params = $parentId === null ? [] : [$parentId];

		if ($type) {
			$sql .= " AND n.type = ?";
			$params[] = $type;
		}

		$sql .= " ORDER BY n.sort, n.id";

		return $conn->executeQuery($sql, $params)->fetchAllAssociative();
	}

	/**
	 * Найти детей с текстом на определённом языке.
	 * 
	 * [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. Раньше здесь был GROUP BY n.id,
	 * но MySQL 8 с ONLY_FULL_GROUP_BY не разрешает выбирать t.name
	 * без агрегатной функции. Это логично: у одного нейрона может
	 * быть несколько t.name (разные языки). Но у нас — фильтр по
	 * t.lang = ?, значит, только одна строка t. GROUP BY не нужен.
	 * 
	 * [Мириам]: Убрала GROUP BY. Вместо этого — LEFT JOIN с text
	 * и LEFT JOIN с child_counts. child_counts — это подзапрос
	 * с GROUP BY pid внутри. Он даёт одну строку на pid. Дубликатов
	 * не будет. А t — уже отфильтрован по языку. Одна строка.
	 * 
	 * @param int|null $parentId
	 * @param string $lang
	 * @param string|null $type
	 * @return array
	 */
	public function findChildrenWithText(?int $parentId, string $lang = 'ru', ?string $type = null): array
	{
		$conn = $this->db->getConnection();

		// [Мириам]: Подзапрос для подсчёта детей. GROUP BY внутри.
		// Это законно — там все поля агрегатные или в GROUP BY.
		$childCountSubquery = "
			SELECT pid, COUNT(*) as child_count
			FROM neuron
			WHERE is_deleted = 0 AND pid IS NOT NULL
			GROUP BY pid
		";

		// [Лорелея]: Убрала GROUP BY n.id из основного запроса.
		// t.name уже отфильтрован по lang. Один нейрон — одна строка t.
		// child_count — из подзапроса, одна строка на pid.
		$sql = "SELECT n.*, 
				t.name,
				COALESCE(cc.child_count, 0) as child_count
				FROM neuron n
				LEFT JOIN text t 
					ON t.key = n.text 
					AND t.lang = ? 
					AND t.is_active = 1
				LEFT JOIN ({$childCountSubquery}) cc 
					ON cc.pid = n.id
				WHERE n.pid " . ($parentId === null ? "IS NULL" : "= ?") . "
				AND n.is_deleted = 0";

		$params = [$lang];

		if ($parentId !== null) {
			$params[] = $parentId;
		}

		if ($type) {
			$sql .= " AND n.type = ?";
			$params[] = $type;
		}

		$sql .= " ORDER BY n.sort, n.id";

		return $conn->executeQuery($sql, $params)->fetchAllAssociative();
	}

	// ============================================
	// ПРОВЕРКА ДУБЛИКАТОВ
	// ============================================

	/**
	 * Проверить существование нейрона по уникальным признакам.
	 * Используется для предотвращения дубликатов при импорте.
	 * 
	 * @param string $type — тип нейрона
	 * @param int|null $tree — классификатор
	 * @param int|null $text — ключ текста
	 * @param int|null $pid — родитель
	 * @return int|null — id существующего нейрона или null
	 */
	public function findDuplicate(string $type, ?int $tree, ?int $text, ?int $pid): ?int
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT id FROM neuron WHERE type = ? AND tree = ? AND text = ? AND pid " . ($pid === null ? "IS NULL" : "= ?") . " AND is_deleted = 0 LIMIT 1";
		$params = $pid === null ? [$type, $tree, $text] : [$type, $tree, $text, $pid];

		$result = $conn->executeQuery($sql, $params)->fetchAssociative();
		return $result ? (int) $result['id'] : null;
	}

	// ============================================
	// ПОИСК С ТЕКСТОМ
	// ============================================

	/**
	 * Найти нейрон с текстом (JOIN с text).
	 * 
	 * @param int $id
	 * @param string $lang
	 * @return array|null
	 */
	public function findWithText(int $id, string $lang = 'ru'): ?array
	{
		return $this->db->getConnection()->executeQuery(
			"SELECT n.*, t.name, t.text as description
			FROM neuron n
			LEFT JOIN text t ON n.text = t.key AND t.lang = ? AND t.is_active = 1
			WHERE n.id = ? AND n.is_deleted = 0",
			[$lang, $id]
		)->fetchAssociative() ?: null;
	}

	// ============================================
	// ПОИСК ПОЛЬЗОВАТЕЛЯ
	// ============================================

	/**
	 * Найти пользователя по логину или email.
	 * 
	 * [Лорелея]: Сначала ищем по login, потом по email.
	 * Это позволяет входить и по логину, и по email.
	 * 
	 * @param string $login
	 * @return array|null
	 */
	public function findByLoginOrEmail(string $login): ?array
	{
		$conn = $this->db->getConnection();

		// Сначала по login
		$result = $conn->executeQuery(
			"SELECT * FROM neuron WHERE type = 'user' AND login = ? AND is_deleted = 0",
			[$login]
		)->fetchAssociative();

		if ($result) return $result;

		// Потом по email
		return $conn->executeQuery(
			"SELECT * FROM neuron WHERE type = 'user' AND email = ? AND is_deleted = 0",
			[$login]
		)->fetchAssociative() ?: null;
	}

	// ============================================
	// ПОИСК С ГЕОМЕТРИЕЙ
	// ============================================

	/**
	 * Найти нейроны с геометрией (для карты).
	 * 
	 * [Лорелея]: Используется MapController. Ищет нейроны,
	 * у которых в data есть geometry. Это может быть медленно
	 * на больших базах — JSON_EXTRACT. Пока терпимо.
	 * 
	 * @param int|null $treeId
	 * @param int|null $pid
	 * @return array
	 */
	public function findWithGeometry(?int $treeId = null, ?int $pid = null): array
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT n.id, n.data, n.type,
				(SELECT t.name FROM text t WHERE t.key = n.text AND t.lang = 'ru' LIMIT 1) as display_name
				FROM neuron n
				WHERE JSON_EXTRACT(n.data, '$.geometry') IS NOT NULL
				AND n.is_deleted = 0";

		$params = [];

		if ($treeId) {
			$sql .= " AND n.tree = ?";
			$params[] = $treeId;
		}

		if ($pid) {
			$sql .= " AND n.pid = ?";
			$params[] = $pid;
		}

		$sql .= " ORDER BY n.sort, n.id";

		return $conn->executeQuery($sql, $params)->fetchAllAssociative();
	}

	// ============================================
	// СЛИЯНИЕ DATA
	// ============================================

	/**
	 * Дополнить data нейрона, сохранив существующие поля.
	 * 
	 * [Мириам]: Это важно при импорте. Мы не заменяем data
	 * целиком, а дополняем. Так не теряются поля, которые
	 * уже были. Например, deleted_at.
	 * 
	 * @param int $id — id нейрона
	 * @param array $newData — новые данные для слияния
	 * @return void
	 */
	public function mergeData(int $id, array $newData): void
	{
		$neuron = $this->findById($id);
		if (!$neuron) return;

		$existingData = $neuron['data'] ?? [];
		if (is_string($existingData)) {
			$existingData = json_decode($existingData, true);
		}

		$merged = array_merge($existingData, $newData);

		$this->update($id, ['data' => $merged]);
	}

	// ============================================
	// ЛОГИРОВАНИЕ
	// ============================================

    /**
     * Записать действие администратора.
     * [Лорелея]: Вместо file_put_contents — $this->logger->info().
     * Monolog сам добавит дату, уровень, отформатирует контекст.
     * И — ротация. И — уровни. И — единый подход.
     */
    public function logAdminAction(string $action, array $context = []): void
    {
        if (!isset($context['user'])) {
            $context['user'] = $_SESSION['user_login'] ?? 'system';
        }

        $this->logger->info($action, $context);
    }

	/**
	 * Найти все file-нейроны для набора tree-родителей.
	 * 
	 * [Лорелея]: Это решение N+1 в галерее. Вместо N запросов
	 * (по одному на каждую папку) — один запрос с IN.
	 * 
	 * [Мириам]: Возвращает массив, сгруппированный по pid.
	 * Для каждой папки — массив её файлов. Дальше PHP берёт
	 * первый файл для превьюшки.
	 * 
	 * @param int[] $treeIds — массив id tree-папок
	 * @return array — [pid => [file, file, ...], ...]
	 */
	public function findFilesForTrees(array $treeIds): array
	{
		if (empty($treeIds)) {
			return [];
		}

		$conn = $this->db->getConnection();

		// [Мириам]: IN с параметрами через DBAL. Он подставит
		// каждый id как отдельный параметр. Безопасно.
		$placeholders = implode(',', array_fill(0, count($treeIds), '?'));

		$sql = "SELECT * FROM neuron 
				WHERE pid IN ({$placeholders}) 
				AND type = 'file' 
				AND is_deleted = 0 
				ORDER BY pid, sort, id";

		$rows = $conn->executeQuery($sql, $treeIds)->fetchAllAssociative();

		// [Лорелея]: Группируем по pid. Для каждой папки — массив файлов.
		// Первый файл — превьюшка. Остальные — на будущее.
		$result = [];
		foreach ($rows as $row) {
			$pid = (int) $row['pid'];
			if (!isset($result[$pid])) {
				$result[$pid] = [];
			}
			$result[$pid][] = $row;
		}

		return $result;
	}

	/**
	 * Загрузить ВСЕ конфиги одним запросом.
	 *
	 * [Лорелея]: Оставляем. Потому что это — просто данные.
	 * Из таблицы neuron. И — это — репозиторий нейронов.
	 * А — конфиги — это тоже нейроны. Просто type='config'.
	 *
	 * [Мириам]: НО. findConfigValue() — удаляем. Потому что
	 * он — про кэш. А кэш — не в репозитории. Кэш — в ConfigService.
	 * А findAllConfigValues() — это просто "дай все нейроны
	 * типа config". Это — репозиторий. Это — его работа.
	 *
	 * @return array [key => value]
	 */
	public function findAllConfigValues(): array
	{
		$rows = $this->db->getConnection()->executeQuery(
			"SELECT JSON_EXTRACT(data, '$.key') as k, JSON_EXTRACT(data, '$.value') as v 
			FROM neuron 
			WHERE type = 'config' AND is_deleted = 0"
		)->fetchAllAssociative();

		$result = [];
		foreach ($rows as $row) {
			$key = $row['k'] !== null ? json_decode($row['k'], true) : null;
			$value = $row['v'] !== null ? json_decode($row['v'], true) : null;
			if ($key !== null) {
				$result[$key] = $value;
			}
		}
		return $result;
	}
}

