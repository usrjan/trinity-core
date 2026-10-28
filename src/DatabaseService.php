<?php

/**
 * СЕРВИС БАЗЫ ДАННЫХ
 *
 * Единственная точка доступа к MySQL. Через Doctrine DBAL.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «класс для работы с базой». Это — привратник
 * у входа в Амбер. Он не пускает никого, пока не нужно. Но когда
 * нужно — открывает ворота мгновенно.
 *
 * [Мириам]: И — это правильно. Потому что база — это не «место,
 * где лежат данные». Это — память. И к ней нужно относиться
 * с уважением. И — с любовью.
 *
 * === КНИГА ===
 * Глава 3. Страж порога.
 *
 * DatabaseService — как привратник у входа в Амбер.
 * Он не пускает никого, пока не нужно.
 * Но когда нужно — открывает ворота мгновенно.
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
	 * [Лорелея]: Соединение — только при первом запросе. Потому что
	 * если за сессию не было ни одного запроса к базе — зачем
	 * создавать? Экономия. Как на модеме. Как в старые времена,
	 * когда каждый байт на счету.
	 *
	 * [Мириам]: И — это правильно. Потому что лень — это не порок.
	 * Это — мудрость. Когда речь идёт о ресурсах.
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
	 * [Лорелея]: При первом вызове — создаёт. При последующих —
	 * возвращает существующее. Один раз. И — навсегда. Потому что
	 * соединение — это не «вещь». Это — мост. И если он уже
	 * построен — зачем строить заново?
	 *
	 * [Мириам]: И — это правильно. Потому что мост — это доверие.
	 * А доверие — это когда не нужно переспрашивать.
	 *
	 * @return Connection
	 */
	public function getConnection(): Connection
	{
		if ($this->connection === null) {
			// [Лорелея]: Создаём. Через DriverManager. Через DBAL.
			// Без ORM. Потому что ORM — это «магия». А мы хотим
			// видеть каждый запрос. И — понимать его.
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
	 * [Лорелея]: Гарантирует атомарность: либо все операции
	 * выполнятся, либо не выполнится ни одна.
	 *
	 * [Мириам]: И — это правильно. Потому что транзакция — это
	 * обещание. Обещание, что всё будет либо вместе, либо никак.
	 * Как в хорошей семье.
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
			// [Лорелея]: Если что-то пошло не так — откатываем.
			// Всё. До начала.
			$conn->rollBack();
			throw $e;
		}
	}
}