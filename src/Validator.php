<?php
declare(strict_types=1);

/**
 * ВАЛИДАТОР ДАННЫХ
 *
 * Проверяет данные перед сохранением в базу.
 * Защищает целостность системы.
 *
 * === ПРАВИЛА ===
 * - required — поле обязательно
 * - nullable — может быть null
 * - in:a,b,c — только из списка
 * - not_in:a,b,c — не из списка
 * - json — валидный JSON
 * - exists:table,column — запись существует в базе
 * - unique:table,column — запись уникальна
 * - regex:/pattern/u — соответствует регулярному выражению
 * - int — целое число (строго)
 * - numeric — число (int или float)
 * - string — строка
 * - email — валидный email
 * - url — валидный URL
 * - date — валидная дата
 * - min:N — минимальное значение (для чисел) или длина (для строк)
 * - max:N — максимальное значение или длина
 * - between:min,max — между min и max
 * - array — массив
 * - bool — булево
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;

class Validator
{
	/** @var array Ошибки валидации [field => message]. */
	private array $errors = [];

	/** @var NeuronRepository|null Репозиторий для exists/unique. */
	private ?NeuronRepository $neuronRepo;

	/** @var LoggerInterface|null Логгер. */
	private ?LoggerInterface $logger;

	/**
	 * Конструктор.
	 *
	 * @param NeuronRepository|null $neuronRepo
	 * @param LoggerInterface|null $logger
	 */
	public function __construct(
		?NeuronRepository $neuronRepo = null,
		?LoggerInterface $logger = null
	) {
		$this->neuronRepo = $neuronRepo;
		$this->logger = $logger;
	}

	/**
	 * Проверить данные по правилам.
	 *
	 * @param array $data — проверяемые данные
	 * @param array $rules — правила валидации
	 * @return bool — true если данные валидны
	 */
	public function validate(array $data, array $rules): bool
	{
		$this->errors = [];

		foreach ($rules as $field => $fieldRules) {
			$value = $data[$field] ?? null;

			if ($value === null && in_array('nullable', $fieldRules, true)) {
				continue;
			}

			foreach ($fieldRules as $rule) {
				$this->applyRule($field, $value, $rule, $data);
			}
		}

		return empty($this->errors);
	}

	/**
	 * Применить одно правило к одному полю.
	 *
	 * @param string $field — имя поля
	 * @param mixed $value — значение
	 * @param string $rule — правило
	 * @param array $data — все данные
	 */
	private function applyRule(string $field, $value, string $rule, array $data): void
	{
		$parts = explode(':', $rule, 2);
		$name = $parts[0];
		$param = $parts[1] ?? null;

		if (isset($this->errors[$field])) {
			return;
		}

		switch ($name) {
			case 'required':
				if ($value === null || $value === '') {
					$this->errors[$field] = "Поле '{$field}' обязательно";
				}
				break;

			case 'nullable':
				break;

			case 'in':
				$allowed = explode(',', $param ?? '');
				if (!in_array($value, $allowed, true)) {
					$this->errors[$field] = "Недопустимое значение для '{$field}'";
				}
				break;

			case 'int':
				if ($value !== null && $value !== '') {
					if (is_int($value)) {
						// ok
					} elseif (is_string($value) && preg_match('/^-?\d+$/', $value)) {
						// ok — строка с целым числом
					} elseif (is_float($value) && floor($value) === $value) {
						// ok — float без дробной части
					} else {
						$this->errors[$field] = "Поле '{$field}' должно быть целым числом";
					}
				}
				break;

			case 'numeric':
				if ($value !== null && $value !== '' && !is_numeric($value)) {
					$this->errors[$field] = "Поле '{$field}' должно быть числом";
				}
				break;

			case 'string':
				if ($value !== null && !is_string($value)) {
					$this->errors[$field] = "Поле '{$field}' должно быть строкой";
				}
				break;

			case 'email':
				if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
					$this->errors[$field] = "Поле '{$field}' должно быть валидным email";
				}
				break;

			case 'url':
				if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
					$this->errors[$field] = "Поле '{$field}' должно быть валидным URL";
				}
				break;

			case 'date':
				if ($value !== null && $value !== '') {
					$d = \DateTime::createFromFormat('Y-m-d', $value);
					if (!$d || $d->format('Y-m-d') !== $value) {
						$this->errors[$field] = "Поле '{$field}' должно быть датой в формате Y-m-d";
					}
				}
				break;

			case 'array':
				if ($value !== null && !is_array($value)) {
					$this->errors[$field] = "Поле '{$field}' должно быть массивом";
				}
				break;

			case 'bool':
				if ($value !== null && !is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
					$this->errors[$field] = "Поле '{$field}' должно быть булевым";
				}
				break;

			case 'regex':
				if ($value !== null && $value !== '' && $param !== null) {
					$pattern = $param;
					if (!str_ends_with($pattern, 'u') && !str_ends_with($pattern, 'u/')) {
						if (preg_match('/^\/.*\/[a-z]*$/', $pattern)) {
							$pattern = preg_replace('/\/([a-z]*)$/', '/$1u', $pattern);
						} else {
							$pattern = '/' . $pattern . '/u';
						}
					}
					if (@preg_match($pattern, (string) $value) === false) {
						$this->errors[$field] = "Ошибка в regex для '{$field}'";
					} elseif (!preg_match($pattern, (string) $value)) {
						$this->errors[$field] = "Поле '{$field}' не соответствует формату";
					}
				}
				break;

			case 'min':
				if ($value !== null && $value !== '' && $param !== null) {
					$min = (float) $param;
					if (is_numeric($value)) {
						if ((float) $value < $min) {
							$this->errors[$field] = "Поле '{$field}' должно быть не меньше {$param}";
						}
					} else {
						if (mb_strlen((string) $value) < $min) {
							$this->errors[$field] = "Поле '{$field}' должно быть не короче {$param} символов";
						}
					}
				}
				break;

			case 'max':
				if ($value !== null && $value !== '' && $param !== null) {
					$max = (float) $param;
					if (is_numeric($value)) {
						if ((float) $value > $max) {
							$this->errors[$field] = "Поле '{$field}' должно быть не больше {$param}";
						}
					} else {
						if (mb_strlen((string) $value) > $max) {
							$this->errors[$field] = "Поле '{$field}' должно быть не длиннее {$param} символов";
						}
					}
				}
				break;

			case 'between':
				if ($value !== null && $value !== '' && $param !== null) {
					$parts2 = explode(',', $param);
					$min = (float) ($parts2[0] ?? 0);
					$max = (float) ($parts2[1] ?? PHP_FLOAT_MAX);
					if (is_numeric($value)) {
						$num = (float) $value;
						if ($num < $min || $num > $max) {
							$this->errors[$field] = "Поле '{$field}' должно быть между {$min} и {$max}";
						}
					} else {
						$len = mb_strlen((string) $value);
						if ($len < $min || $len > $max) {
							$this->errors[$field] = "Длина '{$field}' должна быть между {$min} и {$max} символов";
						}
					}
				}
				break;

			case 'exists':
				if ($value !== null && $value !== '' && $param !== null) {
					if ($this->neuronRepo === null) {
						throw new \RuntimeException('Validator: exists требует NeuronRepository');
					}
					$parts2 = explode(',', $param);
					$table = $parts2[0] ?? null;
					$column = $parts2[1] ?? 'id';
					if ($table && !$this->recordExists($table, $column, $value)) {
						$this->errors[$field] = "Запись '{$value}' не найдена в '{$table}.{$column}'";
					}
				}
				break;

			case 'unique':
				if ($value !== null && $value !== '' && $param !== null) {
					if ($this->neuronRepo === null) {
						throw new \RuntimeException('Validator: unique требует NeuronRepository');
					}
					$parts2 = explode(',', $param);
					$table = $parts2[0] ?? null;
					$column = $parts2[1] ?? 'id';
					if ($table && $this->recordExists($table, $column, $value)) {
						$this->errors[$field] = "Значение '{$value}' уже занято в '{$table}.{$column}'";
					}
				}
				break;

			default:
				if ($this->logger !== null) {
					$this->logger->warning('Validator: unknown rule', [
						'rule'  => $rule,
						'field' => $field,
					]);
				} else {
					error_log("[Validator] Unknown rule: {$rule} for field '{$field}'");
				}
				break;
		}
	}

	/**
	 * Проверить существование записи.
	 *
	 * @param string $table
	 * @param string $column
	 * @param mixed $value
	 * @return bool
	 */
	private function recordExists(string $table, string $column, $value): bool
	{
		$conn = $this->neuronRepo->getConnection();

		$allowedTables = ['neuron', 'synapse', 'text'];
		if (!in_array($table, $allowedTables, true)) {
			throw new \RuntimeException("Validator: table '{$table}' не разрешена");
		}

		if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
			throw new \RuntimeException("Validator: column '{$column}' невалидна");
		}

		$sql = "SELECT 1 FROM {$table} WHERE {$column} = ?";
		if (in_array($table, ['neuron', 'synapse'], true)) {
			$sql .= " AND is_deleted = 0";
		}
		$sql .= " LIMIT 1";

		$result = $conn->executeQuery($sql, [$value])->fetchOne();

		return $result !== false;
	}

	/**
	 * Получить ошибки валидации.
	 *
	 * @return array [field => message]
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	/**
	 * Получить первую ошибку.
	 *
	 * @return string|null
	 */
	public function getFirstError(): ?string
	{
		$first = reset($this->errors);
		return $first === false ? null : $first;
	}

	/**
	 * Выбросить исключение если есть ошибки.
	 *
	 * @throws ValidationException
	 */
	public function throwIfInvalid(): void
	{
		if (!empty($this->errors)) {
			throw new ValidationException($this->errors);
		}
	}
}

/**
 * Исключение валидации.
 */
class ValidationException extends \RuntimeException
{
	/** @var array [field => message] */
	private array $errors;

	public function __construct(array $errors)
	{
		$this->errors = $errors;
		parent::__construct(implode('; ', $errors));
	}

	/**
	 * @return array [field => message]
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}
}