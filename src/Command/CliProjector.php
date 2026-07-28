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

use Milpa\Command\Operation;
use Milpa\ToolRuntime\Identity\FileNonceLedger;
use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;
use Milpa\Command\SurfaceProjector;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * Projects an Operation to the `coa` CLI surface: derives typed inputs from `--flag=value` argv per
 * the operation's inputSchema (falling back to the raw string bag when there is no schema), takes a
 * signed authorization for anything that mutates and asks to be confirmed, invokes the handler, and
 * renders its return.
 *
 * **The consent gate is a signature, not a flag.** `--yes` used to stand for consent and could not
 * name what it consented to: yes to removing *a plugin* covers removing any plugin, on any host, at
 * any later moment, and anyone at the keyboard can type it. `--sign` signs the operation together
 * with its arguments and this host, so the authorization cannot be presented for a different target
 * and the audit line records which key answered — checkable afterwards by someone who does not
 * trust this process.
 *
 * Still non-interactive in the way that mattered: no `[y/N]` on stdin, nothing to block on or fake
 * in tests. The prompt that may appear belongs to the key, not to this projector.
 *
 * Reading is untouched. A shell already reads the database, so demanding a signature to list
 * anything would buy nothing and teach operators to disable the gate — which is how a real
 * protection becomes an ornament.
 */
final class CliProjector implements SurfaceProjector
{
    public function __construct(
        private readonly SchemaCoercer $coercer = new SchemaCoercer(),
        private readonly ?OperationSigner $signer = null,
        private readonly ?OperationAuthorizer $authorizer = null,
    ) {
    }

    /**
     * Turns `--sign` into a verified authorization for exactly this call, or refuses.
     *
     * The refusal paths matter as much as the success one, so each says what happened and what to
     * do: a card that declined is not a bad signature, and a bad signature is not an expired one.
     *
     * @param array<string, mixed>   $input
     * @param list<string>           $argv
     * @param callable(string): void $out
     *
     * @return int 0 when authorized, otherwise the exit code to return
     */
    private function authorizeBySignature(Operation $op, array $input, array $argv, callable $out): int
    {
        if (!\in_array('--sign', $argv, true)) {
            $out("This operation mutates and needs your authorization. Re-run with --sign.");
            $out('');
            $out('  --sign signs THIS call — the operation, these arguments, this host — with your');
            $out('  key. The authorization cannot be presented for a different target, which is');
            $out('  what a confirmation flag could never promise.');

            return 1;
        }

        $host = gethostname() ?: 'unknown-host';
        $now = time();

        $signed = ($this->signer ?? new GnupgOperationSigner())->sign($op->name, $input, $host, $now);
        if ($signed === null) {
            // Declining at the card lands here, and so does a missing key. Both mean the operation
            // does not run, and neither is an error in the operation.
            $out('✗ Nothing was signed, so nothing was authorized.');
            $out('  Either the signature was declined, or no usable key was found.');

            return 1;
        }

        [$payload, $signature] = $signed;

        $authorizer = $this->authorizer ?? new OperationAuthorizer(
            new GnupgSignatureVerifier(),
            new FileNonceLedger(\dirname(__DIR__, 2) . '/storage/authorizations'),
        );

        $verdict = $authorizer->authorize($op->name, $input, $host, $payload, $signature, $now);
        if (!$verdict->granted) {
            $out('✗ ' . (string) $verdict->reason);

            return 1;
        }

        // Printed, not just recorded: the operator sees which key answered before the effect
        // happens, so a wrong card is caught by the person rather than by an audit weeks later.
        $out('✓ authorized by ' . ($verdict->signer?->principal() ?? 'unknown'));

        return 0;
    }

    public function surface(): string
    {
        return 'cli';
    }

    public function supports(Operation $op): bool
    {
        return $op->supportsSurface('cli');
    }

    /**
     * @param list<string> $argv tokens after the command name
     *
     * @return array<string, mixed>
     *
     * @throws SchemaCoercionException
     */
    public function deriveInput(Operation $op, array $argv): array
    {
        return $this->coercer->coerce($op->inputSchema ?? [], $this->rawBag($argv));
    }

    /**
     * @param list<string>           $argv
     * @param callable(string): void $out
     */
    public function run(Operation $op, array $argv, DIContainerInterface $container, callable $out): int
    {
        try {
            $input = $op->inputSchema !== null ? $this->deriveInput($op, $argv) : $this->rawBag($argv);
        } catch (SchemaCoercionException $e) {
            $out('✗ ' . $e->getMessage());

            return 1;
        }

        if ($op->mutating && $op->requiresConfirmation) {
            // The input has to be derived first now, which is the whole reason the order changed:
            // `--yes` could be answered before knowing what the arguments were, because it never
            // referred to them. A signature is over the arguments, so there is nothing to sign
            // until they exist.
            $authorized = $this->authorizeBySignature($op, $input, $argv, $out);
            if ($authorized !== 0) {
                return $authorized;
            }
        }

        $handler = $op->handler;
        if (\is_callable($handler)) {
            /** @var mixed $result */
            $result = $handler($input);
        } else {
            [$class, $method] = $handler;
            $instance = $container->get($class);
            if (!\is_object($instance)) {
                $out("✗ command '{$op->name}': {$class} did not resolve to an object.");

                return 1;
            }
            /** @var mixed $result */
            $result = $instance->{$method}($input);
        }

        if (\is_int($result)) {
            return $result;
        }
        if ($result !== null) {
            $out(\is_string($result) ? $result : (string) \json_encode($result));
        }

        return 0;
    }

    /**
     * @param list<string> $argv
     *
     * @return array<string, string>
     */
    private function rawBag(array $argv): array
    {
        $bag = [];
        foreach ($argv as $token) {
            if (!str_starts_with($token, '--')) {
                continue;
            }
            $body = substr($token, 2);
            [$key, $value] = str_contains($body, '=') ? explode('=', $body, 2) : [$body, '1'];
            $bag[$key] = $value;
        }

        return $bag;
    }
}
