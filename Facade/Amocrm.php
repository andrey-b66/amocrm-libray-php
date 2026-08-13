<?php

declare(strict_types=1);

namespace Amocrm\Facade;

use Amocrm\Client\ApiClient;
use Amocrm\Repository\Call;
use Amocrm\Repository\Company;
use Amocrm\Repository\Contact;
use Amocrm\Repository\Lead;
use Amocrm\Repository\Link;
use Amocrm\Repository\Note;
use Amocrm\Repository\Pipeline;
use Amocrm\Repository\Tag;
use Amocrm\Repository\Task;
use Amocrm\Repository\User;

/**
 * Единая точка входа для работы с amoCRM.
 *
 * Фасад создаёт API-клиент с долгосрочным токеном и по требованию возвращает
 * репозитории, использующие один и тот же авторизованный клиент. Репозитории
 * принимают и возвращают обычные массивы формата amoCRM API v4.
 *
 * Третий аргумент — сколько запросов уходит одновременно при параллельном
 * чтении страниц (findPages, getMany). Обычные методы он не затрагивает: они
 * как и раньше делают один запрос за раз. По умолчанию три — с запасом под
 * лимит amoCRM в 7 запросов в секунду на аккаунт; выше поднимать стоит,
 * только если аккаунт этот темп выдерживает.
 *
 * Пример: $amocrm = new Amocrm('example.amocrm.ru', $longLivedToken);
 *         $leads = $amocrm->leads()->findAll('filter[status_id][0]=143');
 * Пример: $amocrm = new Amocrm('example.amocrm.ru', $longLivedToken, 5);
 */
final class Amocrm
{
    private ApiClient $apiClient;

    public function __construct(
        string $domain,
        string $longLivedToken,
        int $requestsAtOnce = ApiClient::DEFAULT_REQUESTS_AT_ONCE,
    ) {
        $this->apiClient = new ApiClient($domain, $longLivedToken, $requestsAtOnce);
    }

    /** Получить репозиторий контактов. */
    public function contacts(): Contact
    {
        return new Contact($this->apiClient);
    }

    /** Получить репозиторий сделок. */
    public function leads(): Lead
    {
        return new Lead($this->apiClient);
    }

    /** Получить репозиторий компаний. */
    public function companies(): Company
    {
        return new Company($this->apiClient);
    }

    /** Получить репозиторий примечаний. */
    public function notes(): Note
    {
        return new Note($this->apiClient);
    }

    /** Получить репозиторий задач. */
    public function tasks(): Task
    {
        return new Task($this->apiClient);
    }

    /** Получить репозиторий пользователей. */
    public function users(): User
    {
        return new User($this->apiClient);
    }

    /** Получить репозиторий регистрации звонков. */
    public function calls(): Call
    {
        return new Call($this->apiClient);
    }

    /** Получить репозиторий связей между сущностями. */
    public function links(): Link
    {
        return new Link($this->apiClient);
    }

    /** Получить репозиторий воронок сделок. */
    public function pipelines(): Pipeline
    {
        return new Pipeline($this->apiClient);
    }

    /** Получить репозиторий тегов. */
    public function tags(): Tag
    {
        return new Tag($this->apiClient);
    }

    /** Получить низкоуровневый доступ к amoCRM API v4: get/post/patch/put/delete. */
    public function raw(): ApiClient
    {
        return $this->apiClient;
    }
}
