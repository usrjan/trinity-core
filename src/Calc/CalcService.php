<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Calc;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Jan\Trinity\Core\DatabaseService;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;

/**
 * Сервис для вычисления формул в нейронах типа `calc`.
 * 
 * Использует Symfony Expression Language для безопасного вычисления выражений.
 * Поддерживает переменные из связанных нейронов через синапсы.
 */
class CalcService
{
    private ExpressionLanguage $language;
    private DatabaseService $db;
    private NeuronRepository $neuronRepo;
    private LoggerInterface $logger;

    public function __construct(
        DatabaseService $db,
        NeuronRepository $neuronRepo,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->neuronRepo = $neuronRepo;
        $this->logger = $logger;
        
        $this->language = new ExpressionLanguage();
        
        // Регистрация кастомных функций для использования в формулах
        $this->registerCustomFunctions();
    }

    /**
     * Регистрирует пользовательские функции для использования в выражениях
     */
    private function registerCustomFunctions(): void
    {
        // Функция для получения значения нейрона по ID
        $this->language->register('neuron', function ($id) {
            return sprintf('getNeuronValue(%s)', $id);
        }, function ($arguments, $id) {
            return $this->getNeuronValue((int)$id);
        });

        // Функция для получения последнего значения синапса
        $this->language->register('synapse', function ($parentId, $relationType) {
            return sprintf('getSynapseValue(%s, %s)', $parentId, $relationType);
        }, function ($arguments, $parentId, $relationType) {
            return $this->getSynapseValue((int)$parentId, $relationType);
        });

        // Математические утилиты
        $this->language->register('round', function ($value, $precision = 2) {
            return sprintf('round(%s, %s)', $value, $precision);
        }, function ($arguments, $value, $precision = 2) {
            return round((float)$value, (int)$precision);
        });

        $this->language->register('max', function (...$values) {
            return 'max(...' . implode(',', array_map(fn($v) => "\$$v", func_get_args())) . ')';
        }, function ($arguments, ...$values) {
            return max($values);
        });

        $this->language->register('min', function (...$values) {
            return 'min(...' . implode(',', array_map(fn($v) => "\$$v", func_get_args())) . ')';
        }, function ($arguments, ...$values) {
            return min($values);
        });
    }

    /**
     * Вычисляет значение формулы для нейрона типа `calc`
     * 
     * @param int $calcId ID нейрона типа `calc`
     * @return float|null Результат вычисления или null при ошибке
     */
    public function calculate(int $calcId): ?float
    {
        try {
            // Получаем нейрон calc
            $calc = $this->neuronRepo->find($calcId);
            
            if (!$calc || $calc['type'] !== 'calc') {
                $this->logger->warning("CalcService: Нейрон ID=$calcId не является типом 'calc'");
                return null;
            }

            // Извлекаем формулу из data
            $formula = $calc['data']['formula'] ?? null;
            
            if (!$formula) {
                $this->logger->warning("CalcService: У нейрона ID=$calcId нет формулы в data.formula");
                return null;
            }

            // Собираем переменные из синапсов
            $variables = $this->collectVariables($calcId);

            // Вычисляем выражение
            $result = $this->language->evaluate($formula, $variables);
            
            $this->logger->info("CalcService: Вычислено ID=$calcId, формула='$formula', результат=$result", [
                'variables' => $variables
            ]);

            return (float)$result;
            
        } catch (\Exception $e) {
            $this->logger->error("CalcService: Ошибка вычисления для ID=$calcId: " . $e->getMessage(), [
                'exception' => $e
            ]);
            return null;
        }
    }

