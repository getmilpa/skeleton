<?php

declare(strict_types=1);

namespace App\Command;

use Milpa\Command\Operation;
use Milpa\Command\SurfaceProjector;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * Projects an Operation to the `coa` CLI surface: derives typed inputs from `--flag=value` argv per
 * the operation's inputSchema (falling back to the raw string bag when there is no schema), enforces
 * the mutating confirm gate via an explicit `--yes`, invokes the handler, and renders its return.
 *
 * The confirm gate is deliberately non-interactive: a mutating operation with
 * `requiresConfirmation: true` requires the caller to pass `--yes` up front — there is no
 * interactive `[y/N]` prompt. This is a slice-1 decision, not an oversight: it keeps `run()`
 * deterministic and scriptable (no stdin read to block on or fake in tests), and it fails safe —
 * absent explicit consent via `--yes`, the operation is refused rather than mutating.
 */
final class CliProjector implements SurfaceProjector
{
    public function __construct(private readonly SchemaCoercer $coercer = new SchemaCoercer())
    {
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
        if ($op->mutating && $op->requiresConfirmation && !\in_array('--yes', $argv, true)) {
            $out("This operation is mutating and requires confirmation. Re-run with --yes.");

            return 1;
        }

        try {
            $input = $op->inputSchema !== null ? $this->deriveInput($op, $argv) : $this->rawBag($argv);
        } catch (SchemaCoercionException $e) {
            $out('✗ ' . $e->getMessage());

            return 1;
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
