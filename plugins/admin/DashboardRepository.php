<?php

declare(strict_types=1);

/**
 * РЕПОЗИТОРИЙ ДАШБОРДА
 *
 * Собирает данные для дашборда: количество нейронов, синапсов,
 * текстов, пользователей, файлов. Последние нейроны. Логи.
 * Пользователей в ожидании.
 *
 * @package Jan\Trinity\Plugin\Admin
 */

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\Repository\NeuronRepository;

class DashboardRepository
{
	/** @var NeuronRepository — работа с нейронами. */
	private NeuronRepository $neuronRepo;

	public function __construct(NeuronRepository $neuronRepo)
	{
		$this->neuronRepo = $neuronRepo;
	}

	/**
	 * Получить общее количество нейронов, пользователей и файлов.
	 */
	public function getCounts(): array
	{
		$conn = $this->neuronRepo->getConnection();

		return $conn->executeQuery(
			"SELECT
				COUNT(*) as total_neurons,
				SUM(CASE WHEN type = 'user' THEN 1 ELSE 0 END) as total_users,
				SUM(CASE WHEN type = 'file' THEN 1 ELSE 0 END) as total_files
			FROM neuron WHERE is_deleted = 0"
		)->fetchAssociative();
	}

	/**
	 * Получить количество синапсов.
	 */
	public function getTotalSynapses(): int
	{
		$conn = $this->neuronRepo->getConnection();
		return (int) $conn->executeQuery("SELECT COUNT(*) FROM synapse WHERE is_deleted = 0")->fetchOne();
	}

	/**
	 * Получить количество текстов.
	 */
	public function getTotalTexts(): int
	{
		$conn = $this->neuronRepo->getConnection();
		return (int) $conn->executeQuery("SELECT COUNT(*) FROM text WHERE is_active = 1")->fetchOne();
	}

	/**
	 * Получить количество нейронов по типам.
	 */
	public function getNeuronsByType(): array
	{
		$conn = $this->neuronRepo->getConnection();

		return $conn->executeQuery(
			"SELECT type, COUNT(*) as cnt FROM neuron WHERE is_deleted = 0 GROUP BY type ORDER BY cnt DESC"
		)->fetchAllAssociative();
	}

	/**
	 * Получить последние нейроны.
	 */
	public function getLatestNeurons(int $limit = 8): array
	{
		$conn = $this->neuronRepo->getConnection();

		return $conn->executeQuery(
			"SELECT n.id, n.type, n.date, t.name
			FROM neuron n
			LEFT JOIN text t ON t.key = n.text AND t.lang = 'ru' AND t.is_active = 1
			WHERE n.is_deleted = 0
			ORDER BY n.id DESC LIMIT " . (int) $limit
		)->fetchAllAssociative();
	}

	/**
	 * Получить пользователей в ожидании.
	 */
	public function getPendingUsers(): array
	{
		$conn = $this->neuronRepo->getConnection();

		$users = $conn->executeQuery(
			"SELECT id, data, date FROM neuron
			WHERE type = 'user'
			AND is_deleted = 0
			AND status = 'pending'
			ORDER BY date DESC"
		)->fetchAllAssociative();

		foreach ($users as &$user) {
			$user['data'] = is_string($user['data'] ?? null)
				? json_decode($user['data'], true)
				: ($user['data'] ?? []);
		}
		unset($user);

		return $users;
	}

	/**
	 * Получить последние строки лога администратора.
	 */
	public function getAdminLog(int $lines = 15): array
	{
		$logDir = dirname(__DIR__, 3) . '/var/log';
		$logFile = $logDir . '/admin.log';

		if (file_exists($logFile)) {
			$logLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			return array_slice(array_reverse($logLines), 0, $lines);
		}

		$files = glob($logDir . '/admin-*.log');
		if (!empty($files)) {
			rsort($files);
			$logFile = $files[0];
			$logLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			return array_slice(array_reverse($logLines), 0, $lines);
		}

		return [];
	}
}