<?php

/**
 * This file is part of Milpa Skeleton — the composer create-project starting point for a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/skeleton
 */

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
