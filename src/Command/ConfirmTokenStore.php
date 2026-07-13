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

/**
 * In-memory one-time confirm tokens with a TTL. Backs the HTTP surface's two-step confirm gate: a
 * mutating operation that requires confirmation issues a token (428), and the client repeats the
 * request with it. MCP inherits tool-runtime's own gate; the CLI confirms inline — so this store
 * serves the HTTP projector only.
 *
 * PER-PROCESS ONLY: `$tokens` is a plain in-memory array, scoped to the lifetime of the PHP
 * process that holds it. That is fine for a single long-lived process (e.g. a test run, or a
 * `php -S`-style dev server handling one request at a time in one process), but it does NOT
 * survive across requests in a real multi-process deployment — php-fpm workers, or any server
 * that spawns a fresh process per request. Each request gets a fresh, empty store, so a token
 * issued while handling the 428 response is gone by the time the client retries with it: the
 * two-step confirm gate can never complete. Before this HTTP confirm surface is used in
 * production, back it with a store that outlives a single process — a session, a shared cache
 * (e.g. Redis/Memcached), or a database table with the same one-time-use-plus-TTL semantics.
 */
final class ConfirmTokenStore
{
    /** @var array<string, array{operation: string, expires: int}> */
    private array $tokens = [];

    public function __construct(private readonly int $ttlSeconds = 60)
    {
    }

    public function issue(string $operation): string
    {
        $token = bin2hex(random_bytes(16));
        $this->tokens[$token] = ['operation' => $operation, 'expires' => time() + $this->ttlSeconds];

        return $token;
    }

    public function consume(string $token, string $operation): bool
    {
        $entry = $this->tokens[$token] ?? null;
        unset($this->tokens[$token]); // one-time use, even on mismatch

        if ($entry === null) {
            return false;
        }
        if ($entry['operation'] !== $operation) {
            return false;
        }

        return $entry['expires'] >= time();
    }
}