    /**
     * Собирает переменные для формулы из синапсов, связанных с calc-нейроном
     * 
     * @param int $calcId ID нейрона типа `calc`
     * @return array Массив переменных [name => value]
     */
    private function collectVariables(int $calcId): array
    {
        $variables = [];
        
        // Получаем все синапсы, где parent = calcId
        // Эти синапсы связывают calc с нейронами-источниками данных
        $sql = "SELECT s.*, n.data as source_data 
                FROM synapse s
                JOIN neuron n ON s.child = n.id
                WHERE s.parent = :parent_id
                ORDER BY s.time DESC";
        
        $synapses = $this->db->fetchAllAssociative($sql, ['parent_id' => $calcId]);
        
        foreach ($synapses as $synapse) {
            // Имя переменной берется из data.relation или text
            $varName = $synapse['data']['variable_name'] ?? 'var_' . $synapse['child'];
            
            // Значение переменной
            $value = null;
            
            // Если указано конкретное поле в data.source_field, берем его
            if (!empty($synapse['data']['source_field'])) {
                $field = $synapse['data']['source_field'];
                $value = $synapse['source_data'][$field] ?? null;
            } else {
                // Иначе берем всё data или числовое значение
                $value = $synapse['source_data']['value'] ?? 
                         $synapse['source_data']['rate'] ?? 
                         $synapse['source_data']['amount'] ??
                         (is_numeric($synapse['source_data']) ? (float)$synapse['source_data'] : 0);
            }
            
            // Преобразуем к float если возможно
            if (is_numeric($value)) {
                $value = (float)$value;
            }
            
            $variables[$varName] = $value;
        }
        
        // Добавляем собственные данные calc-нейрона как переменные
        $calc = $this->neuronRepo->find($calcId);
        if ($calc && !empty($calc['data'])) {
            foreach ($calc['data'] as $key => $val) {
                if (is_numeric($val) && !isset($variables[$key])) {
                    $variables[$key] = (float)$val;
                }
            }
        }
        
        return $variables;
    }

    /**
     * Получает значение нейрона (для использования в формулах через функцию neuron())
     */
    public function getNeuronValue(int $neuronId): float
    {
        $neuron = $this->neuronRepo->find($neuronId);
        
        if (!$neuron) {
            return 0.0;
        }
        
        // Пытаемся получить значение из различных полей
        $value = $neuron['data']['value'] ?? 
                 $neuron['data']['amount'] ?? 
                 $neuron['data']['price'] ?? 
                 0;
        
        return is_numeric($value) ? (float)$value : 0.0;
    }

    /**
     * Получает последнее значение синапса (для использования в формулах через функцию synapse())
     */
    public function getSynapseValue(int $parentId, string $relationType): float
    {
        $sql = "SELECT s.data 
                FROM synapse s
                WHERE s.parent = :parent_id 
                  AND s.relation_type = :relation_type
                ORDER BY s.time DESC 
                LIMIT 1";
        
        $result = $this->db->fetchAssociative($sql, [
            'parent_id' => $parentId,
            'relation_type' => $relationType
        ]);
        
        if (!$result) {
            return 0.0;
        }
        
        $data = json_decode($result['data'], true);
        $value = $data['value'] ?? $data['rate'] ?? $data['amount'] ?? 0;
        
        return is_numeric($value) ? (float)$value : 0.0;
    }

    /**
     * Пересчитывает все зависимые калькуляции при изменении нейрона
     * 
     * @param int $neuronId ID измененного нейрона
     */
    public function recalculateDependencies(int $neuronId): void
    {
        // Находим все calc-нейроны, которые зависят от этого нейрона
        // Через синапсы где child = neuronId
        $sql = "SELECT DISTINCT s.parent as calc_id
                FROM synapse s
                JOIN neuron n ON s.parent = n.id
                WHERE s.child = :child_id 
                  AND n.type = 'calc'";
        
        $calcIds = $this->db->fetchFirstColumn($sql, ['child_id' => $neuronId]);
        
        foreach ($calcIds as $calcId) {
            $result = $this->calculate((int)$calcId);
            
            if ($result !== null) {
                // Сохраняем результат в виде синапса или обновляем data нейрона
                $this->saveResult((int)$calcId, $result);
            }
        }
    }

    /**
     * Сохраняет результат вычисления
     * 
     * @param int $calcId ID калькуляции
     * @param float $result Результат
     */
    private function saveResult(int $calcId, float $result): void
    {
        $calc = $this->neuronRepo->find($calcId);
        
        if (!$calc) {
            return;
        }
        
        // Обновляем data нейрона с результатом и временем вычисления
        $data = $calc['data'] ?? [];
        $data['last_result'] = $result;
        $data['calculated_at'] = date('Y-m-d H:i:s');
        
        $this->neuronRepo->update($calcId, ['data' => $data]);
        
        // Если есть target_neuron в настройках, создаем/обновляем синапс
        if (!empty($calc['data']['target_neuron'])) {
            $targetId = (int)$calc['data']['target_neuron'];
            
            // Создаем синапс с результатом
            $this->db->insert('synapse', [
                'parent' => $calcId,
                'child' => $targetId,
                'data' => json_encode([
                    'relation' => 'calculation_result',
                    'value' => $result,
                    'calculated_at' => $data['calculated_at']
                ]),
                'time' => date('Y-m-d H:i:s')
            ]);
        }
        
        $this->logger->info("CalcService: Результат сохранен для ID=$calcId, result=$result");
    }
}
