<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Admin;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Psr\Log\LoggerInterface;

class DashboardController
{
	use AuthMiddleware;

	private NeuronRepository $neuronRepo;
	private SynapseRepository $synapseRepo;
	private LoggerInterface $logger;

	public function __construct(
		Session $session,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		GuardService $guard,
		LoggerInterface $logger
	) {
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->logger = $logger;

		$this->initAuth($session);
	}

	/**
	 * GET /api/admin/dashboard
	 */
	public function dashboard(): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$conn = $this->neuronRepo->getConnection();

		$counts = $conn->executeQuery(
			"SELECT 
				COUNT(*) as total_neurons,
				SUM(CASE WHEN type = 'user' THEN 1 ELSE 0 END) as total_users,
				SUM(CASE WHEN type = 'file' THEN 1 ELSE 0 END) as total_files
			FROM neuron WHERE is_deleted = 0"
		)->fetchAssociative();

		$totalSynapses = (int) $conn->executeQuery("SELECT COUNT(*) FROM synapse WHERE is_deleted = 0")->fetchOne();
		$totalTexts = (int) $conn->executeQuery("SELECT COUNT(*) FROM text WHERE is_active = 1")->fetchOne();

		$byType = $conn->executeQuery(
			"SELECT type, COUNT(*) as cnt FROM neuron WHERE is_deleted = 0 GROUP BY type ORDER BY cnt DESC"
		)->fetchAllAssociative();

		$latest = $conn->executeQuery(
			"SELECT n.id, n.type, n.date, t.name 
			FROM neuron n 
			LEFT JOIN text t ON t.key = n.text AND t.lang = 'ru' AND t.is_active = 1 
			WHERE n.is_deleted = 0 
			ORDER BY n.id DESC LIMIT 8"
		)->fetchAllAssociative();

		// Здесь формируется HTML (можно вынести в Twig)
		$html = '<div class="p-3">';
		$html .= '<h5 class="gold-text mb-3"><i class="bi bi-speedometer2"></i> Дашборд</h5>';

		$html .= '<div class="row g-2 mb-3">';
		$cards = [
			['icon' => 'diagram-3', 'value' => $counts['total_neurons'], 'label' => 'Нейронов'],
			['icon' => 'link-45deg', 'value' => $totalSynapses, 'label' => 'Синапсов'],
			['icon' => 'fonts', 'value' => $totalTexts, 'label' => 'Текстов'],
			['icon' => 'people', 'value' => $counts['total_users'], 'label' => 'Пользователей'],
			['icon' => 'images', 'value' => $counts['total_files'], 'label' => 'Файлов'],
		];
		foreach ($cards as $card) {
			$html .= '<div class="col">';
			$html .= '<div class="card bg-dark border-gold text-center p-2 h-100">';
			$html .= '<i class="bi bi-' . $card['icon'] . ' gold-text" style="font-size:1.5rem;"></i>';
			$html .= '<h4 class="mt-1 mb-0">' . $card['value'] . '</h4>';
			$html .= '<small class="text-muted">' . $card['label'] . '</small>';
			$html .= '</div></div>';
		}
		$html .= '</div>';

		$html .= '<div class="row g-2 mb-3"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-pie-chart gold-text"></i> Типы нейронов</strong></div><div class="card-body py-2"><table class="table table-dark table-sm mb-0">';
		foreach ($byType as $row) {
			$html .= '<tr><td><code>' . $row['type'] . '</code></td><td class="text-end">' . $row['cnt'] . '</td></tr>';
		}
		$html .= '</table></div></div></div></div>';

		$html .= '<div class="row g-2"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-clock-history gold-text"></i> Последние нейроны</strong></div><div class="card-body py-2"><table class="table table-dark table-sm mb-0">';
		foreach ($latest as $row) {
			$html .= '<tr><td>#' . $row['id'] . '</td><td><code>' . $row['type'] . '</code></td><td>' . htmlspecialchars($row['name'] ?? '—') . '</td><td class="text-muted small">' . $row['date'] . '</td></tr>';
		}
		$html .= '</table></div></div></div></div>';

		$logDir = dirname(__DIR__, 2) . '/var/log';
		$adminLog = [];
		$logFile = $logDir . '/admin.log';

		if (file_exists($logFile)) {
			$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$adminLog = array_slice(array_reverse($lines), 0, 15);
		} else {
			$files = glob($logDir . '/admin-*.log');
			if (!empty($files)) {
				rsort($files);
				$logFile = $files[0];
				$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
				$adminLog = array_slice(array_reverse($lines), 0, 15);
			}
		}

		if (!empty($adminLog)) {
			$html .= '<div class="row g-2 mt-3"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-journal-text gold-text"></i> Действия админа</strong></div><div class="card-body py-2"><pre class="mb-0" style="max-height:200px; overflow-y:auto; font-size:0.75rem;">';
			foreach ($adminLog as $line) {
				$html .= htmlspecialchars($line) . "\n";
			}
			$html .= '</pre></div></div></div></div>';
		}

		$pendingUsers = $conn->executeQuery(
			"SELECT id, data, date FROM neuron 
			WHERE type = 'user' 
			AND is_deleted = 0 
			AND status = 'pending' 
			ORDER BY date DESC"
		)->fetchAllAssociative();

		if (!empty($pendingUsers)) {
			$html .= '<div class="row g-2 mt-3"><div class="col-12"><div class="card bg-dark border-gold"><div class="card-header py-2"><strong><i class="bi bi-person-plus gold-text"></i> Новые пользователи</strong></div><div class="card-body py-2"><table class="table table-dark table-sm mb-0">';
			$html .= '<thead><tr><th>Логин</th><th>Email</th><th>IP</th><th>Регистрация</th><th>Действия</th></tr></thead><tbody>';
			foreach ($pendingUsers as $u) {
				$data = json_decode($u['data'], true);
				$html .= '<tr>';
				$html .= '<td>' . htmlspecialchars($data['login'] ?? '—') . '</td>';
				$html .= '<td>' . htmlspecialchars($data['email'] ?? '—') . '</td>';
				$html .= '<td><code>' . htmlspecialchars($data['ip'] ?? '—') . '</code></td>';
				$html .= '<td>' . htmlspecialchars($data['registered_at'] ?? $u['date']) . '</td>';
				$html .= '<td>';
				$html .= '<button class="btn btn-sm btn-outline-gold me-1" onclick="approveUser(' . $u['id'] . ')"><i class="bi bi-check-lg"></i></button>';
				$html .= '<button class="btn btn-sm btn-outline-danger" onclick="deleteUser(' . $u['id'] . ')"><i class="bi bi-trash"></i></button>';
				$html .= '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div></div></div></div>';
		}

		$html .= '</div>';

		return ApiResponse::success(['html' => $html]);
	}
}