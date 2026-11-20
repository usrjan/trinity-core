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
 * [Лорелея]: Validator не знает о схеме. Он знает только
 * «спроси у RecordChecker». Потому что знание о том, что text
 * помечает удалённое через is_active, а neuron — через is_deleted,
 * — это знание о схеме. А схема — не его дело.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Jan\Trinity\Core\Service\RecordChecker;
use Psr\Log\LoggerInterface;

class Validator
{
	/** @var array Ошибки валидации [field => message]. */
	private array $errors = [];

	/** @var RecordChecker|null Проверка существования записей. */
	private ?RecordChecker $recordChecker;

	/** @var LoggerInterface|null Логгер. */
	private ?LoggerInterface $logger;

	/**
	 * Конструктор.
	 *
	 * @param RecordChecker|null $recordChecker
	 * @param LoggerInterface|null $logger
	 */
	public function __construct(
		?RecordChecker $recordChecker = null,
		?LoggerInterface $logger = null
	) {
		$this->recordChecker = $recordChecker;
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
					$pattern = $this->normalizeRegexPattern($param);

					$result = @preg_match($pattern, (string) $value);

					if ($result === false) {
						$this->errors[$field] = "Ошибка в regex для '{$field}'";
					} elseif ($result === 0) {
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
					if ($this->recordChecker === null) {
						throw new \RuntimeException('Validator: exists требует RecordChecker');
					}
					$parts2 = explode(',', $param);
					$table = $parts2[0] ?? null;
					$column = $parts2[1] ?? 'id';
					if ($table && !$this->recordChecker->exists($table, $column, $value)) {
						$this->errors[$field] = "Запись '{$value}' не найдена в '{$table}.{$column}'";
					}
				}
				break;

			case 'unique':
				if ($value !== null && $value !== '' && $param !== null) {
					if ($this->recordChecker === null) {
						throw new \RuntimeException('Validator: unique требует RecordChecker');
					}
					$parts2 = explode(',', $param);
					$table = $parts2[0] ?? null;
					$column = $parts2[1] ?? 'id';
					if ($table && $this->recordChecker->exists($table, $column, $value)) {
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

	/**
	 * Нормализует regex-паттерн: добавляет разделители и модификатор `u`.
	 *
	 * === ЧТО ДЕЛАЕТ ===
	 * - Если паттерн уже с разделителями (`/foo/`, `/foo/i`, `/foo/u`) —
	 *   добавляет `u`, если его нет. Или оставляет как есть.
	 * - Если паттерн без разделителей (`foo`, `[a-z]+`) — оборачивает
	 *   в `/.../u`.
	 *
	 * === ПОЧЕМУ `u` ===
	 * Потому что проект работает с юникодом (русский, английский,
	 * итальянский, немецкий, французский). Без `u` `\w`, `\d`, `.`
	 * не видят юникодные символы. А `preg_match('/^[а-я]+$/')` без `u`
	 * не сработает на «привет» — потому что `[а-я]` в байтовом режиме
	 * это не «кириллица», а «байты от 0xD0 до 0xBF».
	 *
	 * @param string $pattern — паттерн, возможно без разделителей
	 * @return string — паттерн с разделителями и модификатором `u`
	 */
	private function normalizeRegexPattern(string $pattern): string
	{
		$firstChar = $pattern[0] ?? '';
		$hasDelimiters = in_array($firstChar, ['/', '#', '~', '%', '!', '@'], true);

		if (!$hasDelimiters) {
			return '/' . $pattern . '/u';
		}

		$lastDelimiterPos = strrpos($pattern, $firstChar);
		if ($lastDelimiterPos === false || $lastDelimiterPos === 0) {
			return '/' . $pattern . '/u';
		}

		$body = substr($pattern, 0, $lastDelimiterPos + 1);
		$modifiers = substr($pattern, $lastDelimiterPos + 1);

		if (str_contains($modifiers, 'u')) {
			return $pattern;
		}

		return $body . $modifiers . 'u';
	}
}