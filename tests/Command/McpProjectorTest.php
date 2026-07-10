<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\McpProjector;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;

final class McpProjectorTest extends TestCase
{
    public function testProjectsAnOperationIntoAToolRegistrationCarryingPolicyMetadata(): void
    {
        $captured = [];
        $registry = new class ($captured) implements ToolRegistryInterface {
            /** @param array<string,mixed> $captured */
            public function __construct(private array &$captured)
            {
            }

            public function register(string $name, string $description, array $inputSchema, callable $callback, ?ToolOptions $options = null): void
            {
                $this->captured = compact('name', 'description', 'inputSchema', 'callback', 'options');
            }
        };
        $container = $this->createMock(DIContainerInterface::class);

        $op = new Operation(
            name: 'create_post',
            description: 'Create a post',
            handler: static fn (array $i): array => ['id' => 1] + $i,
            inputSchema: ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            mutating: true,
            requiresConfirmation: true,
            scopes: ['posts:write'],
        );

        (new McpProjector())->project([$op], $registry, $container);

        self::assertSame('create_post', $captured['name']);
        self::assertSame('Create a post', $captured['description']);
        self::assertSame(['type' => 'object', 'properties' => ['title' => ['type' => 'string']]], $captured['inputSchema']);
        self::assertInstanceOf(ToolOptions::class, $captured['options']);
        self::assertTrue($captured['options']->mutating);
        self::assertTrue($captured['options']->requiresConfirmation);
        self::assertSame(['posts:write'], $captured['options']->scopes);
        // the callback forwards to the operation handler and returns raw domain data
        self::assertSame(['id' => 1, 'title' => 'Hi'], ($captured['callback'])(['title' => 'Hi']));
    }

    public function testSkipsOperationsThatOptOutOfMcp(): void
    {
        $calls = 0;
        $registry = new class ($calls) implements ToolRegistryInterface {
            public function __construct(private int &$calls)
            {
            }

            public function register(string $name, string $description, array $inputSchema, callable $callback, ?ToolOptions $options = null): void
            {
                ++$this->calls;
            }
        };

        $op = new Operation('cli_only', 'x', static fn (array $i) => $i, surfaces: ['cli']);
        (new McpProjector())->project([$op], $registry, $this->createMock(DIContainerInterface::class));

        self::assertSame(0, $calls);
    }
}
