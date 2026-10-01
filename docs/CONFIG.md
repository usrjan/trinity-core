# Конфигурация Trinity

## .env

```env
APP_ENV=dev
APP_DEBUG=true

DB_HOST=localhost
DB_PORT=3306
DB_NAME=trinity_core
DB_USER=webdev
DB_PASSWORD=secret

CACHE_ENABLED=false
SESSION_LIFETIME=86400

YANDEX_MAPS_API_KEY=your-key
```

# Конфиги в базе

Нейроны type='config':

Ключ	Значение	Описание
app.debug	true	Режим отладки
app.name	Trinity	Название
app.version	1.2.3	Версия
cache.enabled	false	Кэш
monitor.enabled	true	Монитор
guard.enabled	true	Страж

Доступ: $kernel->getConfig('app.debug')
Через репозиторий: $neuronRepo->findConfigValue('app.debug')