<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Event;

use Jan\Trinity\Core\DatabaseService;
use Jan\Trinity\Core\Repository\NeuronRepository;

/**
 * Диспетчер событий.
 * Управляет подпиской и публикацией событий через нейроны типа event_listener.
 */
class EventDispatcher
{
    private NeuronRepository $neuronRepository;
    private array $listeners = [];
    private bool $initialized = false;

    public function __construct(NeuronRepository $neuronRepository)
    {
        $this->neuronRepository = $neuronRepository;
    }

    /**
     * Загрузка слушателей из базы данных.
     */
    public function loadListeners(): void
    {
        if ($this->initialized) {
            return;
        }

        // Получаем все активные слушатели событий
        $listeners = $this->neuronRepository->findBy([
            'type' => 'event_listener',
            'event_is_active' => 1,
        ]);

        foreach ($listeners as $listener) {
            $eventName = $listener['event_name'];
            $priority = (int)($listener['event_priority'] ?? 0);
            $callback = $listener['event_callback'];

            if (!isset($this->listeners[$eventName])) {
                $this->listeners[$eventName] = [];
            }

            $this->listeners[$eventName][] = [
                'priority' => $priority,
                'callback' => $callback,
                'neuron_id' => $listener['id'],
            ];
        }

        // Сортируем по приоритету (чем выше число, тем раньше выполняется)
        foreach ($this->listeners as $eventName => $eventListeners) {
            usort($this->listeners[$eventName], function ($a, $b) {
                return $b['priority'] - $a['priority'];
            });
        }

        $this->initialized = true;
    }

    /**
     * Публикация события.
     *
     * @param string $eventName Имя события
     * @param array $payload Данные события
     * @return array Результаты выполнения всех слушателей
     */
    public function dispatch(string $eventName, array $payload = []): array
    {
        $this->loadListeners();

        $results = [];

        if (!isset($this->listeners[$eventName])) {
            return $results;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            try {
                $result = $this->executeListener($listener, $payload);
                $results[] = [
                    'neuron_id' => $listener['neuron_id'],
                    'callback' => $listener['callback'],
                    'success' => true,
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'neuron_id' => $listener['neuron_id'],
                    'callback' => $listener['callback'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Выполнение слушателя.
     */
    private function executeListener(array $listener, array $payload): mixed
    {
        $callback = $listener['callback'];

        // Поддержка различных форматов callback:
        // 1. Класс, реализующий EventListenerInterface
        // 2. Статический метод класса "Class@method"
        // 3. Closure (если сериализовано)

        if (class_exists($callback)) {
            $instance = new $callback();
            if ($instance instanceof EventListenerInterface) {
                return $instance->handle($payload);
            }
        }

        // Формат "Class@method"
        if (str_contains($callback, '@')) {
            [$className, $methodName] = explode('@', $callback, 2);
            if (class_exists($className)) {
                $instance = new $className();
                if (method_exists($instance, $methodName)) {
                    return $instance->$methodName($payload);
                }
            }
        }

        throw new \RuntimeException("Не удалось выполнить callback: {$callback}");
    }

    /**
     * Добавление слушателя программно.
     *
     * @param string $eventName Имя события
     * @param string $callback Callback (класс или Class@method)
     * @param int $priority Приоритет (по умолчанию 0)
     * @param array $data Дополнительные данные
     * @return int ID созданного нейрона
     */
    public function addListener(string $eventName, string $callback, int $priority = 0, array $data = []): int
    {
        $neuronData = array_merge($data, [
            'event_name' => $eventName,
            'callback' => $callback,
            'priority' => $priority,
            'is_active' => 1,
        ]);

        $neuronId = $this->neuronRepository->create([
            'type' => 'event_listener',
            'data' => $neuronData,
        ]);

        // Сбрасываем инициализацию для перезагрузки слушателей
        $this->initialized = false;

        return $neuronId;
    }

    /**
     * Удаление слушателя.
     *
     * @param int $neuronId ID нейрона-слушателя
     */
    public function removeListener(int $neuronId): void
    {
        $this->neuronRepository->delete($neuronId);
        $this->initialized = false;
    }

    /**
     * Получение всех слушателей для события.
     *
     * @param string $eventName Имя события
     * @return array Массив слушателей
     */
    public function getListeners(string $eventName): array
    {
        $this->loadListeners();
        return $this->listeners[$eventName] ?? [];
    }

    /**
     * Проверка наличия слушателей для события.
     *
     * @param string $eventName Имя события
     * @return bool true если есть слушатели
     */
    public function hasListeners(string $eventName): bool
    {
        $this->loadListeners();
        return isset($this->listeners[$eventName]) && count($this->listeners[$eventName]) > 0;
    }
}
