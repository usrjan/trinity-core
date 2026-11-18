<?php

declare(strict_types=1);

namespace Jan\Trinity\Plugin\Spa;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\JsonHelper;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class MenuAdminController
{
	use AuthMiddleware;

	private NeuronRepository $neuronRepo;
	private TextRepository $textRepo;
	private Validator $validator;

	public function __construct(
		Session $session,
		NeuronRepository $neuronRepo,
		TextRepository $textRepo,
		Validator $validator
	) {
		$this->neuronRepo = $neuronRepo;
		$this->textRepo = $textRepo;
		$this->validator = $validator;

		$this->initAuth($session);
	}

	/**
	 * POST /api/menu/add-item
	 */
	public function addItem(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$name = $body['name'] ?? '';
		$slug = $body['slug'] ?? '';
		$icon = $body['icon'] ?? 'bi-file';
		$route = $body['route'] ?? '/';
		$isPlugin = (bool) ($body['is_plugin'] ?? false);

		if (!$this->validator->validate([
			'name'  => $name,
			'slug'  => $slug,
			'icon'  => $icon,
			'route' => $route,
		], [
			'name'  => ['required', 'string', 'min:1', 'max:255'],
			'slug'  => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/iu'],
			'icon'  => ['nullable', 'string', 'max:50', 'regex:/^bi-[a-z0-9-]+$/u'],
			'route' => ['required', 'string', 'max:1024', 'regex:/^\/[a-z0-9_\-\/]*$/iu'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$menuRoot = $this->neuronRepo->findBySlug('MENU');
		if (!$menuRoot) {
			return ApiResponse::error('MENU не найден', 500);
		}

		$public = $this->neuronRepo->findBySlugAndPid('PUBLIC', $menuRoot['id']);
		if (!$public) {
			return ApiResponse::error('PUBLIC не найден', 500);
		}

		$textKey = $this->textRepo->findOrCreate('ru', $name);

		$children = $this->neuronRepo->findChildren($public['id']);
		$maxSort = 0;
		foreach ($children as $child) {
			$childData = is_string($child['data'] ?? null)
				? json_decode($child['data'], true)
				: ($child['data'] ?? []);
			$sort = (int) ($childData['sort'] ?? 0);
			if ($sort > $maxSort) {
				$maxSort = $sort;
			}
		}
		$nextSort = $maxSort + 100;

		$id = $this->neuronRepo->create('tree', [
			'slug'      => $slug,
			'route'     => $route,
			'icon'      => $icon,
			'sort'      => $nextSort,
			'is_plugin' => $isPlugin,
		], $public['id'], $textKey);

		return ApiResponse::success(['id' => $id], 'Пункт меню добавлен');
	}

	/**
	 * POST /api/menu/update-item
	 */
	public function updateItem(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		$body = JsonHelper::parseJsonBody($request);
		if ($body instanceof JsonResponse) return $body;

		$id = (int) ($body['id'] ?? 0);
		$name = $body['name'] ?? '';
		$slug = $body['slug'] ?? '';
		$icon = $body['icon'] ?? 'bi-file';
		$route = $body['route'] ?? '/';

		if (!$this->validator->validate([
			'id'    => $id,
			'name'  => $name,
			'slug'  => $slug,
			'icon'  => $icon,
			'route' => $route,
		], [
			'id'    => ['required', 'int', 'min:1'],
			'name'  => ['required', 'string', 'min:1', 'max:255'],
			'slug'  => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/iu'],
			'icon'  => ['nullable', 'string', 'max:50', 'regex:/^bi-[a-z0-9-]+$/u'],
			'route' => ['required', 'string', 'max:1024', 'regex:/^\/[a-z0-9_\-\/]*$/iu'],
		])) {
			return ApiResponse::error($this->validator->getFirstError(), 400);
		}

		$neuron = $this->neuronRepo->findById($id);
		if (!$neuron) {
			return ApiResponse::error('Пункт меню не найден', 404);
		}

		if ($neuron['text']) {
			$textKey = $this->textRepo->findOrCreate('ru', $name);
			if ($textKey !== (int) $neuron['text']) {
				$this->neuronRepo->update($id, ['text' => $textKey]);
			}
		}

		$currentData = is_string($neuron['data'] ?? null)
			? json_decode($neuron['data'], true)
			: ($neuron['data'] ?? []);

		$currentData['slug'] = $slug;
		$currentData['icon'] = $icon;
		$currentData['route'] = $route;

		$this->neuronRepo->update($id, ['data' => $currentData]);

		return ApiResponse::success(null, 'Пункт меню обновлён');
	}
}