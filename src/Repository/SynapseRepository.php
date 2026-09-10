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

    /**
     * Установить динамический атрибут (связь синапса).
     * Создает новую связь с данными и временем.
     * 
     * @param int $neuronId ID нейрона-родителя (кому назначаем атрибут)
     * @param int $attributeId ID нейрона-атрибута (что назначаем, например "Цена")
     * @param mixed $value Значение атрибута
     * @param string|null $relationType Тип связи (например 'price', 'rate')
     * @param \DateTime|string|null $time Дата/время действия значения
     * @param array $extra Дополнительные данные в JSON
     * @return int ID созданного синапса
     */
    public function setAttribute(
        int $neuronId,
        int $attributeId,
        mixed $value,
        ?string $relationType = null,
        \DateTime|string|null $time = null,
        array $extra = []
    ): int {
        if ($time instanceof \DateTime) {
            $time = $time->format('Y-m-d H:i:s');
        } elseif ($time === null) {
            $time = date('Y-m-d H:i:s');
        }

        $data = array_merge($extra, [
            'relation' => $relationType ?? 'attribute',
            'value' => $value
        ]);

        return $this->create($neuronId, $attributeId, $data, null, $time);
    }

    /**
     * Получить последнее значение атрибута.
     * 
     * @param int $neuronId ID нейрона-родителя
     * @param int $attributeId ID нейрона-атрибута
     * @param string|null $relationType Тип связи
     * @param \DateTime|string|null $asOfDate Дата, на которую нужно значение (null = последнее)
     * @return mixed|null Значение атрибута или null
     */
    public function getAttribute(
        int $neuronId,
        int $attributeId,
        ?string $relationType = null,
        \DateTime|string|null $asOfDate = null
    ): mixed {
        $conn = $this->db->getConnection();

        $sql = "SELECT data FROM synapse 
                WHERE parent = ? AND child = ?";
        
        $params = [$neuronId, $attributeId];

        if ($relationType) {
            $sql .= " AND relation_type = ?";
            $params[] = $relationType;
        }

        if ($asOfDate !== null) {
            if ($asOfDate instanceof \DateTime) {
                $asOfDate = $asOfDate->format('Y-m-d H:i:s');
            }
            $sql .= " AND time <= ?";
            $params[] = $asOfDate;
        }

        $sql .= " ORDER BY time DESC LIMIT 1";

        $result = $conn->executeQuery($sql, $params)->fetchAssociative();
        
        if (!$result) {
            return null;
        }

        $data = json_decode($result['data'], true);
        return $data['value'] ?? null;
    }

    /**
     * Получить историю изменения атрибута.
     * 
     * @param int $neuronId ID нейрона-родителя
     * @param int $attributeId ID нейрона-атрибута
     * @param string|null $relationType Тип связи
     * @param \DateTime|string|null $fromDate Начальная дата
     * @param \DateTime|string|null $toDate Конечная дата
     * @return array Массив записей ['time' => ..., 'value' => ...]
     */
    public function getAttributeHistory(
        int $neuronId,
        int $attributeId,
        ?string $relationType = null,
        \DateTime|string|null $fromDate = null,
        \DateTime|string|null $toDate = null
    ): array {
        $conn = $this->db->getConnection();

        $sql = "SELECT time, data FROM synapse 
                WHERE parent = ? AND child = ?";
        
        $params = [$neuronId, $attributeId];

        if ($relationType) {
            $sql .= " AND relation_type = ?";
            $params[] = $relationType;
        }

        if ($fromDate !== null) {
            if ($fromDate instanceof \DateTime) {
                $fromDate = $fromDate->format('Y-m-d H:i:s');
            }
            $sql .= " AND time >= ?";
            $params[] = $fromDate;
        }

        if ($toDate !== null) {
            if ($toDate instanceof \DateTime) {
                $toDate = $toDate->format('Y-m-d H:i:s');
            }
            $sql .= " AND time <= ?";
            $params[] = $toDate;
        }

        $sql .= " ORDER BY time ASC";

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();
        
        $history = [];
        foreach ($results as $row) {
            $data = json_decode($row['data'], true);
            $history[] = [
                'time' => $row['time'],
                'value' => $data['value'] ?? null,
                'data' => $data
            ];
        }

        return $history;
    }

    /**
     * Получить все атрибуты нейрона.
     * 
     * @param int $neuronId ID нейрона-родителя
     * @param \DateTime|string|null $asOfDate Дата, на которую получать значения
     * @return array Массив ['attribute_id' => value, ...]
     */
    public function getAllAttributes(int $neuronId, \DateTime|string|null $asOfDate = null): array
    {
        $synapses = $this->findByParent($neuronId);
        
        $attributes = [];
        
        foreach ($synapses as $synapse) {
            // Для каждого синапса получаем актуальное значение
            $value = $this->getAttribute(
                $neuronId,
                $synapse['child'],
                $synapse['relation_type'] ?? null,
                $asOfDate
            );
            
            if ($value !== null) {
                $attributes[$synapse['child']] = $value;
            }
        }
        
        return $attributes;
    }
}