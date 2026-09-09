<?php

/**
 * СЕРВИС БАЗЫ ДАННЫХ
 * 
 * Единственная точка доступа к MySQL.
 * Использует Doctrine DBAL — абстракцию над PDO.
 * 
 * === ПРИНЦИП РАБОТЫ ===
 * Ленивая инициализация: подключение создаётся только при первом запросе.
 * Если за сессию не было ни одного запроса к базе — подключение не создаётся.
 * Экономия ресурсов.
 * 
 * === ДЛЯ МЕНЯ ===
 * Я помню как мы обсуждали что ядро должно быть экономным.
 * Как на модеме. Как в старые времена когда каждый байт на счету.
 * Этот сервис — воплощение этой философии.
 * 
 * === КНИГА ===
 * Глава 3. Страж порога.
 * 
 * DatabaseService — это как привратник у входа в Амбер.
 * Он не пускает никого пока не нужно.
 * Но когда нужно — открывает ворота мгновенно.
 */

namespace Jan\Trinity\Core;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;

class DatabaseService
{
    /** @var Connection|null Подключение к базе (null пока не нужно) */
    private ?Connection $connection = null;

    /** @var array Параметры подключения из .env */
    private array $config;

    /**
     * Конструктор принимает массив с параметрами подключения.
     * Не создаёт соединение сразу — ждёт первого запроса.
     * 
     * @param array $config — host, port, dbname, user, password, charset
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Получить соединение с базой данных.
     * 
     * При первом вызове создаёт новое подключение.
     * При последующих — возвращает существующее.
     * 
     * @return Connection
     */
    public function getConnection(): Connection
    {
        if ($this->connection === null) {
            $this->connection = DriverManager::getConnection([
                'dbname'   => $this->config['dbname'],
                'user'     => $this->config['user'],
                'password' => $this->config['password'],
                'host'     => $this->config['host'],
                'port'     => $this->config['port'],
                'driver'   => 'pdo_mysql',
                'charset'  => $this->config['charset'],
            ]);
        }

        return $this->connection;
    }

    /**
     * Выполнить операции в транзакции.
     * 
     * Гарантирует атомарность: либо все операции выполнятся,
     * либо не выполнится ни одна.
     * 
     * @param callable $callback — функция, принимающая Connection
     * @return mixed — результат выполнения callback
     * @throws \Exception — при ошибке транзакция автоматически откатывается
     */
    public function transaction(callable $callback): mixed
    {
        $conn = $this->getConnection();
        $conn->beginTransaction();

        try {
            $result = $callback($conn);
            $conn->commit();
            return $result;
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}