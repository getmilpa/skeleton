<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HttpProjector;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Contracts\AuthContextFactory;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Exceptions\AuthMiddlewareNotInstalledException;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Command\Operation;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The Artifact 09 hole closes: HTTP stops running a scoped atom naked. These pin the four outcomes of
 * a scope-declaring Operation on the HTTP surface — plus Rod's binding distinction (a scoped op with
 * no auth chain wired is a 500 host-misconfiguration, never a 401/403 that would blame the caller) —
 * and prove an unscoped Operation is byte-identical to the pre-auth path.
 */
final class HttpProjectorScopeEnforcementTest extends TestCase
{
    /** A read-only, no-confirm operation guarded by a single scope — isolates auth from the confirm gate. */
    private function scopedReadOp(): Operation
    {
        return new Operation(
            name: 'read_secret',
            description: 'Read a secret',
            handler: static fn (array $i): array => ['secret' => 'ok'],
            inputSchema: ['type' => 'object'],
            scopes: ['posts:read'],
            path: '/secret',
        );
    }

    private function projector(Operation $op, DIContainerInterface $container): HttpProjector
    {
        return new HttpProjector([$op], $container);
    }

    /** A container that resolves the auth chain — the host wired a CredentialVerifier. */
    private function containerWithAuthChain(): DIContainerInterface
    {
        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => $id === CredentialVerifier::class || $id === AuthContextFactory::class,
        );

        return $container;
    }

    /** A container with NO auth chain wired — the architectural-500 case. */
    private function containerWithoutAuthChain(): DIContainerInterface
    {
        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturn(false);

        return $container;
    }

    private function matched(HttpProjector $projector, string $path): ServerRequest
    {
        $route = null;
        foreach ($projector->routes() as $r) {
            if ($r->path === $path) {
                $route = $r;
                break;
            }
        }
        self::assertNotNull($route, "no synthesized route for {$path}");

        return (new ServerRequest('GET', $path))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route));
    }

    private function withActor(ServerRequest $request, Actor $actor): ServerRequest
    {
        return $request->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::authenticated($actor));
    }

    public function testScopedOpWithWiredChainAndActorHoldingTheScopeReturns200(): void
    {
        $projector = $this->projector($this->scopedReadOp(), $this->containerWithAuthChain());
        $request = $this->withActor(
            $this->matched($projector, '/secret'),
            new Actor('user:42', ActorType::User, ['posts:read']),
        );

        $response = $projector->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['secret' => 'ok'], json_decode((string) $response->getBody(), true));
    }

    public function testScopedOpWithWiredChainAndActorLackingTheScopeReturns403ScopeDenied(): void
    {
        $projector = $this->projector($this->scopedReadOp(), $this->containerWithAuthChain());
        $request = $this->withActor(
            $this->matched($projector, '/secret'),
            new Actor('user:9', ActorType::User, ['posts:write']), // lacks posts:read
        );

        $response = $projector->handle($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('MILPA_SCOPE_DENIED', $payload['code']);
    }

    public function testScopedOpWithWiredChainButNoActorReturns401AuthContextMissing(): void
    {
        $projector = $this->projector($this->scopedReadOp(), $this->containerWithAuthChain());
        // No milpa.auth attribute at all — the anonymous / no-actor case.
        $request = $this->matched($projector, '/secret');

        $response = $projector->handle($request);

        self::assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('MILPA_AUTH_CONTEXT_MISSING', $payload['code']);
    }

    public function testScopedOpWithNoAuthChainWiredIsA500ArchitecturalErrorNotA401Or403(): void
    {
        $projector = $this->projector($this->scopedReadOp(), $this->containerWithoutAuthChain());
        // A perfectly valid caller: authenticated, holds the scope. The FAILURE is the host's.
        $request = $this->withActor(
            $this->matched($projector, '/secret'),
            new Actor('user:42', ActorType::User, ['posts:read']),
        );

        try {
            $projector->handle($request);
            self::fail('expected AuthMiddlewareNotInstalledException');
        } catch (AuthMiddlewareNotInstalledException $e) {
            self::assertSame(500, $e->statusCode());
            self::assertNotSame(401, $e->statusCode());
            self::assertNotSame(403, $e->statusCode());
            self::assertSame('MILPA_AUTH_MIDDLEWARE_NOT_INSTALLED', $e->errorCode());
            self::assertStringContainsString('read_secret', $e->getMessage());
        }
    }

    public function testUnscopedOpIsByteIdenticalEvenWithNoChainAndNoActor(): void
    {
        // An operation that declares NO scopes never touches the auth path: no 500 despite the
        // missing chain, no 401 despite the missing actor — exactly the pre-auth confirm-gate-only path.
        $op = new Operation(
            name: 'ping',
            description: 'Ping',
            handler: static fn (array $i): array => ['pong' => true],
            inputSchema: ['type' => 'object'],
            path: '/ping',
        );
        $projector = $this->projector($op, $this->containerWithoutAuthChain());
        $request = $this->matched($projector, '/ping'); // no auth attribute at all

        $response = $projector->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['pong' => true], json_decode((string) $response->getBody(), true));
    }
}
