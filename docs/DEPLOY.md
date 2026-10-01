# Деплой Trinity

## Окружение

| Компонент  | Версия          |
|------------|-----------------|
| Хост       | Hyper-V         |
| ОС         | FreeBSD 14.3-RELEASE |
| PHP        | 8.4.19          |
| MySQL      | 8.0.46          |
| Nginx      | 1.30.2,3        |
| Samba      | 4.19.9_12       |
| Сокет PHP-FPM | /tmp/php-fpm.sock |

## Шаги

1. Клонировать репозиторий в `/home/web/www`
2. `composer install --no-dev`
3. Скопировать `.env.example` в `.env`
4. Заполнить параметры
5. Импортировать `seed.sql`
6. Настроить nginx (см. ниже)
7. Убедиться, что `var/` доступна для записи
8. Отключить `APP_DEBUG`

## Конфигурация Nginx

```nginx
events {
    worker_connections  1024;
}

http {
    include       mime.types;
    default_type  application/octet-stream;
    client_max_body_size 128M;

    sendfile        on;
    keepalive_timeout  65;

    server {
        listen       80;
        server_name  localhost;

        root /home/web/www;
        index index.php;

        location / {
            try_files $uri $uri/ /index.php?$args;
        }

        error_page   500 502 503 504  /50x.html;
        location = /50x.html {
            root   /usr/local/www/nginx-dist;
        }

        location ~ \.php$ {
            fastcgi_pass   unix:/tmp/php-fpm.sock;
            fastcgi_index  index.php;
            try_files $uri =404;

            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param PATH_INFO $fastcgi_path_info;
            fastcgi_send_timeout 3600;
            fastcgi_read_timeout 3600;
            include        fastcgi_params;
        }

        location ~ /\. {
            deny all;
        }

        proxy_connect_timeout 3600;
        proxy_send_timeout 3600;
        proxy_read_timeout 3600;
        send_timeout 3600;
    }
}
```

## Замечания

- FreeBSD работает на Hyper-V
- Для сети между хостом и виртуалкой — Internal Switch или Bridge
- Samba — для доступа к файлам с хостовой машины
- Рекомендуется ZFS для снапшотов и надёжности
- client_max_body_size 128M — для загрузки Excel и изображений
- Таймауты 3600 — для длительных отчётов
- location ~ /\. — запрет доступа к скрытым файлам
- Сокет /tmp/php-fpm.sock — быстрее TCP

## Продакшн

APP_ENV=prod
APP_DEBUG=false
CACHE_ENABLED=true
Права на var/: chmod -R 775 var/
Секреты — только в .env, не в git

## Samba

Доступ к папке разработчика `/home/web/` с хостовой машины.

```ini
[global]
unix charset = UTF-8
workgroup = WORKGROUP
server string = Samba Server
interfaces = 127.0.0.0/8 10.250.11.0/24
bind interfaces only = yes
map to guest = bad user

[WEB]
comment = Web Developer Folder
path = /home/web/
force user = web
public = yes
writable = yes
read only = no
create mask = 0777
directory mask = 0777
```

## Что важно
interfaces — только localhost и внутренняя сеть 10.250.11.0/24
bind interfaces only = yes — не слушаем внешние
force user = web — все файлы от веб-разработчика
Маски 0777 — для удобства разработки
Доступ гостевой — для простоты в изолированной сети

## Безопасность
Внешний доступ закрыт: только 127.0.0.1 и 10.250.11.0/24
Пароль не требуется — сеть доверенная
Не использовать маски 0777 в production