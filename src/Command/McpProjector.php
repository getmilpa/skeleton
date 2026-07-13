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
use Milpa\Command\SurfaceProjector;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;

/**
 * Projects Operations to the MCP surface by registering each into the tool registry. Because atoms
 * register through the same ToolRegistry the family already exposes over JSON-RPC, MCP transport
 * (tools/list + tools/call) and the confirm-token gate come for free from tool-runtime — this
 * projector only maps the atom's metadata into a ToolOptions. It references only milpa/core types
 * (always present), so it needs no class_exists guard; the guard lives at the call site where the
 * concrete ToolRegistry is constructed.
 */
final class McpProjector implements SurfaceProjector
{
    public function surface(): string
    {
        return 'mcp';
    }

    public function supports(Operation $op): bool
    {
        return $op->supportsSurface('mcp');
    }

    /**
     * @param iterable<Operation> $operations
     */
    public function project(iterable $operations, ToolRegistryInterface $registry, DIContainerInterface $container): void
    {
        foreach ($operations as $op) {
            if (!$this->supports($op)) {
                continue;
            }

            $registry->register(
                $op->name,
                $op->description,
                $op->inputSchema ?? [],
                $this->callableFrom($op->handler, $container),
                new ToolOptions(
                    scopes: $op->scopes,
                    mutating: $op->mutating,
                    requiresConfirmation: $op->requiresConfirmation,
                    version: $op->version,
                    outputSchema: $op->outputSchema,
                ),
            );
        }
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     *
     * @return callable(array<string, mixed>): mixed
     */
    private function callableFrom(mixed $handler, DIContainerInterface $container): callable
    {
        if (\is_callable($handler)) {
            return $handler;
        }

        [$class, $method] = $handler;

        return static fn (array $args): mixed => $container->get($class)->{$method}($args);
    }
}
