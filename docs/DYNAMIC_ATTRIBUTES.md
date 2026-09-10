# Динамические атрибуты в Trinity Core

## 📋 Обзор

Динамические атрибуты позволяют добавлять произвольные свойства любым нейронам без изменения структуры базы данных. Реализованы через таблицу `synapse`, которая связывает нейрон с атрибутом и хранит значение с поддержкой историчности.

---

## 🏗 Архитектура

### Три таблицы

| Таблица | Назначение |
|---------|------------|
| `text` | Мультиязычные названия атрибутов |
| `neuron` | Сущности (товары, категории, атрибуты) |
| `synapse` | **Связи + значения + история** |

### Ключевая идея

- **Нейрон** (`type='item'`) — товар "Холодильник Samsung"
- **Нейрон** (`type='item'`) — атрибут "Цена"
- **Синапс** — связь товара с ценой: `parent=товар, child=атрибут, data.value=50000, time=2024-01-01`

---

## 💡 Возможности

### 1. Историчность значений

Один товар может иметь **множество значений одного атрибута** в разное время:

```sql
-- Цена на 01.01.2024
INSERT INTO synapse (parent, child, data, time) 
VALUES (100, 101, '{"relation":"price","value":50000}', '2024-01-01');

-- Цена на 01.02.2024
INSERT INTO synapse (parent, child, data, time) 
VALUES (100, 101, '{"relation":"price","value":52000}', '2024-02-01');

-- Цена на 01.03.2024
INSERT INTO synapse (parent, child, data, time) 
VALUES (100, 101, '{"relation":"price","value":48000}', '2024-03-01');
```

**Получить цену на конкретную дату:**
```php
$price = $synapseRepo->getAttribute(
    $productId, 
    $priceAttrId, 
    'price', 
    '2024-02-15'  // Вернет 52000
);
```

---

### 2. Мультивалютность и контексты

Один товар может иметь несколько цен в разных контекстах:

| parent | child | relation_type | data.value | time |
|--------|-------|---------------|------------|------|
| 100 (товар) | 101 (цена) | price_retail | 50000 | 2024-01-01 |
| 100 (товар) | 101 (цена) | price_wholesale | 45000 | 2024-01-01 |
| 100 (товар) | 101 (цена) | price_vip | 47000 | 2024-01-01 |

```php
// Розничная цена
$retailPrice = $synapseRepo->getAttribute($productId, $attrId, 'price_retail');

// Оптовая цена
$wholesalePrice = $synapseRepo->getAttribute($productId, $attrId, 'price_wholesale');
```

---

### 3. Конструктор товаров

Создание товаров с динамическими характеристиками:

**Нейроны-атрибуты:**
- ID 200: "Цвет"
- ID 201: "Размер"
- ID 202: "Мощность"
- ID 203: "Энергокласс"

**Синапсы для товара "Холодильник Samsung":**
```php
$synapseRepo->setAttribute($productId, 200, 'Белый', 'color');
$synapseRepo->setAttribute($productId, 201, '60x180', 'size');
$synapseRepo->setAttribute($productId, 202, 150, 'power', null, ['unit' => 'Вт']);
$synapseRepo->setAttribute($productId, 203, 'A++', 'energy_class');
```

**Получить все атрибуты:**
```php
$attributes = $synapseRepo->getAllAttributes($productId);
// ['200' => 'Белый', '201' => '60x180', '202' => 150, '203' => 'A++']
```

---

### 4. Курсы валют с историей

**Нейроны:**
- ID 10: "USD"
- ID 11: "EUR"
- ID 12: "RUB"

**Синапсы (курсы):**
```php
// USD/RUB на 01.01.2024
$synapseRepo->setAttribute(10, 12, 90.5, 'rate', '2024-01-01');

// USD/RUB на 02.01.2024
$synapseRepo->setAttribute(10, 12, 91.2, 'rate', '2024-01-02');

// EUR/RUB на 02.01.2024
$synapseRepo->setAttribute(11, 12, 98.7, 'rate', '2024-01-02');
```

**История изменения курса:**
```php
$history = $synapseRepo->getAttributeHistory(
    10,  // USD
    12,  // RUB
    'rate',
    '2024-01-01',
    '2024-01-31'
);

// Результат:
// [
//   ['time' => '2024-01-01', 'value' => 90.5],
//   ['time' => '2024-01-02', 'value' => 91.2],
//   ...
// ]
```

---

## 📚 API SynapseRepository

### `setAttribute()`

Установить значение атрибута.

