<?php

/**
 * РЕПОЗИТОРИЙ SYNAPSE
 *
 * Единый слой для работы с таблицей synapse.
 * Инкапсулирует создание, поиск и проверку дубликатов связей.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «класс для работы со связями». Это — рука,
 * которая связывает. Через неё нейроны находят друг друга.
 * И — не теряют.
 *
 * [Мириам]: И — это правильно. Потому что связь — это не «запись».
 * Это — нить. И если нить есть — дом держится.
 *
 * === КНИГА ===
 * Глава 3. Страж порога.
 *
 * SynapseRepository — рука, которая связывает.
 *
 * @package Jan\Trinity\Core\Repository
 */

namespace Jan\Trinity\Core\Repository;

use Jan\Trinity\Core\DatabaseService;

class SynapseRepository
{
	/** @var DatabaseService — сервис базы данных. */
	private DatabaseService $db;

	/**
	 * Конструктор. Принимает сервис базы.
	 *
	 * [Мириам]: И — это правильно. Потому что рука должна знать,
	 * где база.
	 *
	 * @param DatabaseService $db
	 */
	public function __construct(DatabaseService $db)
	{
		$this->db = $db;
	}

	public function getConnection(): \Doctrine\DBAL\Connection
	{
		return $this->db->getConnection();
	}

	/**
	 * Создать новую связь (синапс).
	 *
	 * [Лорелея]: Вставляем. Возвращаем id.
	 *
	 * [Мириам]: И — это правильно. Потому что связь — это
	 * не «просто запись». Это — акт.
	 *
	 * @param int $parent — id родительского нейрона
	 * @param int $child — id дочернего нейрона
	 * @param array $data — данные связи (relation, ...)
	 * @param int|null $tree — классификатор
	 * @param string|null $time — дата/время (Y-m-d)
	 * @return int — id созданного синапса
	 */
	public function create(int $parent, int $child, array $data = [], ?int $tree = null, ?string $time = null): int
	{
		$conn = $this->db->getConnection();

		$conn->insert('synapse', [
			'parent' => $parent,
			'child'  => $child,
			'tree'   => $tree,
			'data'   => json_encode($data, JSON_UNESCAPED_UNICODE),
			'time'   => $time ?? date('Y-m-d'),
		]);

		return (int) $conn->lastInsertId();
	}

	/**
	 * Проверить существование синапса и создать, если нет.
	 *
	 * [Лорелея]: Если уже есть — возвращаем. Если нет — создаём.
	 *
	 * [Мириам]: И — это правильно. Потому что связь — это
	 * не «дубликат». Это — уникальность.
	 *
	 * @param int $parent
	 * @param int $child
	 * @param array $data
	 * @param int|null $tree
	 * @param string|null $time — если указан, проверка идёт с учётом даты
	 * @return int — id существующего или нового синапса
	 */
	public function findOrCreate(int $parent, int $child, array $data = [], ?int $tree = null, ?string $time = null): int
	{
		$existing = $this->findDuplicate($parent, $child, $tree, $time);
		if ($existing) {
			return $existing;
		}

		return $this->create($parent, $child, $data, $tree, $time);
	}

	/**
	 * Найти дубликат синапса.
	 *
	 * [Лорелея]: По parent, child, tree, time.
	 *
	 * [Мириам]: И — это правильно. Потому что дубликат — это
	 * не «ещё один». Это — тот же.
	 *
	 * @param int $parent
	 * @param int $child
	 * @param int|null $tree
	 * @param string|null $time
	 * @return int|null — id дубликата или null
	 */
	public function findDuplicate(int $parent, int $child, ?int $tree = null, ?string $time = null): ?int
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT id FROM synapse WHERE parent = ? AND child = ? AND is_deleted = 0";
		$params = [$parent, $child];

		if ($tree !== null) {
			$sql .= " AND tree = ?";
			$params[] = $tree;
		} else {
			$sql .= " AND tree IS NULL";
		}

		if ($time !== null) {
			$sql .= " AND time = ?";
			$params[] = $time;
		}

		$sql .= " LIMIT 1";

