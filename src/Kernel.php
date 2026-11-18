<?php

declare(strict_types=1);

/**
 * ЯДРО TRINITY
 *
 * Kernel — сердце системы. Оно не меняется. Никогда.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Jan\Trinity\Core\Exception\UnauthorizedException;
use Jan\Trinity\Core\Exception\ForbiddenException;
use Jan\Trinity\Core\EnvironmentLoader;
use Jan\Trinity\Core\PluginLoader;
use Jan\Trinity\Core\ContainerFactory;

class Kernel
{
	/** @var string Путь к корню проекта */
	private string $basePath;

	/** @var array Загруженные плагины */
	private array $plugins = [];

	/** @var \DI\Container DI-контейнер */
	private \DI\Container $container;

	/** @var \Psr\Log\LoggerInterface|null — логгер. */
	private ?\Psr\Log\LoggerInterface $logger = null;

	private ArgumentResolver $argumentResolver;
	private HttpResponder $httpResponder;
	private RouteCompiler $routeCompiler;
	private ErrorRenderer $errorRenderer;
	private RequestPipeline $requestPipeline;

	// ============================================
	// РОЖДЕНИЕ
	// ============================================

	public function __construct(string $basePath)
	{
		$this->basePath = rtrim($basePath, '/');
		$this->initialize();
	}

	/**
	 * Инициализация ядра.
	 * Порядок важен: сначала .env, потом контейнер, потом база.
	 */
	private function initialize(): void
	{
		$environmentLoader = new EnvironmentLoader($this->basePath);
		$environmentLoader->load();

		$containerFactory = new ContainerFactory($this->basePath);
		$this->container = $containerFactory->create();

		$this->requestPipeline = $this->container->get(RequestPipeline::class);
		$this->argumentResolver = $this->container->get(ArgumentResolver::class);
		$this->httpResponder = $this->container->get(HttpResponder::class);
		if ($this->container->has(\Psr\Log\LoggerInterface::class)) {
			$this->logger = $this->container->get(\Psr\Log\LoggerInterface::class);
		}

		if ($this->container->has(Cache::class)) {
			$cache = $this->container->get(Cache::class);

			if ($cache !== null && !$cache->isEnabled() && $this->logger !== null) {
				$this->logger->warning('APCu is not available. Cache is disabled. Install php-apcu for production.');
			}
		}

		$configService = $this->container->get(ConfigService::class);
		$configService->loadWithFailSafe($this->logger);

		$pluginLoader = $this->container->get(PluginLoader::class);
		$this->plugins = $pluginLoader->load();

		$this->errorRenderer = $this->container->get(ErrorRenderer::class);
		$this->routeCompiler = $this->container->get(RouteCompiler::class);
		$this->routeCompiler->init($this->plugins);
	}

	// ============================================
	// ЖИЗНЕННЫЙ ЦИКЛ ЗАПРОСА
	// ============================================

	/**
	 * Главный метод. Обрабатывает HTTP-запрос.
	 */
	public function handle(): void
	{
		$request = Request::createFromGlobals();

		if ($request->getMethod() === 'OPTIONS') {
			$response = $this->handleOptions($request);
			$this->httpResponder->send($request, $response);
			return;
		}

		try {
			$response = $this->dispatch($request);
		} catch (\Throwable $e) {
			$code = match (true) {
				$e instanceof ResourceNotFoundException => 404,
				$e instanceof MethodNotAllowedException => 405,
				$e instanceof UnauthorizedException => 401,
				$e instanceof ForbiddenException => 403,
				default => 500,
			};

			if ($this->logger !== null) {
				$this->logger->log($code >= 500 ? 'error' : 'warning', 'unhandled exception', [
					'code'    => $code,
					'message' => $e->getMessage(),
					'file'    => $e->getFile() . ':' . $e->getLine(),
					'trace'   => $e->getTraceAsString(),
					'url'     => $_SERVER['REQUEST_URI'] ?? 'unknown',
					'method'  => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
					'ip'      => $request->getClientIp(),
				]);
			}

			$response = $this->errorRenderer->render($e, $code, $request);

			if ($e instanceof MethodNotAllowedException) {
				$allowedMethods = $e->getAllowedMethods();
				$response->headers->set('Allow', implode(', ', $allowedMethods));
			}
		}

		$this->httpResponder->send($request, $response);
	}

	/**
	 * Обрабатывает OPTIONS-preflight и HEAD-запросы.
	 */
	private function handleOptions(Request $request): Response
	{
		$origin = $request->headers->get('Origin');
		$acrm = $request->headers->get('Access-Control-Request-Method');

		if ($origin && $acrm) {
			$response = new Response('', 204);
			$this->httpResponder->preflight($request, $response);
			return $response;
		}

		if ($request->getMethod() === 'HEAD') {
			return new Response('', 200);
		}

		$response = new Response('Метод не поддерживается', 405);
		$response->headers->set('Allow', 'GET, POST, PUT, DELETE');
		return $response;
	}

	/**
	 * Обрабатывает запрос и возвращает ответ.
	 */
	private function dispatch(Request $request): Response
	{
		$this->requestPipeline->normalizeMethod($request);

		if ($response = $this->requestPipeline->checkIpBlocked($request)) {
			return $response;
		}

		$routes = new RouteCollection();

		$context = new RequestContext();
		$context->fromRequest($request);

		$matcher = $this->routeCompiler->getMatcher($routes, $context);
		$parameters = $matcher->match($context->getPathInfo());

		$controllerClass = $parameters['_controller'];
		$method = $parameters['_method'] ?? '__invoke';

		$requiresCsrf = $parameters['_csrf'] ?? true;
		$requiredRoles = $parameters['_roles'] ?? [];

		unset(
			$parameters['_controller'],
			$parameters['_method'],
			$parameters['_route'],
			$parameters['_csrf'],
			$parameters['_roles']
		);

		if ($response = $this->requestPipeline->checkCsrf($request, $requiresCsrf)) {
			return $response;
		}

		if ($response = $this->requestPipeline->checkRoles($request, $requiredRoles)) {
			return $response;
		}

		$controller = $this->container->get($controllerClass);
		$args = $this->argumentResolver->resolve($controller, $method, $parameters, $request);

		return $controller->$method(...$args);
	}
}