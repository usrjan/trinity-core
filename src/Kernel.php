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

class Kernel
{
	/** @var Bootstrap — тот, кто собрал дом. */
	private Bootstrap $bootstrap;

	/** @var \DI\Container DI-контейнер. */
	private \DI\Container $container;

	private ArgumentResolver $argumentResolver;
	private HttpResponder $httpResponder;
	private RouteCompiler $routeCompiler;
	private ErrorRenderer $errorRenderer;
	private RequestPipeline $requestPipeline;

	// ============================================
	// РОЖДЕНИЕ
	// ============================================

	/**
	 * Конструктор. Принимает путь. Собирает дом через Bootstrap.
	 * И — получает то, что нужно для жизни.
	 *
	 * @param string $basePath
	 */
	public function __construct(string $basePath)
	{
		$this->bootstrap = new Bootstrap($basePath);
		$this->bootstrap->boot();

		$this->container = $this->bootstrap->getContainer();

		$this->requestPipeline = $this->container->get(RequestPipeline::class);
		$this->argumentResolver = $this->container->get(ArgumentResolver::class);
		$this->httpResponder = $this->container->get(HttpResponder::class);

		$this->errorRenderer = $this->container->get(ErrorRenderer::class);
		$this->routeCompiler = $this->container->get(RouteCompiler::class);
		$this->routeCompiler->init($this->bootstrap->getPlugins());
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

			$response = $this->errorRenderer->render($e, $code, $request);

			if ($e instanceof MethodNotAllowedException) {
				$allowedMethods = $e->getAllowedMethods();
				$response->headers->set('Allow', implode(', ', $allowedMethods));
			}
		}

		$this->httpResponder->send($request, $response);
	}

	/**
	 * Обрабатывает OPTIONS-preflight.
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