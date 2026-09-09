<?php

/**
 * РЕПОЗИТОРИЙ SYNAPSE
 * 
 * Единый слой для работы с таблицей synapse.
 * Инкапсулирует создание, поиск и проверку дубликатов связей.
 */

namespace Jan\Trinity\Core\Repository;

use Jan\Trinity\Core\DatabaseService;

class SynapseRepository
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
     * Создать новую связь (синапс).
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
     * Если $time указан — проверяет полное совпадение (parent + child + tree + time).
     * Если $time не указан — проверяет без времени.
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

        $sql = "SELECT id FROM synapse WHERE parent = ? AND child = ?";
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
     * @param int $parentId
     * @param string|null $relationType — фильтр по типу связи (has_role, has_work, ...)
     * @return array
     */
    public function findByParent(int $parentId, ?string $relationType = null): array
    {
        $conn = $this->db->getConnection();

        $sql = "SELECT s.*, 
                (SELECT t.name FROM text t WHERE t.key = n.text AND t.lang = 'ru' LIMIT 1) as child_name
                FROM synapse s
                JOIN neuron n ON s.child = n.id
                WHERE s.parent = ?";

        $params = [$parentId];

        if ($relationType) {
            $sql .= " AND s.relation_type = ?";
            $params[] = $relationType;
        }

        $sql .= " ORDER BY s.time DESC, s.id DESC";

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Найти синапсы по ребёнку.
     * 
     * @param int $childId
     * @param string|null $relationType
     * @return array
     */
    public function findByChild(int $childId, ?string $relationType = null): array
    {
        $conn = $this->db->getConnection();

        $sql = "SELECT s.*, 
                (SELECT t.name FROM text t WHERE t.key = n.text AND t.lang = 'ru' LIMIT 1) as parent_name
                FROM synapse s
                JOIN neuron n ON s.parent = n.id
                WHERE s.child = ?";

        $params = [$childId];

        if ($relationType) {
            $sql .= " AND s.relation_type = ?";
            $params[] = $relationType;
        }

        $sql .= " ORDER BY s.time DESC, s.id DESC";

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Посчитать количество синапсов по родителю.
     * 
     * @param int $parentId
     * @param string|null $relationType
     * @return int
     */
    public function countByParent(int $parentId, ?string $relationType = null): int
    {
        $conn = $this->db->getConnection();

        $sql = "SELECT COUNT(*) FROM synapse WHERE parent = ?";
        $params = [$parentId];

        if ($relationType) {
            $sql .= " AND relation_type = ?";
            $params[] = $relationType;
        }

        return (int) $conn->executeQuery($sql, $params)->fetchOne();
    }

    /**
     * Удалить синапс по ID.
     * 
     * @param int $id
     */
    public function delete(int $id): void
    {
        $this->db->getConnection()->executeStatement('DELETE FROM synapse WHERE id = ?', [$id]);
    }

    /**
     * Удалить все синапсы нейрона.
     * 
     * @param int $neuronId
     */
    public function deleteByNeuron(int $neuronId): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement('DELETE FROM synapse WHERE parent = ? OR child = ?', [$neuronId, $neuronId]);
    }

    public function findRolesByUser(int $userId): array
    {
        $results = $this->db->getConnection()->executeQuery(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(n.data, '$.slug')) as role_code
            FROM synapse s 
            JOIN neuron n ON s.child = n.id 
            WHERE s.parent = ? 
                AND s.id = (SELECT MAX(s2.id) FROM synapse s2 WHERE s2.parent = s.parent AND s2.child = s.child)
                AND s.relation_type = 'has_role'",
            [$userId]
        )->fetchAllAssociative();
        
        return array_column($results, 'role_code');
    }
}