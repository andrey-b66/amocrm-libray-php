# Модуль amoCRM

Работа с amoCRM API v4 через долгосрочный токен. Репозитории принимают и
возвращают массивы формата API v4, подробности и примеры — в докблоках самих
методов.

## Установка

PHP 7.4 или 8.x с расширениями `curl` и `json`. Других зависимостей нет.

```bash
composer require integrat/amocrm
```

```php
require __DIR__ . '/vendor/autoload.php';

use Amocrm\Facade\Amocrm;

$amocrm = new Amocrm('example.amocrm.ru', $longLivedToken);
```

## Поиск

Запрос пишется обычной строкой — той же, что в адресной строке браузера. Можно
вставить и целиком URL: всё до `?` отбрасывается.

```php
// Одна страница: limit задаётся аргументом, а не строкой.
$leads = $amocrm->leads()->find('filter[pipeline_id][0]=10739150&filter[status_id][0]=143', 50);

// Все страницы по 250 обходятся сами: третий аргумент null — читать до конца выборки.
$leads = $amocrm->leads()->find('filter[created_at][from]=1753747200&with=contacts', 250, null);

// Ограниченный обход: три страницы подряд, дальше не идём. Результат — один
// плоский список сделок, а не разбитый по страницам.
$leads = $amocrm->leads()->find('filter[status_id][0]=143&order[id]=asc', 250, 3);

$contact = $amocrm->contacts()->findById($contactId, 'leads,companies');
$contacts = $amocrm->contacts()->findByIds([10, 20, 30]);
$contacts = $amocrm->contacts()->findByPhone('+7 (999) 000-00-00');
$contacts = $amocrm->contacts()->findByQuery('Ромашка');
$contacts = $amocrm->contacts()->findByField(123456, 'ООО Ромашка');

$leads = $amocrm->leads()->findActiveByContactId($contactId);
$pipelines = $amocrm->pipelines()->find('', 250, null);
$users = $amocrm->users()->getActive();
```

## Создание и обновление

```php
// create() принимает список сущностей — как и сама amoCRM. Одна запись это
// список из одной; в ответ приходят созданные сущности с проставленными id.
[$contact] = $amocrm->contacts()->create([['name' => 'Иван']]);
$amocrm->contacts()->update([['id' => $contact['id'], 'name' => 'Пётр']]);

// Пачкой — до 250 в одном запросе, список любой длины бьётся сам. Своё поле
// request_id amoCRM вернёт обратно: по нему видно, какой id какой записи.
$created = $amocrm->contacts()->create([
    ['name' => 'Иван', 'request_id' => '42'],
    ['name' => 'Пётр', 'request_id' => '43'],
]);

// update() устроен так же: у каждой сущности свой id внутри данных, остальные
// поля частичные — меняется только перечисленное.
$updated = $amocrm->leads()->update([
    ['id' => 10, 'price' => 1000],
    ['id' => 20, 'name' => 'Повторная заявка'],
]);

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

[$product] = $products->create([['name' => 'Стул', 'custom_fields_values' => [
    ['field_id' => $priceFieldId, 'values' => [['value' => 1500]]],
]]]);
$products->update([['id' => $product['id'], 'name' => 'Стул офисный']]);

$products->findByQuery('Стул');
$products->findById($product['id']);
$products->find('', 250, null);

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

Количество может быть дробным. Цену сделки amoCRM пересчитывает не сразу, а
примерно через секунду после привязки.

Товары сразу многих сделок берут не запросом на каждую сделку, а через
`with=catalog_elements`: ID товаров и `metadata` с количеством придут прямо в
сделках, в `_embedded.catalog_elements`.

```php
$leads = $amocrm->leads()->find('with=catalog_elements&order[id]=desc', 50, 4);
```

Так же работает любой другой список — счета и пользовательские справочники.

```php
$catalogs = $amocrm->catalogs();

$all = $catalogs->find('', 250, null);
$regular = $catalogs->findByType('regular'); // ещё бывают products, invoices, suppliers

$elements = $catalogs->elements($catalogId);
$elements->create([['name' => 'Строка справочника']]);
$elements->linkToLead($leadId, $elementId);
```

Поиска по значению поля у элементов списков нет: `findByField()` бросает
исключение, вместо него используйте `findByQuery()`.

Удаления товаров, элементов и сделок в API v4 нет — на `DELETE` amoCRM отвечает
405, чистить приходится в интерфейсе.

## Произвольные запросы

```php
$events = $amocrm->raw()->get('api/v4/events', 'filter[entity][0]=lead&limit=50');
$amocrm->raw()->patch('api/v4/leads/' . $leadId, ['price' => 1000]);
```

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

Повторных попыток нет: HTTP 429, 5xx и обрыв связи сразу приходят как
`ApiException`. Повторять ли запрос, решает вызывающий код — запись после обрыва
могла уже дойти до amoCRM, и повтор завёл бы дубль.
