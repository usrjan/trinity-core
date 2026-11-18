<?php
declare(strict_types=1);

/**
 * СЕРВИС БАЗЫ ДАННЫХ
 *
 * Единственная точка доступа к MySQL. Через Doctrine DBAL.
 *
 * @package Jan\Trinity\Core
 */

namespace Jan\Trinity\Core;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;

class DatabaseService
{
	/** @var Connection|null Подключение к базе. Пока не нужно — null. */
	private ?Connection $connection = null;

	/** @var array Параметры подключения из .env. Кровь системы. */
	private array $config;

	/**
	 * Конструктор. Принимает параметры. Не создаёт соединение.
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