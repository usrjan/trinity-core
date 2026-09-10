# База данных Trinity

## Таблицы

### neuron

Сущность. Хранит всё: пользователей, страницы, меню, роуты, конфиги, детали, проекты.

| Поле | Тип | Описание |
|------|-----|----------|
| id | INT | Первичный ключ |
| pid | INT | Родительский нейрон |
| type | ENUM | tree, item, file, user, config, route, template, command, project, construction, detail |
| tree | INT | Привязка к дереву |
| text | INT | Ссылка на text.key |
| data | JSON | Все данные нейрона |
| date | DATETIME | Дата создания |
| slug | VARCHAR | Из data.slug |
| route | VARCHAR | Из data.route |
| login | VARCHAR | Из data.login |
| email | VARCHAR | Из data.email |
| sort | INT | Из data.sort |
| is_deleted | TINYINT | Из data.deleted_at |
| hash | VARCHAR | SHA2(pid+type+data) |

### synapse

Связь между нейронами.

| Поле | Тип | Описание |
|------|-----|----------|
| id | INT | Первичный ключ |
| tree | INT | Привязка к дереву |
| parent | INT | Родительский нейрон |
| child | INT | Дочерний нейрон |
| text_key | INT | Ключ текста |
| text_id | INT | ID текста |
| data | JSON | Данные связи |
| time | DATETIME | Время |
| relation_type | VARCHAR | Из data.relation |
| hash | VARCHAR | SHA2(parent+child+data) |

### text

Мультиязычные тексты.

| Поле | Тип | Описание |
|------|-----|----------|
| id | INT | Первичный ключ |
| key | INT | Общий ключ для переводов |
| group | INT | Группа |
| lang | ENUM | ru, en |
| name | VARCHAR | Название |
| text | TEXT | Содержимое |
| is_active | TINYINT | Активен ли |

## Соглашения

- Мягкое удаление: `data.deleted_at` → `is_deleted = 1`
- Сортировка: `data.sort`, шаг 100
- Роуты в базе: `type='route'`, сортируются по `sort`
- Конфиги: `type='config'`, ключ в `data.key`, значение в `data.value`
- Пользователи: `type='user'`, логин в `data.login`, пароль в `data.password_hash`