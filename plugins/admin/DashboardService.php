<?php

declare(strict_types=1);

/**
 * СЕРВИС ДАШБОРДА
 *
 * Собирает данные из репозитория и готовит их для рендеринга.
 *
 * @package Jan\Trinity\Plugin\Admin
 */

namespace Jan\Trinity\Plugin\Admin;

class DashboardService
{
	/** @var DashboardRepository — рука, которая собирает данные. */
	private DashboardRepository $repository;

	public function __construct(DashboardRepository $repository)
	{
		$this->repository = $repository;
	}

	/**
	 * Собрать все данные для дашборда.
	 */
	public function collect(): array
	{
		$counts = $this->repository->getCounts();

		return [
			'counts' => [
				'total_neurons' => (int) ($counts['total_neurons'] ?? 0),
				'total_users'   => (int) ($counts['total_users'] ?? 0),
				'total_files'   => (int) ($counts['total_files'] ?? 0),
				'total_synapses' => $this->repository->getTotalSynapses(),
				'total_texts'   => $this->repository->getTotalTexts(),
			],
			'by_type'       => $this->repository->getNeuronsByType(),
			'latest'        => $this->repository->getLatestNeurons(8),
			'admin_log'     => $this->repository->getAdminLog(15),
			'pending_users' => $this->repository->getPendingUsers(),
		];
	}
}