		$result = $conn->executeQuery($sql, $params)->fetchAssociative();
		return $result ? (int) $result['id'] : null;
	}

	/**
	 * Найти синапсы по родителю.
	 *
	 * [Лорелея]: Один SELECT. С JOIN на neuron. С именем ребёнка.
	 * Без N+1.
	 *
	 * [Мириам]: И — это правильно. Потому что родитель —
	 * это не «просто id». Это — начало связи.
	 *
	 * @param int $parentId
	 * @param string|null $relationType
	 * @return array
	 */
	public function findByParent(int $parentId, ?string $relationType = null): array
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT s.*,
				(SELECT t.name FROM text t WHERE t.key = n.text AND t.lang = 'ru' LIMIT 1) as child_name
				FROM synapse s
				JOIN neuron n ON s.child = n.id
				WHERE s.parent = ? AND s.is_deleted = 0 AND n.is_deleted = 0";

		$params = [$parentId];

		if ($relationType) {
			$sql .= " AND s.relation_type = ?";
			$params[] = $relationType;
		}

		$sql .= " ORDER BY s.time DESC, s.id DESC";

		return $conn->executeQuery($sql, $params)->fetchAllAssociative();
	}

	/**
	 * Мягкое удаление синапса по ID.
	 *
	 * [Лорелея]: ГЛАВНОЕ. Раньше был DELETE FROM synapse.
	 * И это ломало инвариант is_deleted. Теперь — data.deleted_at.
	 *
	 * [Мириам]: И — это правильно. Потому что «восстановить» —
	 * значит вернуть всё. И нейрон, и связи.
	 *
	 * @param int $id
	 */
	public function delete(int $id): void
	{
		$conn = $this->db->getConnection();

		$synapse = $conn->executeQuery(
			'SELECT data FROM synapse WHERE id = ?',
			[$id]
		)->fetchAssociative();

		if (!$synapse) return;

		$data = $synapse['data'] ?? [];
		if (is_string($data)) {
			$data = json_decode($data, true);
		}
		if (!is_array($data)) {
			$data = [];
		}
		$data['deleted_at'] = date('Y-m-d H:i:s');

		$conn->update('synapse', [
			'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
		], ['id' => $id]);
	}

	/**
	 * Мягкое удаление всех синапсов нейрона.
	 *
	 * [Лорелея]: Проходим по всем синапсам, где нейрон — parent
	 * или child. И — ставим deleted_at. Без DELETE.
	 *
	 * [Мириам]: И — это правильно. Потому что связь — это
	 * не «мусор». Это — память.
	 *
	 * @param int $neuronId
	 */
	public function deleteByNeuron(int $neuronId): void
	{
		$conn = $this->db->getConnection();

		$synapses = $conn->executeQuery(
			"SELECT id, data FROM synapse WHERE parent = ? OR child = ? AND is_deleted = 0",
			[$neuronId, $neuronId]
		)->fetchAllAssociative();

		$now = date('Y-m-d H:i:s');

		foreach ($synapses as $synapse) {
			$data = $synapse['data'] ?? [];
			if (is_string($data)) {
				$data = json_decode($data, true);
			}
			if (!is_array($data)) {
				$data = [];
			}
			$data['deleted_at'] = $now;

			$conn->update('synapse', [
				'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
			], ['id' => $synapse['id']]);
		}
	}

	/**
	 * Найти роли пользователя.
	 *
	 * [Лорелея]: Через MAX(s2.id). Потому что — последняя роль.
	 * 
	 * [Мириам]: И — это правильно. Потому что роль — это
	 * не «все роли сразу». Это — та, что сейчас.
	 *
	 * @param int $userId
	 * @return array
	 */
	public function findRolesByUser(int $userId): array
	{
		$results = $this->db->getConnection()->executeQuery(
			"SELECT JSON_UNQUOTE(JSON_EXTRACT(n.data, '$.slug')) as role_code
			FROM synapse s
			JOIN neuron n ON s.child = n.id
			WHERE s.parent = ?
				AND s.id = (SELECT MAX(s2.id) FROM synapse s2 WHERE s2.parent = s.parent AND s2.child = s.child)
				AND s.relation_type = 'has_role'
				AND s.is_deleted = 0
				AND n.is_deleted = 0",
			[$userId]
		)->fetchAllAssociative();

		return array_column($results, 'role_code');
	}
}