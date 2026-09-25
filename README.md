# Модуль amoCRM

Работа с amoCRM API v4 по долгосрочному токену. Репозитории принимают и
возвращают массивы формата API v4; подробности и примеры — в докблоках методов.

## Установка

PHP 7.4 или 8.x с расширениями `curl` и `json`. Других зависимостей нет.

```bash
composer require integrat/amocrm
```

```php
use Amocrm\Facade\Amocrm;

$amocrm = new Amocrm('example.amocrm.ru', $longLivedToken);
```

Долгосрочный токен выпускается в настройках интеграции amoCRM на срок от 1 дня
до 5 лет.

## Репозитории

| Метод фасада                | Методы                                                                                                     |
|-----------------------------|------------------------------------------------------------------------------------------------------------|
| `leads()`                   | `find`, `findById`, `findByIds`, `findByQuery`, `findByPhone`, `findByField`, `findActiveByIds`, `create`, `update` |
| `contacts()`, `companies()` | `find`, `findById`, `findByIds`, `findByQuery`, `findByPhone`, `findByField`, `create`, `update`          |
| `tasks()`                   | `find`, `findById`, `findByIds`, `findForEntity`, `create`, `createForEntity`, `update`                   |
| `notes()`                   | `findForEntity`, `findById`, `create`, `createCommon`, `update`                                           |
| `tags()`                    | `find`, `create`, `addToEntity`, `removeFromEntity`, `clearForEntity`                                     |
| `links()`                   | `linkContactToLead`, `linkCompanyToLead`, `linkContactToCompany`, `unlink…`, `findLinkedIds`, `findMainContactId` |
| `calls()`                   | `create`, `createIncoming`, `createOutgoing`                                                              |
| `pipelines()`               | `findAll`, `findById`                                                                                     |
| `users()`                   | `findAll`, `findActive`, `findDeactivated`, `findById`                                                    |
| `raw()`                     | `get`, `post`, `patch`, `lastRequestId`                                                                   |

Методы примечаний, задач, тегов и связей первым аргументом принимают тип
сущности: `leads`, `contacts` или `companies`.

## Чтение

```php
// Запрос — строка параметров API; размер страницы и число страниц — аргументы.
$leads = $amocrm->leads()->find('filter[pipeline_id][0]=10739150', 50);                   // одна страница
$leads = $amocrm->leads()->find('filter[pipeline_id][0]=10739150&order[id]=asc', 250, 3); // три страницы
$leads = $amocrm->leads()->find('filter[created_at][from]=1753747200', 250, null);        // все страницы

$contact = $amocrm->contacts()->findById($contactId, 'leads,companies');
$contacts = $amocrm->contacts()->findByIds([10, 20, 30]);
$contacts = $amocrm->contacts()->findByQuery('Ромашка');
$contacts = $amocrm->contacts()->findByPhone('+7 (999) 000-00-00');
$contacts = $amocrm->contacts()->findByField(123456, 'ООО Ромашка');

$pipelines = $amocrm->pipelines()->findAll();
$users = $amocrm->users()->findActive('role,group');
```

- Незнакомый фильтр amoCRM не отклоняет, а отдаёт всю выборку. Статус сделки
  фильтруется парой с воронкой: `filter[statuses][0][pipeline_id]=…&filter[statuses][0][status_id]=…`.
- `findByField()` работает, если в аккаунте подключена API-фильтрация.
- Если сущности нет, `findById()` возвращает `null`, а списки — `[]`.

## Запись

```php
[$contact] = $amocrm->contacts()->create([['name' => 'Иван', 'request_id' => '42']]);
$amocrm->leads()->update([['id' => 10, 'price' => 1000], ['id' => 20, 'name' => 'Повторная заявка']]);

$amocrm->notes()->createCommon('leads', $leadId, 'Клиент просил перезвонить');
$amocrm->tasks()->createForEntity('leads', $leadId, ['text' => 'Перезвонить', 'complete_till' => time() + 3600]);
$amocrm->calls()->createIncoming(['phone' => '+79990000000', 'duration' => 125, 'source' => 'my-telephony']);
```

- `create()` и `update()` принимают список и возвращают список, длинные списки
  уходят порциями по 250. Своё поле `request_id` возвращается рядом с `id`.
- Если ошибка случилась в середине списка, прошлые порции уже записаны.
- Звонок на номер, которого нет в базе, amoCRM не добавляет, и приходит `ApiException`.
- Удалять сделки, контакты и компании API v4 не позволяет.

## Связи и теги

```php
$links = $amocrm->links();
$links->linkContactToLead($contactId, $leadId, true); // true — главный контакт
$links->unlinkContactFromLead($contactId, $leadId);

$contactIds = $links->findLinkedIds('leads', $leadId, 'contacts');
$mainContactId = $links->findMainContactId($leadId);
$activeLeads = $amocrm->leads()->findActiveByIds($links->findLinkedIds('contacts', $contactId, 'leads'));

[$tag] = $amocrm->tags()->create('leads', [['name' => 'Важная заявка']]);
$amocrm->tags()->addToEntity('leads', $leadId, [$tag['id'], 'Повторный клиент']);
$amocrm->tags()->clearForEntity('leads', $leadId);
```

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
    $exception->getStatusCode();       // HTTP-статус; 0 — запрос до amoCRM не дошёл
    $exception->getResponseData();     // тело ответа amoCRM
    $exception->getValidationErrors(); // ошибки валидации из ответа
    $exception->getRequestId();        // X-Request-Id для поддержки amoCRM
}
```

Запросы не повторяются автоматически: при 429, 5xx и обрыве связи решение о
повторе остаётся за вызывающим кодом.

## Разработка

```bash
composer test       # тесты на фейковой amoCRM, без сети
composer test-live  # тесты на настоящем аккаунте
composer cs-check
```

Живым тестам нужен `.env` в корне проекта (в git он не попадает):

```
DOMAIN=example.amocrm.ru
TOKEN=долгосрочный-токен
TEST_PIPELINE_ID=6725478
TEST_STATUS_ID=56919066
```

Без этих значений живые тесты пропускаются. Каждый прогон создаёт по одной
сделке, контакту и компании с пометкой `[autotest]`; удалить их через API нельзя,
поэтому для тестов нужна отдельная воронка.

## Лицензия

MIT, см. [LICENSE](LICENSE).
