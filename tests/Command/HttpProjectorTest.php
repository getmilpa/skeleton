<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HttpProjector;
use Milpa\Command\Operation;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class HttpProjectorTest extends TestCase
{
    // Unscoped on purpose: these cases exercise the confirm gate + coercion in isolation, which is
    // the byte-identical empty-scope path. Scope enforcement (the closed Artifact 09 hole) has its
    // own dedicated fixture and suite in HttpProjectorScopeEnforcementTest.
    private function createPostOperation(): Operation
    {
        return new Operation(
            name: 'create_post',
            description: 'Create a post',
            handler: static fn (array $i): array => ['id' => 1] + $i,
            inputSchema: ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ], 'required' => ['title', 'body']],
            mutating: true,
            requiresConfirmation: true,
            path: '/posts',
        );
    }

    private function projector(Operation ...$ops): HttpProjector
    {
        return new HttpProjector($ops, $this->createMock(DIContainerInterface::class));
    }

    public function testSynthesizesOneRoutePerOperationWithVerbFromMutating(): void
    {
        $routes = $this->projector($this->createPostOperation())->routes();

        self::assertCount(1, $routes);
        self::assertSame('/posts', $routes[0]->path);
        self::assertSame([HttpMethod::POST], $routes[0]->methods);
        self::assertSame('create_post', $routes[0]->name);
        self::assertNotNull($routes[0]->handler);
    }

    public function testDerivesPathFromNameWhenNotDeclared(): void
    {
        $op = new Operation('board:seed', 'Seed', static fn (array $i): array => $i, inputSchema: ['type' => 'object']);
        $routes = $this->projector($op)->routes();

        self::assertSame('/board/seed', $routes[0]->path);
        self::assertSame([HttpMethod::GET], $routes[0]->methods); // not mutating -> GET
    }

    public function testMutatingRequestWithoutTokenReturns428WithAToken(): void
    {
        $projector = $this->projector($this->createPostOperation());
        $request = $this->matched($projector, 'POST', '/posts', '{"title":"Hi","body":"Yo"}');

        $response = $projector->handle($request);

        self::assertSame(428, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertTrue($payload['requires_confirmation']);
        self::assertNotEmpty($payload['confirm_token']);
    }

    public function testConfirmedRequestCreatesAndReturns201(): void
    {
        $projector = $this->projector($this->createPostOperation());
        $token = json_decode((string) $projector->handle(
            $this->matched($projector, 'POST', '/posts', '{"title":"Hi","body":"Yo"}')
        )->getBody(), true)['confirm_token'];

        $confirmed = $this->matched($projector, 'POST', '/posts', '{"title":"Hi","body":"Yo"}')
            ->withHeader('Confirm-Token', $token);

        $response = $projector->handle($confirmed);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['id' => 1, 'title' => 'Hi', 'body' => 'Yo'], json_decode((string) $response->getBody(), true));
    }

    public function testInvalidBodyReturns422(): void
    {
        $projector = $this->projector($this->createPostOperation());
        $confirmed = $this->matched($projector, 'POST', '/posts', '{"title":"Hi"}') // body missing
            ->withHeader('Confirm-Token', json_decode((string) $projector->handle(
                $this->matched($projector, 'POST', '/posts', '{"title":"Hi"}')
            )->getBody(), true)['confirm_token']);

        $response = $projector->handle($confirmed);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('body', (string) $response->getBody());
    }

    public function testDerivedPathWithEmptySegmentThrowsAtRouteSynthesis(): void
    {
        // 'bad::name' -> explode(':', ...) -> ['bad', '', 'name'] -> an empty middle segment,
        // which the Router's grammar cannot express (would produce a `//` in the path).
        $op = new Operation('bad::name', 'Bad', static fn (array $i): array => $i, inputSchema: ['type' => 'object']);
        $projector = $this->projector($op);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bad::name/');

        $projector->routes();
    }

    public function testDerivedPathContainingBraceThrowsAtRouteSynthesis(): void
    {
        // A name containing '{' would accidentally synthesize a Router placeholder segment.
        $op = new Operation('board:{seed}', 'Seed', static fn (array $i): array => $i, inputSchema: ['type' => 'object']);
        $projector = $this->projector($op);

        $this->expectException(\InvalidArgumentException::class);

        $projector->routes();
    }

    public function testUnknownOperationReturns404(): void
    {
        $projector = $this->projector($this->createPostOperation());
        // a matched RouteResult whose route name is not one of the projector's operations
        $stray = new \Milpa\Http\Routing\Route('/other', HttpMethod::GET, name: 'ghost');
        $request = (new ServerRequest('GET', '/other'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($stray));

        self::assertSame(404, $projector->handle($request)->getStatusCode());
    }

    private function matched(HttpProjector $projector, string $method, string $path, string $body): ServerRequest
    {
        $route = null;
        foreach ($projector->routes() as $r) {
            if ($r->path === $path) {
                $route = $r;
                break;
            }
        }
        self::assertNotNull($route, "no synthesized route for {$path}");

        return (new ServerRequest($method, $path, ['Content-Type' => 'application/json'], $body))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route));
    }
}
