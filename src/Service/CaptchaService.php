<?php

declare(strict_types=1);

namespace Jan\Trinity\Core\Service;

class CaptchaService
{
	/**
	 * Генерирует простую арифметическую капчу.
	 */
	public function generate(): array
	{
		$a = random_int(1, 9);
		$b = random_int(1, 9);

		return [
			'question' => "Сколько будет {$a} + {$b}?",
			'expected' => (string) ($a + $b),
		];
	}

	/**
	 * Проверяет ответ на капчу.
	 */
	public function validate(string $answer, string $expected): bool
	{
		return hash_equals($expected, $answer);
	}
}