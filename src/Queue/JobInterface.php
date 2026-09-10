<?php

declare(strict_types=1);

namespace Trinity\Queue;

/**
 * Интерфейс задачи для очереди.
 * 
 * Все классы задач, которые должны обрабатываться в фоне,
 * обязаны реализовывать этот интерфейс.
 */
interface JobInterface
{
    /**
     * Выполняет логику задачи.
     * 
     * @param array $payload Данные задачи (ассоциативный массив)
     * @return void
     * @throws \Exception Если задача не может быть выполнена
     */
    public function execute(array $payload): void;

    /**
     * Возвращает имя очереди для данной задачи.
     * Позволяет распределять задачи по разным очередям.
     * 
     * @return string Имя очереди (по умолчанию 'default')
     */
    public function getQueue(): string;

    /**
     * Возвращает максимальное количество попыток выполнения.
     * 
     * @return int Количество попыток (по умолчанию 3)
     */
    public function getMaxAttempts(): int;

    /**
     * Вызывается при успешном выполнении задачи.
     * Можно использовать для логирования или очистки.
     * 
     * @param array $payload Данные задачи
     * @return void
     */
    public function onSuccess(array $payload): void;

    /**
     * Вызывается при неудачном выполнении задачи после исчерпания попыток.
     * Можно использовать для уведомления администратора.
     * 
     * @param array $payload Данные задачи
     * @param \Throwable $exception Ошибка, вызвавшая провал
     * @return void
     */
    public function onFailure(array $payload, \Throwable $exception): void;
}
