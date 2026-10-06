<?php

namespace App\Services\Bitrix24;

use RuntimeException;

final class EntityRestException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Bitrix24 storage error: '.$errorCode);
    }
}
