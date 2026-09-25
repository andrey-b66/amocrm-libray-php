<?php

declare(strict_types=1);

namespace Amocrm\Support;

use Amocrm\Client\ApiClient;
use InvalidArgumentException;

/**
 * Запись в amoCRM, общая для репозиториев: списки сущностей порциями.
 *
 * @internal
 */
final class ApiWriter
{
    /** Сколько сущностей уходит в теле одного запроса на запись. */
    public const MAX_BATCH_SIZE = 250;

    /**
     * Проверить список сущностей и разбить его на порции по 250. $withIds —
     * ещё и требовать `id` у каждой: пакетный PATCH находит записи по нему.
     */
    public static function batches(array $entities, bool $withIds = false): array
    {
        foreach ($entities as $index => $entity) {
            // Одну сущность тоже передают списком: create([['name' => 'Иван']]).
            if (!is_array($entity)) {
                throw new InvalidArgumentException(
                    'Элемент ' . $index . ' не является массивом данных сущности: '
                    . 'передаётся список сущностей, даже если она одна.',
                );
            }

            if ($withIds && !isset($entity['id'])) {
                throw new InvalidArgumentException('В сущности ' . $index . ' не указан ID обновляемой записи.');
            }
        }

        // array_chunk нумерует порцию с нуля: иначе список с пропусками в ключах
        // ушёл бы JSON-объектом `{"0":…,"2":…}`, а amoCRM ждёт массив.
        return array_chunk($entities, self::MAX_BATCH_SIZE);
    }

    /**
     * Записать сущности порциями и собрать их из ответов по ключу `_embedded`.
     * Для `patch` у каждой сущности обязателен `id`.
     *
     * Исключение значит «прошло не всё»: прошлые порции уже записаны, следующие
     * не отправлены.
     *
     * @param string $httpMethod метод ApiClient: `post` или `patch`
     */
    public static function write(
        ApiClient $client,
        string $httpMethod,
        string $endpoint,
        string $embeddedKey,
        array $entities
    ): array {
        $result = [];

        foreach (self::batches($entities, $httpMethod === 'patch') as $batch) {
            $response = $client->$httpMethod($endpoint, $batch);

            foreach ($response['_embedded'][$embeddedKey] ?? [] as $entity) {
                $result[] = $entity;
            }
        }

        return $result;
    }

    /** Экземпляры не нужны: у класса только статические методы. */
    private function __construct()
    {
    }
}
