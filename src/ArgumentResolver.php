<?php
declare(strict_types=1);

/**
 * РЕЗОЛВЕР АРГУМЕНТОВ
 *
 * Превращает параметры маршрута в аргументы метода контроллера.
 * Знает про Request, скалярные типы и optional-параметры.
 *
 * [Лорелея]: Kernel больше не знает, как приводить "42" к int.
 * Это — работа резолвера. Ядро — скучное.
 *
 * [Мириам]: И это правильно, потому что тип должен совпадать,
 * а не почти совпадать.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\Request;

class ArgumentResolver
{
	/** @var array Кэш спецификаций параметров в пределах запроса. */
	private static array $paramSpecCacheRequest = [];

	/**
	 * Разрешает аргументы для метода контроллера.
	 *
	 * @param object $controller
	 * @param string $method
	 * @param array $urlParams
	 * @param Request $request
	 * @return array
	 */
	public function resolve(
		object $controller,
		string $method,
		array $urlParams,
		Request $request
	): array {
		$cacheKey = get_class($controller) . '::' . $method;

		if (!isset(self::$paramSpecCacheRequest[$cacheKey])) {
			$reflection = new \ReflectionMethod($controller, $method);
			$spec = [];

			foreach ($reflection->getParameters() as $param) {
				$type = $param->getType();
				$spec[] = [
					'name'     => $param->getName(),
					'type'     => $type instanceof \ReflectionNamedType ? $type->getName() : null,
					'optional' => $param->isOptional(),
					'default'  => $param->isOptional() ? $param->getDefaultValue() : null,
				];
			}

			self::$paramSpecCacheRequest[$cacheKey] = $spec;
		}

		$spec = self::$paramSpecCacheRequest[$cacheKey];
		$args = [];

		foreach ($spec as $param) {
			if ($param['type'] === Request::class) {
				$args[] = $request;
			} elseif (isset($urlParams[$param['name']])) {
				$args[] = $this->castScalar($urlParams[$param['name']], $param['type']);
			} elseif ($param['optional']) {
				$args[] = $param['default'];
			} else {
				throw new \RuntimeException(sprintf(
					"Cannot resolve parameter '%s' of type '%s' in %s::%s(). " .
					"Supported: %s, scalar route parameters, optional defaults.",
					$param['name'],
					$param['type'] ?? 'mixed',
					get_class($controller),
					$method,
					Request::class
				));
			}
		}

		return $args;
	}

	/**
	 * Приводит скаляр к типу параметра.
	 *
	 * @param mixed $value
	 * @param string|null $type
	 * @return mixed
	 */
	private function castScalar(mixed $value, ?string $type): mixed
	{
		if ($type === null) {
			return $value;
		}

		return match ($type) {
			'int'    => (int) $value,
			'float'  => (float) $value,
			'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
			'string' => (string) $value,
			default  => $value,
		};
	}
}