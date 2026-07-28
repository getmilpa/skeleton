<?php

/**
 * This file is part of Milpa Skeleton — the bootable runtime of the Milpa PHP framework.
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
 * Produces an authorization for one call, signed by whoever is meant to approve it.
 *
 * A port for the same reason verification is one: signing leaves the process. Here it reaches a
 * key agent and possibly a card, and a test that had to touch real hardware to cover "the operator
 * declined" would simply never cover it — which is the branch most worth pinning, since it is the
 * one that runs when someone changes their mind.
 */
interface OperationSigner
{
    /**
     * @param array<string, mixed> $arguments the derived input, exactly as the handler will receive it
     *
     * @return array{0: string, 1: string}|null the canonical payload and its signature, or null when
     *                                          nothing was signed — declined, no key, no agent
     */
    public function sign(string $operation, array $arguments, string $host, int $now): ?array;
}