```php
/**
 * @param int $neuronId ID нейрона (кому назначаем)
 * @param int $attributeId ID атрибута (что назначаем)
 * @param mixed $value Значение
 * @param string|null $relationType Тип связи ('price', 'rate', etc.)
 * @param DateTime|string|null $time Дата действия
 * @param array $extra Дополнительные данные
 */
$synapseRepo->setAttribute(
    $productId,
    $priceAttrId,
    50000,
    'price',
    '2024-01-01',
    ['currency' => 'RUB', 'source' => 'import']
);
```

---

### `getAttribute()`

Получить значение атрибута на дату.

```php
/**
 * @param int $neuronId ID нейрона
 * @param int $attributeId ID атрибута
 * @param string|null $relationType Тип связи
 * @param DateTime|string|null $asOfDate Дата (null = последнее)
 * @return mixed|null
 */
$price = $synapseRepo->getAttribute(
    $productId,
    $priceAttrId,
    'price',
    '2024-01-15'  // Цена на эту дату
);
```

---

### `getAttributeHistory()`

Получить историю изменения атрибута.

```php
/**
 * @param int $neuronId ID нейрона
 * @param int $attributeId ID атрибута
 * @param string|null $relationType Тип связи
 * @param DateTime|string|null $fromDate Начало периода
 * @param DateTime|string|null $toDate Конец периода
 * @return array [['time' => ..., 'value' => ...], ...]
 */
$history = $synapseRepo->getAttributeHistory(
    $productId,
    $priceAttrId,
    'price',
    '2024-01-01',
    '2024-01-31'
);
```

---

### `getAllAttributes()`

Получить все атрибуты нейрона.

```php
/**
 * @param int $neuronId ID нейрона
 * @param DateTime|string|null $asOfDate Дата (null = текущие)
 * @return array [attribute_id => value, ...]
 */
$attributes = $synapseRepo->getAllAttributes($productId);
```

---

## 🎯 Сценарии использования

### 1. Прайс-лист с историей

```php
// Импорт ежедневных цен
foreach ($products as $product) {
    foreach ($prices as $date => $price) {
        $synapseRepo->setAttribute(
            $product['id'],
            $priceAttrId,
            $price,
            'price',
            $date
        );
    }
}

// Отчет: изменение цены за месяц
$report = $synapseRepo->getAttributeHistory(
    $productId,
    $priceAttrId,
    'price',
    $startDate,
    $endDate
);
```

---

### 2. Мультирегиональные цены

```php
// Нейроны регионов
$moscow = 500;
$spb = 501;
$ekb = 502;

// Цены по регионам
$synapseRepo->setAttribute($productId, $priceAttrId, 50000, 'price_moscow');
$synapseRepo->setAttribute($productId, $priceAttrId, 48000, 'price_spb');
$synapseRepo->setAttribute($productId, $priceAttrId, 47000, 'price_ekb');

// Получение цены для региона
$price = $synapseRepo->getAttribute(
    $productId,
    $priceAttrId,
    'price_' . $regionCode
);
```

---

### 3. Роли и права доступа

```php
// Пользователь ID 10 имеет роль "Менеджер" с 01.01.2024
$synapseRepo->setAttribute(10, $managerRoleId, true, 'has_role', '2024-01-01');

// Проверка роли
$hasRole = $synapseRepo->getAttribute($userId, $roleId, 'has_role');
```

---

### 4. Статусы заказов

```php
// Заказ ID 100
$synapseRepo->setAttribute(100, $statusAttrId, 'new', 'status', '2024-01-01 10:00');
$synapseRepo->setAttribute(100, $statusAttrId, 'processing', 'status', '2024-01-01 12:00');
$synapseRepo->setAttribute(100, $statusAttrId, 'shipped', 'status', '2024-01-02 09:00');
$synapseRepo->setAttribute(100, $statusAttrId, 'delivered', 'status', '2024-01-03 15:00');

// Текущий статус
$currentStatus = $synapseRepo->getAttribute(100, $statusAttrId, 'status');
// delivered

// История статусов
$statusHistory = $synapseRepo->getAttributeHistory(100, $statusAttrId, 'status');
```

---

## 🔐 Преимущества

| Преимущество | Описание |
|--------------|----------|
| **Гибкость** | Добавление атрибутов без изменения БД |
| **Историчность** | Хранение всех изменений с датами |
| **Мультиконтекстность** | Разные значения для разных типов связей |
| **Единый API** | Одинаковый интерфейс для всех атрибутов |
| **Производительность** | Индексы по `parent`, `child`, `time`, `relation_type` |

---

## ⚙️ Рекомендации

