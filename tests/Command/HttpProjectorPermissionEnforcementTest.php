<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HttpProjector;
use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\ArrayPermissionCatalog;
use Milpa\Auth\AuthContext;
use Milpa\Auth\CatalogPermissionResolver;
use Milpa\Auth\Contracts\AuthContextFactory;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Contracts\PermissionResolver;
use Milpa\Auth\Exceptions\AuthMiddlewareNotInstalledException;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Command\Operation;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The permission-typed counterpart of {@see HttpProjectorScopeEnforcementTest}: proves `handle()` maps
 * an Operation typed by `$permission` (rather than `$scopes`) to milpa/auth's
 * {@see \Milpa\Auth\Http\RequirePermissionMiddleware} — denied is 403 MILPA_PERMISSION_DENIED, granted
 * runs the atom, and a permission-typed operation with no auth chain wired is the same 500 architectural
 * error as the scope path (never a 401/403 that would blame the caller).
 */
final class HttpProjectorPermissionEnforcementTest extends TestCase
{
    /** A mutating operation guarded by the permission 'crm.contact:update'. */
    private function permissionedUpdateOp(): Operation
    {
        return new Operation(
            name: 'update_contact',
            description: 'Update a contact',
            handler: static fn (array $i): array => ['updated' => true],
            inputSchema: ['type' => 'object'],
            permission: 'crm.contact:update',
            path: '/contact',
        );
    }

    private function projector(Operation $op, DIContainerInterface $container): HttpProjector
    {
        return new HttpProjector([$op], $container);
    }

    /**
     * A container that resolves the auth chain (a CredentialVerifier) AND a CatalogPermissionResolver
     * bound to PermissionResolver::class, whose catalog grants 'crm.contact:update' to the 'editor'
     * role.
     */
    private function containerWithAuthChainAndResolver(): DIContainerInterface
    {
        $resolver = new CatalogPermissionResolver(
            ArrayPermissionCatalog::fromArray(['roles' => ['editor' => ['permissions' => ['crm.contact:update']]]]),
        );

        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => \in_array(
                $id,
                [CredentialVerifier::class, AuthContextFactory::class, PermissionResolver::class],
                true,
            ),
        );
        $container->method('get')->willReturnCallback(
            static fn (string $id): mixed => $id === PermissionResolver::class ? $resolver : null,
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

        return (new ServerRequest('POST', $path))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route));
    }

    private function withActor(ServerRequest $request, Actor $actor): ServerRequest
    {
        return $request->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::authenticated($actor));
    }

    public function testPermissionedOperationDeniedIs403(): void
    {
        $projector = $this->projector($this->permissionedUpdateOp(), $this->containerWithAuthChainAndResolver());
        $request = $this->withActor(
            $this->matched($projector, '/contact'),
            new Actor('user:9', ActorType::User), // no scopes, no roles -> lacks crm.contact:update
        );

        $response = $projector->handle($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('MILPA_PERMISSION_DENIED', $payload['code']);
    }

    public function testPermissionedOperationGrantedRuns(): void
    {
        $projector = $this->projector($this->permissionedUpdateOp(), $this->containerWithAuthChainAndResolver());
        $request = $this->withActor(
            $this->matched($projector, '/contact'),
            new Actor('user:1', ActorType::User, [], [], ['editor']),
        );

        $response = $projector->handle($request);

        self::assertContains($response->getStatusCode(), [200, 201]);
        self::assertSame(['updated' => true], json_decode((string) $response->getBody(), true));
    }

    public function testPermissionedOperationNoAuthChainIsA500ArchitecturalErrorNotA401Or403(): void
    {
        $projector = $this->projector($this->permissionedUpdateOp(), $this->containerWithoutAuthChain());
        // A perfectly valid caller, holding the granting role. The FAILURE is the host's.
        $request = $this->withActor(
            $this->matched($projector, '/contact'),
            new Actor('user:1', ActorType::User, [], [], ['editor']),
        );

        try {
            $projector->handle($request);
            self::fail('expected AuthMiddlewareNotInstalledException');
        } catch (AuthMiddlewareNotInstalledException $e) {
            self::assertSame(500, $e->statusCode());
            self::assertNotSame(401, $e->statusCode());
            self::assertNotSame(403, $e->statusCode());
            self::assertSame('MILPA_AUTH_MIDDLEWARE_NOT_INSTALLED', $e->errorCode());
            self::assertStringContainsString('update_contact', $e->getMessage());
            self::assertStringContainsString('crm.contact:update', $e->getMessage());
        }
    }
}
