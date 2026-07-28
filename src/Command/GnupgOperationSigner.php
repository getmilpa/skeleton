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

use Milpa\ToolRuntime\Identity\OperationAuthorization;

/**
 * Asks the operator's key to authorize one specific call, through the `gpg` on this machine.
 *
 * Lives in the host, not in the runtime, and the split is the same one the rest of the framework
 * uses: verifying is a policy decision and belongs where policy lives; signing is a fact about this
 * machine — which key, which agent, which card — and belongs where the machine is configured.
 *
 * What it signs is the whole call, never the operation's name alone. That is the difference between
 * this and the `--yes` it replaces: a flag consents to *removing a plugin*, so the same yes covers
 * removing any plugin, on any host, at any later moment. These bytes name one plugin, on this host,
 * in this minute.
 *
 * **What a signature proves here, and what it does not.** It always proves the call was authorized
 * by the holder of that key, and it always binds the target — those hold no matter how gpg-agent is
 * configured. What it does *not* prove on its own is human presence at this instant: with a cached
 * passphrase and a card that does not demand touch, signing needs no hands. Presence comes from the
 * key's own policy, so the gate is worth exactly what the card is set to require. Said plainly here
 * rather than implied, because "it is signed" invites a stronger reading than the mechanism earns.
 */
final class GnupgOperationSigner implements OperationSigner
{
    public function __construct(
        private readonly string $gpgBinary = 'gpg',
        private readonly ?string $keyId = null,
    ) {
    }

    /**
     * Builds the authorization for this call and returns it with its detached signature.
     *
     * @param array<string, mixed> $arguments the derived input, exactly as the handler will receive it
     *
     * @return array{0: string, 1: string}|null the canonical payload and its signature, or null when
     *                                          signing failed — a refused card, a missing key, no agent
     */
    public function sign(string $operation, array $arguments, string $host, int $now): ?array
    {
        $authorization = new OperationAuthorization(
            operation: $operation,
            arguments: $arguments,
            host: $host,
            issuedAt: gmdate('c', $now),
            // Random rather than sequential: a predictable nonce lets someone pre-compute the
            // ledger entry for an authorization the operator has not made yet, and burn it.
            nonce: bin2hex(random_bytes(16)),
        );

        $payload = $authorization->canonical();

        $payloadFile = tempnam(sys_get_temp_dir(), 'milpa-authz-');
        if ($payloadFile === false) {
            return null;
        }

        try {
            file_put_contents($payloadFile, $payload);

            $key = $this->keyId !== null && $this->keyId !== ''
                ? ' --local-user ' . escapeshellarg($this->keyId)
                : '';

            // No --batch: signing may need a passphrase or a card touch, and suppressing the prompt
            // would turn "the operator declined" into "the tool is broken".
            $command = escapeshellcmd($this->gpgBinary)
                . ' --armor --detach-sign' . $key . ' --output - '
                . escapeshellarg($payloadFile) . ' 2>/dev/null';

            $signature = shell_exec($command);

            if (!\is_string($signature) || !str_contains($signature, 'BEGIN PGP SIGNATURE')) {
                return null;
            }

            return [$payload, $signature];
        } finally {
            @unlink($payloadFile);
        }
    }
}
