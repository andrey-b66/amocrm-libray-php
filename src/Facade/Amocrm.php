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
 * Точка входа: создаёт API-клиент по долгосрочному токену и отдаёт репозитории.
 * Репозитории принимают и возвращают массивы формата amoCRM API v4.
 *
 * Пример: $amocrm = new Amocrm('example.amocrm.ru', $longLivedToken);
 *         $leads = $amocrm->leads()->find('filter[pipeline_id][0]=10739150', 250, null);
 */
final class Amocrm
{
    /** Один клиент на все репозитории: у них общее соединение. */
    private ApiClient $apiClient;

    /** Таймауты в секундах: сколько ждать ответа целиком и сколько — соединения. */
    public function __construct(
        string $domain,
        string $longLivedToken,
        int $timeout = ApiClient::DEFAULT_TIMEOUT,
        int $connectTimeout = ApiClient::DEFAULT_CONNECT_TIMEOUT
    ) {
        $this->apiClient = new ApiClient($domain, $longLivedToken, $timeout, $connectTimeout);
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

    /** Получить API-клиент для запросов, которых нет в репозиториях. */
    public function raw(): ApiClient
    {
        return $this->apiClient;
    }
}
