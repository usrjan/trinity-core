<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Schedule;

use Jan\Trinity\Core\Repository\NeuronRepository;

/**
 * Планировщик задач.
 * Управляет задачами по расписанию через нейроны типа schedule.
 */
class Scheduler
{
    private NeuronRepository $neuronRepository;
    private array $schedules = [];
    private bool $initialized = false;

    public function __construct(NeuronRepository $neuronRepository)
    {
        $this->neuronRepository = $neuronRepository;
    }

    /**
     * Загрузка расписаний из базы данных.
     */
    public function loadSchedules(): void
    {
        if ($this->initialized) {
            return;
        }

        // Получаем все активные расписания
        $schedules = $this->neuronRepository->findBy([
            'type' => 'schedule',
            'schedule_is_active' => 1,
        ]);

        foreach ($schedules as $schedule) {
            $this->schedules[] = [
                'neuron_id' => $schedule['id'],
                'cron_expression' => $schedule['schedule_cron'],
                'command' => $schedule['schedule_command'],
                'last_run' => $schedule['schedule_last_run'],
                'next_run' => $schedule['schedule_next_run'],
                'is_active' => (bool)$schedule['schedule_is_active'],
                'data' => $schedule['data'],
            ];
        }

        $this->initialized = true;
    }

    /**
     * Расчет следующего времени запуска для cron-выражения.
     *
     * @param string $cronExpression Cron-выражение (5 полей: минута час день месяц день_недели)
     * @param \DateTimeInterface $from Начальная дата
     * @return \DateTime|null Следующее время запуска или null если выражение некорректно
     */
    public function getNextRunTime(string $cronExpression, \DateTimeInterface $from = null): ?\DateTime
    {
        if ($from === null) {
            $from = new \DateTime();
        } else {
            $from = clone $from;
        }

        // Простая реализация парсера cron (для базовых выражений)
        // Формат: минута час день месяц день_недели
        $parts = preg_split('/\s+/', trim($cronExpression));
        if (count($parts) !== 5) {
            return null;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        // Сбрасываем секунды
        $from->setSecond(0);
        
        // Пробуем найти следующее время в пределах года
        $maxIterations = 525600; // минут в году
        $iterations = 0;

        while ($iterations < $maxIterations) {
            $iterations++;

            // Проверка месяца
            if (!$this->matchCronField((int)$from->format('n'), $month, 1, 12)) {
                $from->modify('+1 month');
                $from->setDate((int)$from->format('Y'), (int)$from->format('m'), 1);
                $from->setTime(0, 0);
                continue;
            }

            // Проверка дня месяца или дня недели
            $dayMatch = $this->matchCronField((int)$from->format('j'), $dayOfMonth, 1, 31);
            $weekdayMatch = $this->matchCronField((int)$from->format('w'), $dayOfWeek, 0, 6);
            
            // В cron ИЛИ между днем и днем недели (если оба не *)
            if ($dayOfMonth !== '*' && $dayOfWeek !== '*') {
                if (!$dayMatch && !$weekdayMatch) {
                    $from->modify('+1 day');
                    $from->setTime(0, 0);
                    continue;
                }
            } else {
                if (!$dayMatch && !$weekdayMatch) {
                    $from->modify('+1 day');
                    $from->setTime(0, 0);
                    continue;
                }
            }

            // Проверка часа
            if (!$this->matchCronField((int)$from->format('G'), $hour, 0, 23)) {
                $from->modify('+1 hour');
                $from->setMinute(0);
                continue;
            }

            // Проверка минуты
            if (!$this->matchCronField((int)$from->format('i'), $minute, 0, 59)) {
                $from->modify('+1 minute');
                continue;
            }

            // Если время в прошлом, переходим к следующей минуте
            if ($from <= new \DateTime()) {
                $from->modify('+1 minute');
                continue;
            }

            return $from;
        }

        return null;
    }

    /**
     * Проверка соответствия значения cron-полю.
     */
    private function matchCronField(int $value, string $field, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        // Поддержка шагов */5
        if (str_starts_with($field, '*/')) {
            $step = (int)substr($field, 2);
            return $step > 0 && ($value % $step) === 0;
        }

        // Поддержка диапазонов 1-5
        if (str_contains($field, '-')) {
            [$start, $end] = explode('-', $field);
            return $value >= (int)$start && $value <= (int)$end;
        }

        // Поддержка списков 1,3,5
        if (str_contains($field, ',')) {
            $values = array_map('intval', explode(',', $field));
            return in_array($value, $values);
        }

        // Конкретное значение
        return $value === (int)$field;
    }

    /**
     * Обновление времени следующего запуска для расписания.
     *
     * @param int $neuronId ID нейрона
     * @param \DateTime|null $nextRun Время следующего запуска
     */
    public function updateNextRun(int $neuronId, ?\DateTime $nextRun): void
    {
        $neuron = $this->neuronRepository->find($neuronId);
        if (!$neuron) {
            return;
        }

        $data = is_string($neuron['data']) ? json_decode($neuron['data'], true) : $neuron['data'];
        $data['next_run'] = $nextRun?->format('Y-m-d H:i:s');

        $this->neuronRepository->update($neuronId, ['data' => $data]);
    }

    /**
     * Отметка о выполнении расписания.
     *
     * @param int $neuronId ID нейрона
     */
    public function markAsRun(int $neuronId): void
    {
        $neuron = $this->neuronRepository->find($neuronId);
        if (!$neuron) {
            return;
        }

        $now = new \DateTime();
        $data = is_string($neuron['data']) ? json_decode($neuron['data'], true) : $neuron['data'];
        $data['last_run'] = $now->format('Y-m-d H:i:s');
        
        // Обновляем next_run
        if (!empty($data['cron_expression'])) {
            $nextRun = $this->getNextRunTime($data['cron_expression'], $now);
            $data['next_run'] = $nextRun?->format('Y-m-d H:i:s');
        }

        $this->neuronRepository->update($neuronId, ['data' => $data]);
    }

    /**
     * Получение всех расписаний, которые нужно выполнить.
     *
     * @return array Массив расписаний для выполнения
     */
    public function getDueSchedules(): array
    {
        $this->loadSchedules();
        $now = new \DateTime();
        $due = [];

        foreach ($this->schedules as $schedule) {
            if (!$schedule['is_active']) {
                continue;
            }

            $nextRun = !empty($schedule['next_run']) 
                ? new \DateTime($schedule['next_run']) 
                : null;

            // Если next_run не установлен или настало время
            if ($nextRun === null || $nextRun <= $now) {
                $due[] = $schedule;
            }
        }

        return $due;
    }

    /**
     * Выполнение расписания.
     *
     * @param array $schedule Данные расписания
     * @return mixed Результат выполнения команды
     */
    public function executeSchedule(array $schedule): mixed
    {
        $command = $schedule['command'];
        
        // Поддержка различных форматов команд:
        // 1. Класс с методом execute()
        // 2. Статический метод "Class@method"
        // 3. PHP-код для eval (не рекомендуется для безопасности)

        try {
            if (class_exists($command)) {
                $instance = new $command();
                if (method_exists($instance, 'execute')) {
                    $result = $instance->execute($schedule['data']);
                    $this->markAsRun($schedule['neuron_id']);
                    return $result;
                }
            }

            // Формат "Class@method"
            if (str_contains($command, '@')) {
                [$className, $methodName] = explode('@', $command, 2);
                if (class_exists($className)) {
                    $instance = new $className();
                    if (method_exists($instance, $methodName)) {
                        $result = $instance->$methodName($schedule['data']);
                        $this->markAsRun($schedule['neuron_id']);
                        return $result;
                    }
                }
            }

            throw new \RuntimeException("Не удалось выполнить команду: {$command}");
        } catch (\Throwable $e) {
            // Логируем ошибку, но не прерываем выполнение других расписаний
            error_log("Scheduler error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Запуск планировщика (проверка и выполнение всех_due_ расписаний).
     *
     * @return array Результаты выполнения
     */
    public function run(): array
    {
        $results = [];
        $dueSchedules = $this->getDueSchedules();

        foreach ($dueSchedules as $schedule) {
            $result = $this->executeSchedule($schedule);
            $results[] = [
                'neuron_id' => $schedule['neuron_id'],
                'command' => $schedule['command'],
                'success' => $result !== null,
                'result' => $result,
            ];
        }

        return $results;
    }

    /**
     * Добавление нового расписания.
     *
     * @param string $cronExpression Cron-выражение
     * @param string $command Команда для выполнения
     * @param array $data Дополнительные данные
     * @return int ID созданного нейрона
     */
    public function addSchedule(string $cronExpression, string $command, array $data = []): int
    {
        $now = new \DateTime();
        $nextRun = $this->getNextRunTime($cronExpression, $now);

        $neuronData = array_merge($data, [
            'cron_expression' => $cronExpression,
            'command' => $command,
            'is_active' => 1,
            'next_run' => $nextRun?->format('Y-m-d H:i:s'),
        ]);

        $neuronId = $this->neuronRepository->create([
            'type' => 'schedule',
            'data' => $neuronData,
        ]);

        $this->initialized = false;

        return $neuronId;
    }

    /**
     * Удаление расписания.
     *
     * @param int $neuronId ID нейрона
     */
    public function removeSchedule(int $neuronId): void
    {
        $this->neuronRepository->delete($neuronId);
        $this->initialized = false;
    }

    /**
     * Активация/деактивация расписания.
     *
     * @param int $neuronId ID нейрона
     * @param bool $isActive Статус активности
     */
    public function setActive(int $neuronId, bool $isActive): void
    {
        $neuron = $this->neuronRepository->find($neuronId);
        if (!$neuron) {
            return;
        }

        $data = is_string($neuron['data']) ? json_decode($neuron['data'], true) : $neuron['data'];
        $data['is_active'] = $isActive ? 1 : 0;

        $this->neuronRepository->update($neuronId, ['data' => $data]);
        $this->initialized = false;
    }
}
