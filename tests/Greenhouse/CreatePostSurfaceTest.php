<?php

declare(strict_types=1);

namespace App\Tests\Greenhouse;

use App\Command\CliProjector;
use App\Command\HttpProjector;
use App\Command\McpProjector;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\Runtime\Kernel;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The done-when: create_post declared ONCE (GreenhousePlugin) surfaces to coa + MCP + POST /posts,
 * each calling the SAME PostService::create, with the confirm gate honoured per surface.
 */
final class CreatePostSurfaceTest extends TestCase
{
    private function bootKernel(): Kernel
    {
        return Kernel::boot(['plugins' => [GreenhousePlugin::class]]);
    }

    public function testCliSurfaceCreatesAPost(): void
    {
        $kernel = $this->bootKernel();
        $op = $kernel->commands()[0];
        $lines = [];

        $code = (new CliProjector())->run(
            $op,
            ['--title=Hi', '--body=Yo', '--yes'],
            $kernel->container(),
            static function (string $l) use (&$lines): void {
                $lines[] = $l;
            },
        );

        self::assertSame(0, $code);
        self::assertSame(['{"id":1,"title":"Hi","body":"Yo"}'], $lines);
    }

    public function testMcpSurfaceRegistersAndInvokesTheSameHandler(): void
    {
        $kernel = $this->bootKernel();
        $captured = [];
        $registry = new class ($captured) implements ToolRegistryInterface {
            public function __construct(private array &$captured)
            {
            }

            public function register(string $name, string $description, array $inputSchema, callable $callback, ?ToolOptions $options = null): void
            {
                $this->captured = ['name' => $name, 'callback' => $callback, 'options' => $options];
            }
        };

        (new McpProjector())->project($kernel->commands(), $registry, $kernel->container());

        self::assertSame('create_post', $captured['name']);
        self::assertTrue($captured['options']->mutating);
        self::assertSame(['posts:write'], $captured['options']->scopes);
        self::assertSame(['id' => 1, 'title' => 'Hi', 'body' => 'Yo'], ($captured['callback'])(['title' => 'Hi', 'body' => 'Yo']));
    }

    public function testHttpSurfaceCreatesAPostThroughTheConfirmGate(): void
    {
        $kernel = $this->bootKernel();
        /** @var HttpProjector $projector */
        $projector = $kernel->container()->get(HttpProjector::class);

        $route = $projector->routes()[0];
        $make = static fn (): ServerRequest => (new ServerRequest('POST', '/posts', ['Content-Type' => 'application/json'], '{"title":"Hi","body":"Yo"}'))
            ->withAttribute(\Milpa\Http\Routing\RouteResult::ATTRIBUTE, \Milpa\Http\Routing\RouteResult::matched($route));

        $first = $projector->handle($make());
        self::assertSame(428, $first->getStatusCode());
        $token = json_decode((string) $first->getBody(), true)['confirm_token'];

        $second = $projector->handle($make()->withHeader('Confirm-Token', $token));
        self::assertSame(201, $second->getStatusCode());
        self::assertSame(['id' => 1, 'title' => 'Hi', 'body' => 'Yo'], json_decode((string) $second->getBody(), true));
    }
}
