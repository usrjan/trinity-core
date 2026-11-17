<?php
declare(strict_types=1);

/**
 * CSRF-СЕРВИС
 *
 * [Ревью]: Этот класс отсутствовал в репозитории. Он импортировался
 * в 6 контроллерах и регистрировался в config/container.php
 * (CsrfService::class => \DI\autowire()), но файла не было —
 * любой запрос, задевающий CSRF, падал с «Class not found».
 *
 * Реализация — поверх той же сессии, что использует GuardService:
 * один ключ 'csrf_token', один источник правды. Без $_SESSION.
 *
 * [Мириам]: И — это правильно. Потому что защита должна быть
 * в одном месте, а не в пяти.
 *
 * @package Jan\Trinity\Core\Service
 */

namespace Jan\Trinity\Core\Service;

use Symfony\Component\HttpFoundation\Session\Session;

class CsrfService
{
        private Session $session;

        public function __construct(Session $session)
        {
                $this->session = $session;
        }

        /**
         * Возвращает существующий токен или создаёт новый.
         *
         * [Лорелея]: Вызывается из Twig-функции csrf_token() при
         * рендере каждой формы. Токен живёт до конца сессии.
         */
        public function getOrCreateToken(): string
        {
                if (!$this->session->isStarted()) {
                        $this->session->start();
                }

                $token = $this->session->get('csrf_token');
                if (is_string($token) && $token !== '') {
                        return $token;
                }

                return $this->generateToken();
        }

        /**
         * Принудительно генерирует новый токен (перезаписывает старый).
         *
         * [Мириам]: Вызывать после успешного логина — вместе с
         * session->migrate(true). Новая сессия — новый токен.
         */
        public function generateToken(): string
        {
                $token = bin2hex(random_bytes(32));

                if (!$this->session->isStarted()) {
                        $this->session->start();
                }

                $this->session->set('csrf_token', $token);

                return $token;
        }

        /**
         * Проверяет токен. Сравнение — только hash_equals.
         *
         * [Лорелея]: Пустой или отсутствующий токен — всегда false.
         * Никаких «может быть».
         */
        public function validateToken(?string $token): bool
        {
                if ($token === null || $token === '') {
                        return false;
                }

                if (!$this->session->isStarted()) {
                        $this->session->start();
                }

                $storedToken = $this->session->get('csrf_token');

                if (!is_string($storedToken)) {
                        return false;
                }

                return hash_equals($storedToken, $token);
        }
}
