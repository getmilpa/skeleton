<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CliProjector;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use PHPUnit\Framework\TestCase;

final class CliProjectorTest extends TestCase
{
    private CliProjector $projector;
    private DIContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = new CliProjector();
        $this->container = $this->createMock(DIContainerInterface::class);
    }

    public function testDerivesTypedInputFromFlagsPerSchema(): void
    {
        $op = new Operation('create_post', 'Create', static fn (array $i): array => $i, inputSchema: [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'priority' => ['type' => 'integer']],
        ]);

        $input = $this->projector->deriveInput($op, ['--title=Hi', '--priority=3']);

        self::assertSame(['title' => 'Hi', 'priority' => 3], $input);
    }

    public function testRunInvokesTheHandlerWithCoercedInputAndRendersResult(): void
    {
        $lines = [];
        $op = new Operation('echo', 'Echo', static fn (array $i): array => ['got' => $i], inputSchema: [
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer']],
        ]);

        $code = $this->projector->run($op, ['--n=42'], $this->container, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        self::assertSame(0, $code);
        self::assertSame(['{"got":{"n":42}}'], $lines);
    }

    public function testNullSchemaKeepsTheRawStringBag(): void
    {
        $op = new Operation('legacy', 'Legacy', static fn (array $i): array => $i); // inputSchema null

        $code = $this->projector->run($op, ['--a=1', '--b=x'], $this->container, static fn (string $l) => null);

        self::assertSame(0, $code);
    }

    public function testMutatingConfirmationRequiresYes(): void
    {
        $lines = [];
        $ran = false;
        $op = new Operation('wipe', 'Wipe', static function (array $i) use (&$ran): int {
            $ran = true;

            return 0;
        }, mutating: true, requiresConfirmation: true);

        $code = $this->projector->run($op, [], $this->container, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        self::assertSame(1, $code);
        self::assertFalse($ran, 'handler must not run without --yes');
        self::assertStringContainsString('--yes', $lines[0]);
    }

    public function testMutatingProceedsWithYes(): void
    {
        $ran = false;
        $op = new Operation('wipe', 'Wipe', static function (array $i) use (&$ran): int {
            $ran = true;

            return 0;
        }, mutating: true, requiresConfirmation: true);

        $code = $this->projector->run($op, ['--yes'], $this->container, static fn (string $l) => null);

        self::assertSame(0, $code);
        self::assertTrue($ran);
    }
}
