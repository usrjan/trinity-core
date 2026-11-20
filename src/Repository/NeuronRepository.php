<?php
declare(strict_types=1);

/**
 * РЕПОЗИТОРИЙ NEURON
 *
 * Единый слой для работы с таблицей neuron.
 * Инкапсулирует создание, обновление, удаление и поиск нейронов.
 *
 * @package Jan\Trinity\Core\Repository
 */

namespace Jan\Trinity\Core\Repository;

use Jan\Trinity\Core\DatabaseService;
use Psr\Log\LoggerInterface;

class NeuronRepository
{
	/** @var DatabaseService — сервис базы данных. */
	private DatabaseService $db;

	/** @var LoggerInterface — логгер для admin-событий. */
	private LoggerInterface $logger;

	/**
	 * @var string[] Белый список столбцов для update().
	 */
	private const UPDATABLE_COLUMNS = ['type', 'pid', 'text', 'tree', 'data', 'date'];

	/**
	 * Конструктор. Принимает сервис базы и логгер.
	 *
	 * @param DatabaseService $db
	 * @param LoggerInterface $logger
	 */
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

	/**
	 * Создать нейрон.
	 *
	 * @param string $type — тип нейрона
	 * @param mixed $data — данные (JSON)
	 * @param int|null $pid — родитель
	 * @param int|null $text — текстовый ключ
	 * @param int|null $tree — классификатор
	 * @return int — id созданного нейрона
	 */
	public function create(
		string $type,
		$data = [],
		?int $pid = null,
		?int $text = null,
		?int $tree = null,
		?string $hash = null
	): int {
		if ($type === 'user' && $hash === null) {
			throw new \InvalidArgumentException('NeuronRepository: user requires a password hash');
		}

		$conn = $this->db->getConnection();

		if ($hash === null && in_array($type, ['item', 'tree', 'file'], true)) {
			$hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
		}

		$conn->insert('neuron', [
			'pid'  => $pid,
			'type' => $type,
			'text' => $text,
			'tree' => $tree,
			'data' => $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
			'hash' => $hash,
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
	 * @param int $id — id нейрона
	 * @param array $fields — поля для обновления
	 * @return void
	 */
	public function update(int $id, array $fields): void
	{
		$conn = $this->db->getConnection();

		$fields = array_intersect_key($fields, array_flip(self::UPDATABLE_COLUMNS));

		if (empty($fields)) {
			return;
		}

		if (isset($fields['data'])) {
			$neuron = $this->findById($id);
			if ($neuron && in_array($neuron['type'], ['item', 'tree', 'file'], true)) {
				$fields['hash'] = hash('sha256', json_encode($fields['data'], JSON_UNESCAPED_UNICODE));
			}
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

	/**
	 * Мягко удалить нейрон.
	 *
	 * @param int $id — id нейрона
	 * @param bool $deleteSynapses — удалять ли связи
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
			$this->softDeleteSynapses($id);
		}

		$this->logAdminAction('delete', [
			'neuron_id' => $id,
			'type'      => $neuron['type'] ?? 'unknown',
		]);
	}

	/**
	 * Мягко удалить все связи нейрона.
	 *
	 * @param int $neuronId
	 */
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

	/**
	 * Найти нейрон по id.
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
	 * Найти нейрон по имени.
	 *
	 * @param string $name
	 * @param string $lang
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
	 * Найти нейрон по имени и pid.
	 *
	 * @param string $name
	 * @param int|null $pid
	 * @param string $lang
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
	 * Найти детей нейрона.
	 *
	 * @param int|null $parentId
	 * @param string|null $type
	 * @return array
	 */
	public function findChildren(?int $parentId, ?string $type = null): array
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT n.*, COALESCE(c.cnt, 0) AS child_count
				FROM neuron n
				LEFT JOIN (
					SELECT pid, COUNT(*) AS cnt
					FROM neuron
					WHERE is_deleted = 0
					GROUP BY pid
				) c ON c.pid = n.id
				WHERE n.pid " . ($parentId === null ? "IS NULL" : "= ?") . "
				AND n.is_deleted = 0";

		$params = $parentId === null ? [] : [$parentId];

		if ($type) {
			$sql .= " AND n.type = ?";
			$params[] = $type;
		}

		$sql .= " ORDER BY n.sort, n.id";

		$children = $conn->executeQuery($sql, $params)->fetchAllAssociative();

		// [Лорелея]: decode data. Один раз. Без второго запроса.
		foreach ($children as &$child) {
			$child['data'] = $this->decodeData($child['data'] ?? null);
			$child['child_count'] = (int) $child['child_count'];
		}
		unset($child);

		return $children;
	}

	/**
	 * Найти детей с текстом.
	 *
	 * @param int|null $parentId
	 * @param string $lang
	 * @param string|null $type
	 * @return array
	 */
	public function findChildrenWithText(?int $parentId, string $lang = 'ru', ?string $type = null): array
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT n.*,
				t.name,
				t.text as text_content,
				COALESCE(c.cnt, 0) AS child_count
				FROM neuron n
				LEFT JOIN text t
					ON t.key = n.text
					AND t.lang = ?
					AND t.is_active = 1
				LEFT JOIN (
					SELECT pid, COUNT(*) AS cnt
					FROM neuron
					WHERE is_deleted = 0
					GROUP BY pid
				) c ON c.pid = n.id
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

		$children = $conn->executeQuery($sql, $params)->fetchAllAssociative();

		foreach ($children as &$child) {
			$child['data'] = $this->decodeData($child['data'] ?? null);
			$child['child_count'] = (int) $child['child_count'];
		}
		unset($child);

		return $children;
	}

	/**
	 * Декодировать поле data нейрона в массив.
	 *
	 * [Лорелея]: Вынесено, потому что раньше код декодирования
	 * дублировался в findChildren() и findChildrenWithText().
	 * Почти слово в слово. Это была копипаста. Которая работала.
	 * Но — копипаста.
	 *
	 * @param mixed $data
	 * @return array
	 */
	private function decodeData($data): array
	{
		if (is_string($data)) {
			$decoded = json_decode($data, true);
			return is_array($decoded) ? $decoded : [];
		}

		return is_array($data) ? $data : [];
	}

	// ============================================
	// ПРОВЕРКА ДУБЛИКАТОВ
	// ============================================

	/**
	 * Найти дубликат нейрона.
	 *
	 * @param string $type
	 * @param int|null $tree
	 * @param int|null $text
	 * @param int|null $pid
	 * @return int|null
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
	// ПОИСК ПОЛЬЗОВАТЕЛЯ
	// ============================================

	/**
	 * Найти пользователя по логину или email.
	 *
	 * @param string $login
	 * @return array|null
	 */
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

	/**
	 * Найти нейроны с геометрией.
	 *
	 * @param int|null $treeId
	 * @param int|null $pid
	 * @return array
	 */
	public function findWithGeometry(?int $treeId = null, ?int $pid = null): array
	{
		$conn = $this->db->getConnection();

		$sql = "SELECT n.id, n.data, n.type, t.name AS display_name
				FROM neuron n
				LEFT JOIN text t
					ON t.key = n.text
					AND t.lang = 'ru'
					AND t.is_active = 1
				WHERE n.has_geometry = 1
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
	 * Слить данные в нейрон.
	 *
	 * @param int $id
	 * @param array $newData
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
	 *
	 * @param string $action
	 * @param array $context
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

	/**
	 * Найти файлы для деревьев.
	 *
	 * @param array $treeIds
	 * @return array
	 */
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

	/**
	 * Найти все значения конфигов.
	 *
	 * @return array
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