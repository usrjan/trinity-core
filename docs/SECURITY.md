# Безопасность Trinity

## CSRF-защита

Все POST/PUT/DELETE-запросы защищены токеном.

- Токен генерируется в `GuardController::generateCsrfToken()`
- Хранится в сессии и cookie `csrf_token`
- Проверяется через `GuardController::validateCsrfToken()`
- В JS автоматически подставляется через обёртку `fetch` в `spa.js`

## Brute force

Ограничение попыток входа:

- По IP: 10 попыток за 5 минут
- По логину: 5 попыток за 5 минут
- При 15 попытках с одного IP — блокировка на 1 час

Файлы попыток: `var/guard/{md5}.attempts`  
Заблокированные IP: `var/guard/blocked_ips.json`

## Пароли

- Хеширование: bcrypt, cost = 12
- Проверка: `password_verify()`
- Минимальная длина: 8 символов

## Роли

- `role_admin` — полный доступ
- `role_user` — ограниченный
- Проверка через `AuthMiddleware`

## Логирование

- Ошибки PHP: `var/log/error.log`
- Действия: `var/log/app.log`
- JS-ошибки: `var/log/js-error.log`
- Безопасность: `var/log/security.log`

## Рекомендации

- В production отключить `APP_DEBUG`
- Использовать HTTPS
- Хранить секреты в `.env`, не в репозитории
- Регулярно чистить `var/guard`