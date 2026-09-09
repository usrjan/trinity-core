<?php

/**
 * РЕПОЗИТОРИЙ NEURON
 * 
 * Единый слой для работы с таблицей neuron.
 * Инкапсулирует создание, обновление, удаление и поиск нейронов.
 */

namespace Jan\Trinity\Core\Repository;

use Jan\Trinity\Core\DatabaseService;

class NeuronRepository
{
	private DatabaseService $db;

	public function __construct(DatabaseService $db)
	{
		$this->db = $db;
	}

	public function getConnection(): \Doctrine\DBAL\Connection
	{
		return $this->db->getConnection();
	}

	/**
	 * Создать новый нейрон.
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

	/**
	 * Обновить нейрон.
	 * 
	 * @param int $id — id нейрона
	 * @param array $fields — поля для обновления (type, pid, text, tree, data)
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

	/**
	 * Мягкое удаление нейрона (устанавливает deleted_at в data).
	 * 
	 * @param int $id — id нейрона
	 * @param bool $deleteSynapses — удалять ли связанные синапсы
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

	/**
	 * Найти нейрон по slug.
	 * 
	 * @param string $slug
	 * @return array|null
	 */
	public function findBySlug(string $slug): ?array
	{
		return $this->db->getConnection()->executeQuery(
			"SELECT * FROM neuron WHERE JSON_EXTRACT(data, '$.slug') = ? AND is_deleted = 0 LIMIT 1",
			[$slug]
		)->fetchAssociative() ?: null;
	}

	public function findBySlugAndPid(string $slug, ?int $pid): ?array
	{
		$conn = $this->db->getConnection();

		return $conn->executeQuery(
			"SELECT * FROM neuron WHERE JSON_EXTRACT(data, '$.slug') = ? AND pid " . 
			($pid === null ? "IS NULL" : "= ?") . 
			" AND is_deleted = 0 LIMIT 1",
			$pid === null ? [$slug] : [$slug, $pid]
		)->fetchAssociative() ?: null;
	}

	/**
	 * Найти нейрон по имени (через таблицу text).
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

	/**
	 * Найти всех детей нейрона.
	 * 
	 * @param int|null $parentId — id родителя (NULL для корневых)
	 * @param string|null $type — фильтр по типу (опционально)
	 * @return array
	 */
	public function findChildren(?int $parentId, ?string $type = null): array
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT n.*, 
				(SELECT t.name FROM text t WHERE t.key = n.text AND t.lang = 'ru' LIMIT 1) as name,
				(SELECT COUNT(*) FROM neuron c WHERE c.pid = n.id AND c.is_deleted = 0) as child_count
				FROM neuron n
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

	public function findChildrenWithText(?int $parentId, string $lang = 'ru', ?string $type = null): array
	{
		$conn = $this->db->getConnection();
		
		$sql = "SELECT n.*, 
				(SELECT t.name FROM text t WHERE t.key = n.text AND t.lang = ? AND t.is_active = 1 LIMIT 1) as name,
				(SELECT COUNT(*) FROM neuron c WHERE c.pid = n.id AND c.is_deleted = 0) as child_count
				FROM neuron n
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

	/**
	 * Проверить существование нейрона по уникальным признакам.
	 * Используется для предотвращения дубликатов.
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

	public function findConfigValue(string $key, $default = null): mixed
	{
		$result = $this->db->getConnection()->executeQuery(
			"SELECT JSON_EXTRACT(data, '$.value') as value FROM neuron 
			WHERE type = 'config' AND JSON_EXTRACT(data, '$.key') = ? AND is_deleted = 0 LIMIT 1",
			[$key]
		)->fetchAssociative();
		
		if (!$result) return $default;
		
		$value = $result['value'];
		if (is_string($value)) $value = json_decode($value, true);
		return $value ?? $default;
	}

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

	/**
	 * Дополнить data нейрона, сохранив существующие поля.
	 * 
	 * @param int $id — id нейрона
	 * @param array $newData — новые данные для слияния
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

	/**
	 * Записывает действие в лог админа.
	 */
	public function logAdminAction(string $action, array $context = []): void
	{
		$logDir = dirname(__DIR__, 2) . '/var/log';
		if (!is_dir($logDir)) {
			mkdir($logDir, 0775, true);
		}

		$user = $_SESSION['user_login'] ?? 'system';
		$logFile = $logDir . '/admin.log';
		
		$entry = sprintf(
			"[%s] %s: %s %s\n",
			date('Y-m-d H:i:s'),
			$user,
			$action,
			json_encode($context, JSON_UNESCAPED_UNICODE)
		);

		file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
	}
}
