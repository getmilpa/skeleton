<?php

declare(strict_types=1);

namespace App\Command;

final class SchemaCoercionException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('input validation failed: ' . implode('; ', $errors));
    }
}
