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

namespace App\Tests\Support;

use App\Command\OperationSigner;
use Milpa\ToolRuntime\Identity\NonceLedger;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;

/**
 * A key that always answers, for tests whose subject is not the key.
 *
 * Shared so that a test about surface parity does not have to care how consent is expressed — it
 * hands the projector a signer and moves on. Everything about how a signature can fail is pinned in
 * the gate's own suite; duplicating it here would mean two places to update and one of them going
 * quietly stale.
 *
 * The payload is built for real from what the projector supplies, never canned: a stand-in string
 * would be rejected by the authorizer for the wrong reason, and the test would still pass.
 */
trait SignsOperations
{
    private function alwaysSigns(): OperationSigner
    {
        return new class () implements OperationSigner {
            public function sign(string $operation, array $arguments, string $host, int $now): ?array
            {
                $authorization = new OperationAuthorization(
                    operation: $operation,
                    arguments: $arguments,
                    host: $host,
                    issuedAt: gmdate('c', $now),
                    nonce: bin2hex(random_bytes(8)),
                );

                return [$authorization->canonical(), 'a signature the accepting verifier takes'];
            }
        };
    }

    private function acceptingAuthorizer(): OperationAuthorizer
    {
        $verifier = new class () implements SignatureVerifier {
            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', 'Test Operator <test@example.com>');
            }
        };

        $ledger = new class () implements NonceLedger {
            public function spend(string $nonce, int $ttlSeconds, int $now): bool
            {
                return true;
            }
        };

        // The real authorizer: freshness, target and single-use still run, so a test using this
        // trait would still catch the projector building the wrong payload.
        return new OperationAuthorizer($verifier, $ledger, 120);
    }
}