### 1. Именование атрибутов

```php
// Хорошо
'relation' => 'price_retail'
'relation' => 'price_wholesale'
'relation' => 'rate_usd_rub'

// Плохо (слишком общие)
'relation' => 'data1'
'relation' => 'temp'
```

---

### 2. Использование `time`

Всегда указывайте `time` для атрибутов, которые могут изменяться:
- Цены
- Курсы валют
- Статусы
- Остатки на складе

---

### 3. Дополнительные данные в `data`

Используйте поле `$extra` для метаданных:

```php
$synapseRepo->setAttribute(
    $productId,
    $priceAttrId,
    50000,
    'price',
    '2024-01-01',
    [
        'currency' => 'RUB',
        'source' => 'import_excel',
        'imported_by' => $userId,
        'batch_id' => '2024-01-01-batch-1'
    ]
);
```

---

### 4. Индексы

Для производительности убедитесь, что есть индексы:

```sql
INDEX `idx_parent_child_relation` (`parent`, `child`, `relation_type`)
INDEX `idx_parent_time` (`parent`, `time`)
INDEX `idx_relation_time` (`relation_type`, `time`)
```

---

## 🔄 Миграция с традиционных таблиц

### Было (традиционный подход):

```sql
CREATE TABLE products (id, name, price, created_at);
CREATE TABLE prices_history (id, product_id, price, date);
CREATE TABLE product_attributes (id, product_id, color, size, power);
```

### Стало (Trinity Core):

```sql
-- Товар
INSERT INTO neuron (type, text, data) VALUES ('item', 10, '{"slug":"samsung-fridge"}');

-- Атрибуты (создаются один раз)
INSERT INTO neuron (type, text) VALUES ('item', 20); -- "Цена"
INSERT INTO neuron (type, text) VALUES ('item', 21); -- "Цвет"

-- Значения (через синапсы)
INSERT INTO synapse (parent, child, data, time) 
VALUES ($productId, $priceAttrId, '{"relation":"price","value":50000}', NOW());
```

---

## 📊 Производительность

### Запросы

| Операция | Сложность | Индексы |
|----------|-----------|---------|
| Получить последнее значение | O(log N) | `parent`, `child`, `time` |
| Получить историю за период | O(M log N) | `parent`, `time` |
| Получить все атрибуты | O(K log N) | `parent` |

Где:
- N = количество синапсов
- M = количество записей в периоде
- K = количество атрибутов

### Оптимизация

1. **Кэширование**: Кэшируйте результаты `getAllAttributes()` для частых запросов
2. **Партиционирование**: Для больших объемов рассмотрите партиционирование по `time`
3. **Агрегация**: Для отчетов используйте материализованные представления

---

## 📝 Пример полного цикла

```php
// 1. Создаем атрибуты (один раз)
$priceAttrId = $neuronRepo->create([
    'type' => 'item',
    'text' => $textKeyForPrice
]);

$colorAttrId = $neuronRepo->create([
    'type' => 'item',
    'text' => $textKeyForColor
]);

// 2. Создаем товар
$productId = $neuronRepo->create([
    'type' => 'item',
    'pid' => $categoryId,
    'tree' => $brandId,
    'text' => $textKeyForProduct
]);

// 3. Устанавливаем атрибуты
$synapseRepo->setAttribute($productId, $priceAttrId, 50000, 'price', '2024-01-01');
$synapseRepo->setAttribute($productId, $colorAttrId, 'Белый', 'color');

// 4. Обновляем цену (старая сохраняется в истории)
$synapseRepo->setAttribute($productId, $priceAttrId, 52000, 'price', '2024-02-01');

// 5. Получаем текущую цену
$currentPrice = $synapseRepo->getAttribute($productId, $priceAttrId, 'price');
// 52000

// 6. Получаем цену на дату
$oldPrice = $synapseRepo->getAttribute($productId, $priceAttrId, 'price', '2024-01-15');
// 50000

// 7. Получаем историю
$history = $synapseRepo->getAttributeHistory($productId, $priceAttrId, 'price');
// [['time'=>'2024-01-01','value'=>50000], ['time'=>'2024-02-01','value'=>52000]]
```

---

## ✅ Заключение

Динамические атрибуты через синапсы обеспечивают:

- ✅ Гибкую модель данных без миграций БД
- ✅ Встроенную историчность
- ✅ Поддержку мультиконтекстных значений
- ✅ Единый API для всех типов атрибутов
- ✅ Высокую производительность при правильной индексации

Это фундамент для построения сложных систем: каталогов товаров, финансовых платформ, CRM, ERP.
