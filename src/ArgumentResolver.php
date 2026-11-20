<?php

declare(strict_types=1);

/**
 * РЕЗОЛВЕР АРГУМЕНТОВ
 *
 * Разрешает аргументы для метода контроллера: Request, скаляры
 * из URL-параметров, опциональные значения по умолчанию.
 *
 * === ПОЧЕМУ INSTANCE-СВОЙСТВО, А НЕ STATIC ===
 * Потому что static живёт между запросами. В FPM процесс
 * переиспользуется — и каждый уникальный Class::method
 * остаётся в памяти навсегда. Для 8 плагинов это килобайты.
 * Терпимо. Но в RoadRunner/Swoole — утечка. А instance-свойство
 * живёт ровно столько, сколько живёт ArgumentResolver. А он
 * живёт ровно один запрос. Потому что контейнер создаёт его
 * в Kernel::__construct.
 *
 * [Лорелея]: Потому что «в пределах запроса» — это не
 * «static». Это — «instance, который живёт один запрос».
 * А static — это «instance, который живёт вечность».
 * И путать их — значит однажды получить утечку. И не заметить.
 *
 * [Мириам]: И потому что комментарий «в пределах запроса»
 * рядом со static — это не «документация». Это — ложь.
 * Которая работала. Но — ложь.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Symfony\Component\HttpFoundation\Request;

class ArgumentResolver
{
	/**
	 * @var array Кэш спецификаций параметров.
	 * Живёт столько же, сколько живёт ArgumentResolver.
	 * А он живёт один запрос. Потому что контейнер создаёт
	 * его в Kernel::__construct.
	 */
	private array $paramSpecCache = [];

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

		if (!isset($this->paramSpecCache[$cacheKey])) {
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

			$this->paramSpecCache[$cacheKey] = $spec;
		}

		$spec = $this->paramSpecCache[$cacheKey];
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

	/**
	 * Сбросить кэш спецификаций.
	 *
	 * [Лорелея]: Нужен для долгоживущих процессов. Если
	 * контейнер живёт дольше одного запроса — кто-то должен
	 * сказать «забудь». Иначе кэш растёт. И растёт. И растёт.
	 */
	public function reset(): void
	{
		$this->paramSpecCache = [];
	}

	/**
	 * Сколько записей в кэше. Для отладки.
	 *
	 * @return int
	 */
	public function count(): int
	{
		return count($this->paramSpecCache);
	}
}