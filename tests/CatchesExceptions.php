<?php

declare(strict_types=1);

namespace Amocrm\Tests;

use Throwable;

/** Поймать исключение, чтобы проверить его поля: статус, сообщение, ответ amoCRM. */
trait CatchesExceptions
{
    /** Выполнить действие и вернуть исключение, которое оно бросило. */
    protected static function exceptionFrom(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $exception) {
            return $exception;
        }

        self::fail('Ожидалось исключение.');
    }
}
