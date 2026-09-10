<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\InvalidArgumentException;

/**
 * Класс Logger
 *
 * Реализация простого логгера, соответствующего стандарту PSR-3.
 * Записывает логи в файлы, разделяя их по уровням важности.
 *
 * Уровни логирования (от самого важного к менее важному):
 * - emergency: Система неработоспособна
 * - alert: Требуется немедленное действие
 * - critical: Критические условия
 * - error: Ошибки времени выполнения
 * - warning: Предупреждения
 * - notice: Нормальные, но значимые события
 * - info: Информационные сообщения
 * - debug: Отладочная информация
 *
 * @package Jan\Trinity\Core\Services
 */
class Logger implements LoggerInterface
{
    /**
     * Путь к директории с логами
     */
    private string $logPath;

    /**
     * Минимальный уровень логирования (все сообщения этого уровня и выше будут записаны)
     */
    private string $minLevel;

    /**
     * Карта приоритетов уровней логирования
     */
    private const LEVELS = [
        LogLevel::DEBUG     => 100,
        LogLevel::INFO      => 200,
        LogLevel::NOTICE    => 250,
        LogLevel::WARNING   => 300,
        LogLevel::ERROR     => 400,
        LogLevel::CRITICAL  => 500,
        LogLevel::ALERT     => 550,
        LogLevel::EMERGENCY => 600,
    ];

    /**
     * Конструктор
     *
     * @param string $logPath Путь к папке для хранения логов
     * @param string $minLevel Минимальный уровень для записи (по умолчанию 'debug')
     */
    public function __construct(string $logPath, string $minLevel = LogLevel::DEBUG)
    {
        $this->logPath = rtrim($logPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->minLevel = $minLevel;

        // Создаем директорию, если она не существует
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * {@inheritdoc}
     *
     * Основной метод записи лога.
     *
     * Алгоритм работы:
     * 1. Проверяет, проходит ли сообщение фильтр минимального уровня.
     * 2. Интерполирует контекстные переменные в сообщение (заменяет {key} на значение).
     * 3. Формирует строку лога с временной меткой, уровнем и сообщением.
     * 4. Определяет имя файла в зависимости от уровня (error.log, info.log или general.log).
     * 5. Дописывает строку в файл.
     *
     * @param mixed $level Уровень логирования
     * @param string|\Stringable $message Сообщение
     * @param array $context Контекстные данные
     * @throws InvalidArgumentException Если уровень логирования некорректен
     */
    public function log($level, $message, array $context = []): void
    {
        // Проверка корректности уровня
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException("Некорректный уровень логирования: {$level}");
        }

        // Фильтрация по минимальному уровню
        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }

        // Интерполяция контекста в сообщение
        // Пример: "User {id} logged in" + ['id' => 5] -> "User 5 logged in"
        $message = $this->interpolate($message, $context);

        // Форматирование строки лога
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $logLine = "[{$timestamp}] [{$levelUpper}] {$message}" . PHP_EOL;

        // Определение файла для записи
        // Ошибки и критические уровни пишем в отдельные файлы для быстрого доступа
        $fileName = match ($level) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR => 'error.log',
            LogLevel::WARNING => 'warning.log',
            default => 'general.log',
        };

        $filePath = $this->logPath . $fileName;

        // Запись в файл (FILE_APPEND добавляет в конец, LOCK_EX предотвращает гонки записи)
        file_put_contents($filePath, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Интерполяция переменных контекста в сообщение
     *
     * Заменяет плейсхолдеры вида {key} на соответствующие значения из массива context.
     * Исключает ключ 'exception', так как он обрабатывается отдельно (не выводится в тексте).
     *
     * @param string|\Stringable $message Сообщение
     * @param array $context Контекст
     * @return string Обработанное сообщение
     */
    private function interpolate($message, array $context = []): string
    {
        if (!$message instanceof \Stringable && !is_string($message)) {
            return (string) $message;
        }

        $message = (string) $message;

        $replace = [];
        foreach ($context as $key => $val) {
            // Пропускаем exception, так как это объект, который обычно логируется отдельно
            if ($key === 'exception' || !is_scalar($val) && !$val instanceof \Stringable) {
                continue;
            }

            $replace['{' . $key . '}'] = $val;
        }

        return strtr($message, $replace);
    }
}
