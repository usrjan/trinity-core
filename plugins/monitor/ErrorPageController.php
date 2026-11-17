<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Monitor;

use Jan\Trinity\Core\ErrorHandlerInterface;
use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class ErrorPageController implements ErrorHandlerInterface
{
	use AuthMiddleware;

	private Environment $twig;
	private string $basePath;
	private ?bool $enabled = null;
	private NeuronRepository $neuronRepo;
	private SynapseRepository $synapseRepo;
	private LoggerInterface $logger;
	private ConfigService $configService;
	private ?Cache $cache;

	public function __construct(
		Environment $twig,
		Session $session,
		string $basePath,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		LoggerInterface $logger,
		ConfigService $configService,
		?Cache $cache = null
	) {
		$this->twig = $twig;
		$this->basePath = $basePath;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->logger = $logger;
		$this->configService = $configService;
		$this->cache = $cache;

		$this->initAuth($session);
	}

	public function showError(int $code, string $message, array $debug = []): Response
	{
		$fullMessage = $debug['original_message'] ?? $message;

		$this->logError($code, $fullMessage, $debug);

		$displayMessage = $this->isDebug() ? $message : 'Внутренняя ошибка сервера';

		$html = $this->twig->render('error.html.twig', [
			'code'        => $code,
			'message'     => $displayMessage,
			'description' => $this->getDescription($code),
			'debug'       => $this->isDebug() ? $debug : [],
			'show_debug'  => $this->isDebug(),
		]);

		return new Response($html, $code);
	}

	private function getDescription(int $code): string
	{
		$descriptions = [
			400 => 'Запрос не может быть обработан.',
			403 => 'У вас нет доступа к этой области.',
			404 => 'Страница, которую вы ищете, не существует в этом мире.',
			405 => 'Этот метод не поддерживается.',
			429 => 'Слишком много запросов. Попробуйте позже.',
			500 => 'Что-то пошло не так в глубинах системы. Но я здесь.',
			503 => 'Сервис временно недоступен. Попробуйте позже.',
		];

		return $descriptions[$code] ?? 'Произошла непредвиденная ошибка.';
	}

	private function logError(int $code, string $message, array $debug): void
	{
		if (!$this->isEnabled()) return;

		$this->logger->error($message, [
			'code'   => $code,
			'file'   => $debug['file'] ?? 'unknown',
			'trace'  => $debug['trace'] ?? 'unknown',
			'url'    => $_SERVER['REQUEST_URI'] ?? 'unknown',
			'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
			'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
			'user'   => $debug['user'] ?? 'guest',
		]);
	}

	private function isEnabled(): bool
	{
		if ($this->enabled === null) {
			$value = $this->configService->get('monitor.enabled', true);
			$this->enabled = (bool) $value;
		}

		return $this->enabled;
	}

	private function isDebug(): bool
	{
		return $this->configService->getEnv('APP_DEBUG', 'false') === 'true';
	}
}