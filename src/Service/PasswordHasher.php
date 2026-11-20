<?php

declare(strict_types=1);

/**
 * ХЕШЕР ПАРОЛЕЙ
 *
 * Один источник правды для password_hash/password_verify/password_needs_rehash.
 * Алгоритм и параметры — в одном месте. Не в трёх контроллерах.
 *
 * @package Jan\Trinity\Core\Service
 */

namespace Jan\Trinity\Core\Service;

class PasswordHasher
{
	/** @var string Алгоритм хеширования. Один. На всех. */
	private const ALGO = PASSWORD_BCRYPT;

	/** @var array Параметры хеширования. Один. На всех. */
	private const OPTIONS = ['cost' => 12];

	/**
	 * Хеширует пароль.
	 *
	 * @param string $password — чистый пароль
	 * @return string — хеш
	 */
	public function hash(string $password): string
	{
		return password_hash($password, self::ALGO, self::OPTIONS);
	}

	/**
	 * Проверяет пароль против хеша.
	 *
	 * @param string $password — чистый пароль
	 * @param string $hash — хеш из базы
	 * @return bool
	 */
	public function verify(string $password, string $hash): bool
	{
		return password_verify($password, $hash);
	}

	/**
	 * Нужно ли перехешировать пароль.
	 *
	 * @param string $hash — текущий хеш
	 * @return bool
	 */
	public function needsRehash(string $hash): bool
	{
		return password_needs_rehash($hash, self::ALGO, self::OPTIONS);
	}
}