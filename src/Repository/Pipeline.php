<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\ApiReader;

/** Воронки сделок со статусами — только чтение. */
final class Pipeline
{
    private const ENDPOINT = 'api/v4/leads/pipelines';

    private ApiClient $client;

    /** Обычно репозиторий берут у фасада: $amocrm->pipelines(). */
    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /** Все воронки аккаунта, статусы — в `_embedded.statuses`. Страниц у воронок нет. */
    public function findAll(): array
    {
        return $this->client->get(self::ENDPOINT)['_embedded']['pipelines'] ?? [];
    }

    /** Найти воронку по ID; null, если её нет. */
    public function findById(int $pipelineId): ?array
    {
        return ApiReader::one($this->client, self::ENDPOINT . '/' . $pipelineId);
    }
}
