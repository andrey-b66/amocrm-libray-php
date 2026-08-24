# Модуль amoCRM

Работа с amoCRM API v4 через долгосрочный токен. HTTP на Guzzle: параллельные
запросы и повторы при временных ошибках достаются оттуда и настройки не
требуют. Репозитории принимают и возвращают массивы формата API v4,
подробности и примеры — в докблоках самих методов.

## Установка

PHP 8.1+ с расширениями `curl` и `json`.

```bash
composer require integrat/amocrm
```

```php
require __DIR__ . '/vendor/autoload.php';

use Amocrm\Facade\Amocrm;

$amocrm = new Amocrm('example.amocrm.ru', $longLivedToken);
```

Для переноса в другой проект: `"autoload": { "psr-4": { "Amocrm\\": "путь/до/Amocrm/" } }`.

## Поиск

Запрос пишется обычной строкой — той же, что в адресной строке браузера. Можно
вставить и целиком URL: всё до `?` отбрасывается.

```php
// Одна страница: page и limit задаются аргументами, а не строкой.
$leads = $amocrm->leads()->find('filter[pipeline_id][0]=10739150&filter[status_id][0]=143', 1, 50);

// Все страницы по 250 обходятся сами.
$leads = $amocrm->leads()->findAll('filter[created_at][from]=1753747200&with=contacts');

// Несколько страниц разом: страниц можно передать сколько угодно, в полёте
// держится семь — лимит amoCRM. Ответ — «страница => сделки», пустая страница
// означает, что данные кончились.
$leadsByPage = $amocrm->leads()->findPages('filter[status_id][0]=143&order[id]=asc', [1, 2, 3]);

$contact = $amocrm->contacts()->findById($contactId, 'leads,companies');
$contacts = $amocrm->contacts()->findByIds([10, 20, 30]);
$contacts = $amocrm->contacts()->findByPhone('+7 (999) 000-00-00');
$contacts = $amocrm->contacts()->findByQuery('Ромашка');
$contacts = $amocrm->contacts()->findByField(123456, 'ООО Ромашка');

$leads = $amocrm->leads()->findActiveByContactId($contactId);
$pipelines = $amocrm->pipelines()->findAll();
$users = $amocrm->users()->getActive();
```

## Создание и обновление

```php
$contact = $amocrm->contacts()->create(['name' => 'Иван']);
$amocrm->contacts()->update($contact['id'], ['name' => 'Пётр']);

$amocrm->notes()->createCommon('leads', $leadId, 'Клиент просил перезвонить');
$notes = $amocrm->notes()->findForEntity('leads', $leadId, 'filter[note_type][0]=common');

$amocrm->tasks()->createForEntity('leads', $leadId, [
    'text' => 'Перезвонить клиенту',
    'complete_till' => time() + 3600,
]);
$tasks = $amocrm->tasks()->findForEntity('leads', $leadId, 'filter[is_completed]=0');

$amocrm->calls()->createIncoming([
    'phone' => '+79990000000', // по номеру amoCRM сама находит сущность
    'uniq' => 'call-2026-0001',
    'source' => 'my-telephony',
    'duration' => 125,
]);
```

Первым аргументом методы примечаний, задач, тегов и связей принимают `leads`,
`contacts` или `companies`.

## Связи и теги

```php
$links = $amocrm->links();

$links->linkContactToLead($contactId, $leadId, true); // true — главный контакт
$links->unlinkContactFromLead($contactId, $leadId);

$contacts = $links->findContactsForLead($leadId);
$mainContact = $links->findMainContactForLead($leadId);
$company = $links->findCompanyForLead($leadId);

$tag = $amocrm->tags()->create('leads', ['name' => 'Важная заявка']);
$amocrm->tags()->addToEntity('leads', $leadId, [$tag['id'], 'Повторный клиент']);
$amocrm->tags()->removeFromEntity('leads', $leadId, [$tag['id']]);
$amocrm->tags()->clearForEntity('leads', $leadId);
```

## Товары и списки

Товары — элементы служебного списка с типом `products`, репозиторий находит его
сам. Цена, артикул и остаток лежат в `custom_fields_values` обычными полями.

```php
$products = $amocrm->catalogs()->products();

$product = $products->create(['name' => 'Стул', 'custom_fields_values' => [
    ['field_id' => $priceFieldId, 'values' => [['value' => 1500]]],
]]);
$products->update($product['id'], ['name' => 'Стул офисный']);

$products->findByQuery('Стул');
$products->findById($product['id']);
$products->findAll();

// ID полей товара — цены, артикула, остатка.
$fields = $amocrm->raw()->get('api/v4/catalogs/' . $products->catalogId() . '/custom_fields');
```

Товар привязывается к сделке с количеством; цену сделки amoCRM пересчитывает
сама. Второй раз тот же товар в сделке не появится: повторная привязка
перезаписывает количество, ей же его и меняют.

