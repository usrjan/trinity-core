# Система вычислений (Calc) в Trinity Core

## 📋 Обзор

Система вычислений позволяет создавать **реактивные формулы** на основе данных из нейронов и синапсов. Используется тип нейрона `calc` для хранения логики вычислений и `Symfony Expression Language` для безопасного выполнения выражений.

---

## 🏗 Архитектура

### Компоненты

1. **Нейрон типа `calc`** — хранит формулу и настройки вычисления
2. **Синапсы** — связывают `calc` с нейронами-источниками данных
3. **CalcService** — сервис для вычисления формул
4. **Symfony Expression Language** — движок для безопасного выполнения выражений

---

## 🔧 Структура данных

### Нейрон типа `calc`

```json
{
  "id": 100,
  "type": "calc",
  "pid": 10,              // Родительская категория
  "text": 5,              // Ссылка на название "Итоговая сумма"
  "data": {
    "formula": "price * quantity * (1 - discount)",
    "target_neuron": 200, // Куда записать результат (опционально)
    "precision": 2        // Точность вычислений (опционально)
  }
}
```

### Синапсы для переменных

| parent | child | relation_type | data | time |
|--------|-------|---------------|------|------|
| 100 (calc) | 50 (Цена) | input | `{"variable_name": "price", "source_field": "value"}` | 2024-01-01 |
| 100 (calc) | 51 (Количество) | input | `{"variable_name": "quantity", "source_field": "amount"}` | 2024-01-01 |
| 100 (calc) | 52 (Скидка) | input | `{"variable_name": "discount", "source_field": "rate"}` | 2024-01-01 |

---

## 💡 Примеры использования

### Пример 1: Конвертация валют

**Нейроны:**
- ID 10: "USD" (нейрон валюты)
- ID 11: "EUR" (нейрон валюты)
- ID 12: "RUB" (нейрон валюты)
- ID 20: "Курс USD/RUB" (синапс с rate=92.5)
- ID 21: "Курс EUR/RUB" (синапс с rate=99.8)
- ID 30: "Конвертер USD→EUR" (тип `calc`)

**Формула в calc (ID 30):**
```
usd_rate / eur_rate * amount
```

**Синапсы:**
```php
$synapseRepo->setAttribute(30, 20, 92.5, 'input', null, [
    'variable_name' => 'usd_rate',
    'source_field' => 'rate'
]);

$synapseRepo->setAttribute(30, 21, 99.8, 'input', null, [
    'variable_name' => 'eur_rate',
    'source_field' => 'rate'
]);

// Добавляем сумму для конвертации
$synapseRepo->setAttribute(30, 10, 1000, 'input', null, [
    'variable_name' => 'amount',
    'source_field' => 'value'
]);
```

**Вычисление:**
```php
$calcService = $container->get(CalcService::class);
$result = $calcService->calculate(30); 
// Результат: 92.5 / 99.8 * 1000 = 926.85 EUR
```

---

### Пример 2: Расчет стоимости товара

**Нейроны:**
- ID 100: "Холодильник Samsung" (item)
- ID 101: "Цена" (атрибут)
- ID 102: "Количество" (атрибут)
- ID 103: "Скидка %" (атрибут)
- ID 104: "НДС" (атрибут)
- ID 105: "Итоговая сумма" (calc)

**Данные в синапсах:**
| Атрибут | Значение | variable_name |
|---------|----------|---------------|
| Цена | 50000 | price |
| Количество | 5 | quantity |
| Скидка | 0.1 | discount |
| НДС | 0.2 | vat |

**Формула:**
```
(price * quantity * (1 - discount)) * (1 + vat)
```

**Результат:** `(50000 * 5 * 0.9) * 1.2 = 270000`

---

### Пример 3: Динамический коэффициент

**Сценарий:** Цена зависит от региона и времени.

**Синапсы с историей:**
```php
// Цена на 01.01.2024
$synapseRepo->setAttribute($productId, $priceAttrId, 1000, 'price', '2024-01-01');

// Цена на 01.02.2024
$synapseRepo->setAttribute($productId, $priceAttrId, 1100, 'price', '2024-02-01');

// Получить цену на 15.01.2024
$price = $synapseRepo->getAttribute(
    $productId, 
    $priceAttrId, 
    'price', 
    '2024-01-15'
);
// Вернет 1000
```

---

## 📚 API CalcService

### Основные методы

#### `calculate(int $calcId): ?float`
Вычисляет значение формулы для нейрона типа `calc`.

```php
$result = $calcService->calculate(30);
if ($result !== null) {
    echo "Результат: $result";
}
```

#### `recalculateDependencies(int $neuronId): void`
Пересчитывает все калькуляции, зависящие от измененного нейрона.

```php
// При изменении цены товара
$neuronRepo->update($priceId, ['data' => ['value' => 1200]]);
$calcService->recalculateDependencies($priceId);
```

---

### Встроенные функции в формулах

| Функция | Описание | Пример |
|---------|----------|--------|
| `neuron(id)` | Получить значение нейрона | `neuron(50) * 2` |
| `synapse(parentId, type)` | Получить последнее значение синапса | `synapse(10, 'price')` |
| `round(value, precision)` | Округление | `round(total, 2)` |
| `max(a, b, ...)` | Максимальное значение | `max(price1, price2)` |
| `min(a, b, ...)` | Минимальное значение | `min(cost, budget)` |

---

## 🔐 Безопасность

- **Expression Language** выполняет только разрешенные операции
- Нет доступа к системным функциям PHP
- Ограничено математическими операциями и зарегистрированными функциями
- Логирование всех вычислений

---

## 📊 Интеграция с событиями

Автоматический пересчет при изменении данных:

```php
// В EventDispatcher или Observer
$eventDispatcher->addListener('neuron.updated', function($event) use ($calcService) {
    $calcService->recalculateDependencies($event->getNeuronId());
});
```

---

## 🎯 Сценарии использования

1. **Финансы**: Конвертация валют, расчет налогов, комиссий
2. **Торговля**: Расчет итоговой суммы заказа, скидок, наценок
3. **Производство**: Калькуляция себестоимости, норм расхода
4. **Строительство**: Расчет смет, объемов работ
5. **Отчетность**: Агрегация показателей, KPI

---

## ⚙️ Конфигурация

### composer.json
```json
{
  "require": {
    "symfony/expression-language": "^8.0"
  }
}
```

### container.php
```php
CalcService::class => \DI\autowire(),
```

---

## 📝 Примечания

1. **Производительность**: Для частых вычислений кэшируйте результаты в `data.last_result`
2. **Циклические зависимости**: Избегайте циклов в формулах (A зависит от B, B от A)
3. **Историчность**: Используйте поле `time` в синапсах для хранения истории значений
4. **Валидация**: Проверяйте формулы перед сохранением в БД

---

## 🔄 Миграция

При обновлении существующей системы:

1. Добавить тип `calc` в ENUM таблицы `neuron`
2. Установить `symfony/expression-language`
3. Создать нейроны типа `calc` для существующих расчетов
4. Перенести логику из PHP-кода в формулы
