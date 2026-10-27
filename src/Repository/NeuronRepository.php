<?php

/**
 * РЕПОЗИТОРИЙ NEURON
 * ==================
 *
 * Единый слой для работы с таблицей neuron.
 * Инкапсулирует создание, обновление, удаление и поиск нейронов.
 *
 * === ЧТО ИЗМЕНИЛОСЬ ПОСЛЕ РЕВЬЮ ===
 *
 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. update() теперь имеет белый
 * список столбцов. Внутри метода. А не снаружи. Чтобы нельзя
 * было тронуть id, hash, slug, sort, is_deleted. Защита в глубину.
 * Без «пиздеца». Без «потом». Без «завтра».
 *
 * [Мириам]: И — logAdminAction() больше не использует $_SESSION.
 * Потому что репозиторий не знает о сессии. И — не должен.
 * Без «пиздеца». И — «навсегда».
 *
 * @package Jan\Trinity\Core\Repository
 */

namespace Jan\Trinity\Core\Repository;

use Jan\Trinity\Core\DatabaseService;
use Psr\Log\LoggerInterface;

class NeuronRepository
{
	/** @var DatabaseService — сервис базы данных */
	private DatabaseService $db;

	/** @var LoggerInterface — логгер для admin-событий */
	private LoggerInterface $logger;

	/**
	 * @var string[] Белый список столбцов для update().
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Только эти столбцы можно
	 * обновить. id, hash, slug, sort, is_deleted — нельзя.
	 * Потому что они — генерируемые. Или — служебные.
	 * Без «пиздеца». Без «потом». Без «завтра».
	 */
	private const UPDATABLE_COLUMNS = ['type', 'pid', 'text', 'tree', 'data', 'date'];

	public function __construct(DatabaseService $db, LoggerInterface $logger)
	{
		$this->db = $db;
		$this->logger = $logger;
	}

	public function getConnection(): \Doctrine\DBAL\Connection
	{
		return $this->db->getConnection();
	}

	// ============================================
	// СОЗДАНИЕ
	// ============================================

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
	 * [Лорелея]: ГЛАВНОЕ ИСПРАВЛЕНИЕ. Белый список столбцов.
	 * Внутри метода. А не снаружи. Чтобы нельзя было тронуть id.
	 * Или — hash. Или — slug. Или — sort. Или — is_deleted.
	 * Без «пиздеца». Без «потом». Без «завтра».
	 *
	 * [Мириам]: И — это правильно. Потому что метод — открытый API.
	 * И — любой плагин может передать что угодно. А мы — фильтруем.
	 * В глубину. И — «навсегда».
	 *
	 * @param int $id — id нейрона
	 * @param array $fields — поля для обновления
	 * @return void
	 */
	public function update(int $id, array $fields): void
	{
		$conn = $this->db->getConnection();

		// [Лорелея]: Белый список. Только эти столбцы.
		$fields = array_intersect_key($fields, array_flip(self::UPDATABLE_COLUMNS));

		if (empty($fields)) {
			return;
		}

		if (isset($fields['data']) && is_array($fields['data'])) {
			$fields['data'] = json_encode($fields['data'], JSON_UNESCAPED_UNICODE);
		}

		$conn->update('neuron', $fields, ['id' => $id]);

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
			$this->softDeleteSynapses($id);
		}

		$this->logAdminAction('delete', [
			'neuron_id' => $id,
			'type'      => $neuron['type'] ?? 'unknown',
		]);
	}

	private function softDeleteSynapses(int $neuronId): void
	{
		$conn = $this->db->getConnection();

		$synapses = $conn->executeQuery(
			"SELECT id, data FROM synapse WHERE parent = ? OR child = ?",
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

	// ============================================
	// ПОИСК ПО ID
	// ============================================

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

	public function findBySlug(string $slug): ?array
	{
		return $this->db->getConnection()->executeQuery(
			'SELECT * FROM neuron WHERE slug = ? AND is_deleted = 0 LIMIT 1',
			[$slug]
		)->fetchAssociative() ?: null;
	}

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

	public function findChildrenWithText(?int $parentId, string $lang = 'ru', ?string $type = null): array
	{
		$conn = $this->db->getConnection();

		$childCountSubquery = "
			SELECT pid, COUNT(*) as child_count
			FROM neuron
			WHERE is_deleted = 0 AND pid IS NOT NULL
			GROUP BY pid
		";

		$sql = "SELECT n.*,
				t.name,
				t.text as text_content,
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

	public function findDuplicate(string $type, ?int $tree, ?int $text, ?int $pid): ?int
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT id FROM neuron WHERE type = ? AND tree = ? AND text = ? AND pid " . ($pid === null ? "IS NULL" : "= ?") . " AND is_deleted = 0 LIMIT 1";
		$params = $pid === null ? [$type, $tree, $text] : [$type, $tree, $text, $pid];

		$result = $conn->executeQuery($sql, $params)->fetchAssociative();
		return $result ? (int) $result['id'] : null;
	}

	// ============================================
	// ПОИСК ПОЛЬЗОВАТЕЛЯ
	// ============================================

	public function findByLoginOrEmail(string $login): ?array
	{
		$conn = $this->db->getConnection();

		$result = $conn->executeQuery(
			"SELECT * FROM neuron WHERE type = 'user' AND login = ? AND is_deleted = 0",
			[$login]
		)->fetchAssociative();

		if ($result) return $result;

		return $conn->executeQuery(
			"SELECT * FROM neuron WHERE type = 'user' AND email = ? AND is_deleted = 0",
			[$login]
		)->fetchAssociative() ?: null;
	}

	// ============================================
	// ПОИСК С ГЕОМЕТРИЕЙ
	// ============================================

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
	 *
	 * [Лорелея]: Вместо $_SESSION — просто 'system'. Потому что
	 * репозиторий не знает о сессии. И — не должен. Без «пиздеца».
	 *
	 * [Мириам]: И — это правильно. Потому что репозиторий — слой данных.
	 * А не слой сессии. И — «навсегда».
	 */
	public function logAdminAction(string $action, array $context = []): void
	{
		if (!isset($context['user'])) {
			$context['user'] = 'system';
		}

		$this->logger->info($action, $context);
	}

	// ============================================
	// ВСПОМОГАТЕЛЬНЫЕ
	// ============================================

	public function findFilesForTrees(array $treeIds): array
	{
		if (empty($treeIds)) {
			return [];
		}

		$conn = $this->db->getConnection();

		$placeholders = implode(',', array_fill(0, count($treeIds), '?'));

		$sql = "SELECT * FROM neuron
				WHERE pid IN ({$placeholders})
				AND type = 'file'
				AND is_deleted = 0
				ORDER BY pid, sort, id";

		$rows = $conn->executeQuery($sql, $treeIds)->fetchAllAssociative();

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