<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Event;

/**
 * Интерфейс для слушателей событий.
 * Слушатель должен реализовывать метод handle().
 */
interface EventListenerInterface
{
    /**
     * Обработка события.
     *
     * @param array $payload Данные события
     * @return mixed Результат обработки
     */
    public function handle(array $payload = []): mixed;
}