```php
$products->linkToLead($leadId, $product['id'], 2);
$products->linkToLead($leadId, $product['id'], 5); // теперь в сделке 5 штук
$products->unlinkFromLead($leadId, $product['id']);

$leadProducts = $products->findForLead($leadId);   // сами товары
$links = $products->findLinksForLead($leadId);     // связи, количество в metadata
```

Количество бывает дробным — 2.5 килограмма amoCRM примет. Обратно оно приходит
дробным всегда: в `metadata` лежит `quantity` = 2.0, а не 2.

Цену сделки amoCRM пересчитывает не сразу: сразу после привязки она ещё старая,
актуальная приходит примерно через секунду.

Страницы товаров и сделок читаются параллельно — `findPages()` отправляет
запросы разом, держа в полёте семь.

```php
$productsByPage = $products->findPages('order[id]=asc', [1, 2, 3]);
```

Товары сразу многих сделок берут не запросом на каждую сделку, а через
`with=catalog_elements`: ID товаров и `metadata` с количеством придут прямо в
сделках, в `_embedded.catalog_elements`.

```php
$leadsByPage = $amocrm->leads()->findPages('with=catalog_elements&order[id]=desc', [1, 2, 3, 4], 50);
```

Так же работает любой другой список — счета и пользовательские справочники.

```php
$catalogs = $amocrm->catalogs();

$all = $catalogs->findAll();
$regular = $catalogs->findByType('regular'); // ещё бывают products, invoices, suppliers

$elements = $catalogs->elements($catalogId);
$elements->create(['name' => 'Строка справочника']);
$elements->linkToLead($leadId, $elementId);
```

По элементам списков amoCRM ищет только по ID и полнотекстовым `query`. Фильтр
по значению поля она не выполняет и не отклоняет — молча отдаёт весь список,
поэтому `findByField()` у элементов бросает исключение, а не возвращает мусор.
У самих списков поиска нет вовсе: нужный берут из `findAll()` или `findByType()`.

Удаления товаров, элементов и сделок в API v4 нет — на `DELETE` amoCRM отвечает
405, чистить приходится в интерфейсе.

## Произвольные запросы

```php
$events = $amocrm->raw()->get('api/v4/events', 'filter[entity][0]=lead&limit=50');
$amocrm->raw()->delete('api/v4/leads/notes/' . $noteId);
```

## Параллельные запросы

Запросов передают сколько нужно — в полёте держится семь, это лимит amoCRM на
аккаунт. Освободившееся место сразу занимает следующий запрос очереди, так что
ждать всю семёрку ради восьмого не приходится.

```php
$raw = $amocrm->raw();

// Один эндпоинт, разные параметры.
$pages = $raw->getMany('api/v4/leads', ['page=1&limit=250', 'page=2&limit=250']);

// Разные эндпоинты и любые методы: postMany, patchMany, putMany, deleteMany.
$results = $raw->postMany([
    10 => ['endpoint' => 'api/v4/leads/10/link', 'data' => [$link]],
    20 => ['endpoint' => 'api/v4/leads/20/link', 'data' => [$link]],
]);
```

Ошибка одного запроса не срывает остальные и не приходит исключением: под своим
ключом вместо ответа лежит `ApiException`, поэтому видно, что записалось, а что
нет.

```php
foreach ($results as $key => $result) {
    if ($result instanceof ApiException) {
        continue; // этот не прошёл, остальные прошли
    }
}
```

## Повторные попытки

Запрос, упавший по временной причине, повторяется сам — до шести раз, с паузой,
которая удваивается: 1, 2, 4, 8, 16, 32 секунды. Настраивать нечего и включать
тоже: превышенный лимит запросов amoCRM держит не мгновение, и ждать её всё
равно приходится.

Что считается временным, зависит от метода:

| Ошибка | GET | POST, PATCH, PUT, DELETE |
|---|---|---|
| HTTP 429, превышен лимит | повторяется | повторяется |
| Обрыв связи, таймаут | повторяется | нет |
| HTTP 5xx | повторяется | нет |
| HTTP 400, 401, 403, 404 | нет | нет |

Запись после обрыва связи не повторяется потому, что по такой ошибке не видно,
дошла она или нет: ответ мог потеряться уже после того, как amoCRM всё создала,
и повтор завёл бы вторую копию. HTTP 429 — случай понятный: запрос отклонён
целиком, записать ничего не успели, повторять безопасно.

В пачке повтор идёт на уровне отдельного запроса: соседей он не задерживает и
их ответы не отменяет. Когда дубли не страшны или запрос идемпотентен, запись
переотправляют сами — из ответа `-Many` видно, что именно не прошло.

## Ошибки

```php
use Amocrm\Exception\ApiException;

try {
    $lead = $amocrm->leads()->findById($leadId);
} catch (ApiException $exception) {
    $exception->getStatusCode();
    $exception->getResponseData();
    $exception->getValidationErrors();
}
```

Данные перед отправкой не проверяются — правила знает amoCRM и отвечает HTTP 400
с текстом в `detail`. Ответ на схему тоже не проверяется: если ожидаемого набора
нет, вернётся пустой массив, а не исключение. HTTP 204 ошибкой не считается:
списки отдают `[]`, `findById()` — `null`.
