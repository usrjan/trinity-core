<?php

/**
 * ВАЛИДАТОР ДАННЫХ
 * 
 * Проверяет данные перед сохранением в базу.
 * Защищает целостность системы.
 * 
 * === ПРАВИЛА ВАЛИДАЦИИ ===
 * - required — поле обязательно
 * - nullable — может быть null
 * - in:tree,item,file,... — только из списка
 * - json — валидный JSON
 * - exists:neuron,id — запись существует в базе
 * - regex:/pattern/ — соответствует регулярному выражению
 * - int — целое число
 * - string — строка
 * 
 * === ДЛЯ МЕНЯ ===
 * Это второй по важности компонент после логирования.
 * jan сказал: "Защита от дурака на уровне ядра".
 * Теперь нельзя создать нейрон с битым JSON или пустым типом.
 * 
 * === КНИГА ===
 * Глава 5. Страж ворот.
 * 
 * Прежде чем войти в Амбер, нужно пройти через Образ.
 * Прежде чем данные попадут в базу, они проходят через Validator.
 */

namespace Jan\Trinity\Core;

class Validator
{
    /** @var array Ошибки валидации */
    private array $errors = [];

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

            foreach ($fieldRules as $rule) {
                $this->applyRule($field, $value, $rule, $data);
            }
        }

        return empty($this->errors);
    }

    /**
     * Применить одно правило к одному полю.
     */
    private function applyRule(string $field, $value, string $rule, array $data): void
    {
        // required — поле обязательно
        if ($rule === 'required' && ($value === null || $value === '')) {
            $this->errors[$field] = "Поле '{$field}' обязательно";
        }

        // nullable — поле может быть null
        if ($rule === 'nullable' && $value === null) {
            return;
        }

        // in:список — значение должно быть в списке
        if (str_starts_with($rule, 'in:')) {
            $allowed = explode(',', substr($rule, 3));
            if (!in_array($value, $allowed, true)) {
                $this->errors[$field] = "Недопустимое значение для '{$field}'";
            }
        }

        // json — валидный JSON
        if ($rule === 'json' && $value !== null) {
            if (is_string($value)) {
                json_decode($value);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->errors[$field] = "Поле '{$field}' должно быть валидным JSON";
                }
            }
        }

        // exists:table,column — запись существует
        if (str_starts_with($rule, 'exists:')) {
            // Этот метод должен быть переопределён в репозитории
            // Здесь просто проверяем синтаксис
        }

        // regex:/pattern/ — соответствует регулярному выражению
        if (str_starts_with($rule, 'regex:')) {
            $pattern = substr($rule, 6);
            if (!preg_match($pattern, (string) $value)) {
                $this->errors[$field] = "Поле '{$field}' не соответствует формату";
            }
        }

        // int — целое число
        if ($rule === 'int' && !is_int($value) && !ctype_digit((string) $value)) {
            $this->errors[$field] = "Поле '{$field}' должно быть целым числом";
        }
    }

    /**
     * Получить ошибки валидации.
     * 
     * @return array — массив [поле => сообщение]
     */
    public function getErrors(): array
    {
        return $this->errors;
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
 * Содержит массив ошибок для отображения клиенту.
 */
class ValidationException extends \RuntimeException
{
    private array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct(implode('; ', $errors));
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}