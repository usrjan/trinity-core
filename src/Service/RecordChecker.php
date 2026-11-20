<?php

declare(strict_types=1);

/**
 * ПРОВЕРКА СУЩЕСТВОВАНИЯ ЗАПИСЕЙ
 *
 * Один источник правды для exists/unique в Validator.
 * Знает, какая таблица как помечает «удалённые» записи.
 *
 * [Лорелея]: Потому что Validator не должен знать, что text
 * помечает удалённое через is_active, а neuron — через is_deleted.
 * Это — знание о схеме. А схема — не его дело.
 *
 * [Мириам]: И потому что in_array($table, ['neuron', 'synapse'])
 * — это не «правило». Это — список, который однажды забудут
 * обновить. И тогда exists:route,id вернёт true для удалённого
 * маршрута. А потом — заметят. Но будет поздно.
 *
 * @package Jan\Trinity\Core\Service
 */

namespace Jan\Trinity\Core\Service;

use Jan\Trinity\Core\Repository\NeuronRepository;

class RecordChecker
{
	/** @var array Разрешённые таблицы. Один список. На всех. */
	private const ALLOWED_TABLES = ['neuron', 'synapse', 'text'];

	/**
	 * @var array Колонка soft-delete для каждой таблицы.
	 * null — таблица не имеет soft-delete.
	 */
	private const SOFT_DELETE_COLUMN = [
		'neuron'  => 'is_deleted',
		'synapse' => 'is_deleted',
		'text'    => 'is_active',
	];

	/**
	 * @var array «Активное» значение soft-delete для каждой таблицы.
	 * Для is_deleted = 0 — запись жива. Для is_active = 1 — запись жива.
	 */
	private const SOFT_DELETE_ACTIVE_VALUE = [
		'neuron'  => 0,
		'synapse' => 0,
		'text'    => 1,
	];

	/** @var NeuronRepository — через него получаем соединение. */
	private NeuronRepository $neuronRepo;

	/**
	 * Конструктор.
	 *
	 * @param NeuronRepository $neuronRepo
	 */
	public function __construct(NeuronRepository $neuronRepo)
	{
		$this->neuronRepo = $neuronRepo;
	}

	/**
	 * Проверить существование записи.
	 *
	 * @param string $table — таблица (neuron, synapse, text)
	 * @param string $column — колонка
	 * @param mixed $value — значение
	 * @return bool
	 * @throws \RuntimeException — если таблица или колонка не разрешены
	 */
	public function exists(string $table, string $column, $value): bool
	{
		if (!in_array($table, self::ALLOWED_TABLES, true)) {
			throw new \RuntimeException("RecordChecker: table '{$table}' не разрешена");
		}

		if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
			throw new \RuntimeException("RecordChecker: column '{$column}' невалидна");
		}

		$sql = "SELECT 1 FROM {$table} WHERE {$column} = ?";

		// [Лорелея]: Soft-delete. Если таблица его имеет — учитываем.
		// Если нет — не выдумываем.
		$softColumn = self::SOFT_DELETE_COLUMN[$table] ?? null;
		if ($softColumn !== null) {
			$activeValue = self::SOFT_DELETE_ACTIVE_VALUE[$table];
			$sql .= " AND {$softColumn} = ?";
		}

		$sql .= " LIMIT 1";

		$params = [$value];
		if ($softColumn !== null) {
			$params[] = self::SOFT_DELETE_ACTIVE_VALUE[$table];
		}

		$result = $this->neuronRepo->getConnection()
			->executeQuery($sql, $params)
			->fetchOne();

		return $result !== false;
	}
}