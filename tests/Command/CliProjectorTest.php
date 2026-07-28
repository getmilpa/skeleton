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

    public function testMutatingConfirmationIsRefusedWithoutASignedAuthorization(): void
    {
        // `--yes` is gone: it consented without naming what it consented to, so one yes covered
        // every plugin on every host. The gate now asks for a signature over this exact call, and
        // the refusal points at it. The four ways a signature can fail live in
        // {@see CliProjectorSignatureGateTest}; here only the door matters.
        $lines = [];
        $ran = false;
        $op = new Operation('wipe', 'Wipe', static function (array $i) use (&$ran): int {
            $ran = true;

            return 0;
        }, mutating: true, requiresConfirmation: true);

        $code = $this->projector->run($op, ['--yes'], $this->container, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        self::assertSame(1, $code);
        self::assertFalse($ran, 'The old flag authorizes nothing.');
        self::assertStringContainsString('--sign', implode("\n", $lines));
    }

    public function testItNamesItsSurfaceAndClaimsOnlyOperationsThatOfferIt(): void
    {
        // The projector registry routes by these two answers. A projector that
        // claimed every operation would run HTTP-only ones from the terminal.
        $solaCli = new Operation('solo_cli', 'Solo CLI', static fn (array $i): array => $i, surfaces: ['cli']);
        $solaHttp = new Operation('solo_http', 'Solo HTTP', static fn (array $i): array => $i, surfaces: ['http']);

        self::assertSame('cli', $this->projector->surface());
        self::assertTrue($this->projector->supports($solaCli));
        self::assertFalse($this->projector->supports($solaHttp));
    }

    public function testInputThatDoesNotMatchTheSchemaIsRefusedWithoutRunningTheHandler(): void
    {
        // Running the handler with a half-coerced bag is how a bad flag reaches
        // business logic as a 0.
        $ran = false;
        $lines = [];
        $op = new Operation('crear', 'Crear', static function (array $i) use (&$ran): array {
            $ran = true;

            return $i;
        }, inputSchema: [
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer']],
        ]);

        $code = $this->projector->run($op, ['--n=muchos'], $this->container, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        self::assertSame(1, $code);
        self::assertFalse($ran, 'The handler never saw the bad input.');
        self::assertStringContainsString('✗', implode("\n", $lines));
    }
}
