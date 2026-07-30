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

use Milpa\Auth\AuthContext;
use Milpa\Auth\Contracts\AuthContextFactory;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Contracts\PermissionContextFactory;
use Milpa\Auth\Contracts\PermissionResolver;
use Milpa\Auth\Exceptions\AuthContextMissingException;
use Milpa\Auth\Exceptions\AuthMiddlewareNotInstalledException;
use Milpa\Auth\Exceptions\PermissionDeniedException;
use Milpa\Auth\Exceptions\ScopeDeniedException;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\Http\RequirePermissionMiddleware;
use Milpa\Auth\Http\RequireScopeMiddleware;
use Milpa\Auth\Permission;
use Milpa\Command\HttpRouteModel;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * La política de la superficie HTTP: qué exige una operación antes de correr.
 *
 * Salió de HttpProjector, que conflaciaba TRES responsabilidades — proyectar la operación a una
 * ruta, decidir si quien llama puede, y atender la petición. ADR-0035 separa la primera de la
 * tercera; ésta es la del medio, y el ADR dice explícitamente que no le pertenece a ninguna de las
 * dos: vive en el eje `Intent -> Policy -> Signer`.
 *
 * Sacarla tiene una consecuencia medible y no sólo argumentable: las 37 referencias a `Milpa\Auth`
 * de HttpProjector vivían TODAS aquí. Con la política afuera, la mitad que proyecta queda libre de
 * identidad — que es lo que permite que `HttpRouteModel` viva en `milpa/command` sin arrastrar
 * `milpa/auth` al piso mínimo del framework.
 *
 * Scope Y permission: una operación se tipa por uno o por el otro, nunca por los dos — `Operation`
 * lo rechaza en su constructor. El PolicyGate de tool-runtime es defensa en profundidad específica
 * de scope y no aplica a las tipadas por permiso.
 */
final class HttpOperationPolicy
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * Enforces `$op`'s declared scopes for one request. Returns `null` when the request is authorized
     * (the caller proceeds to the confirm gate + handler), or a ready-to-send 401/403 JSON response
     * when the milpa/auth scope gate denies it. Throws {@see AuthMiddlewareNotInstalledException}
     * (500) — Rod's binding distinction — when the operation declares scopes but the host wired no
     * auth chain to enforce them: a server misconfiguration, deliberately NOT a 401/403.
     */
    public function enforceScopes(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->authChainInstalled()) {
            throw AuthMiddlewareNotInstalledException::forScopedOperation($op->name, $op->scopes);
        }

        // The fail-closed scope gate. RequireScopeMiddleware reads the AuthContext an upstream
        // AuthenticateMiddleware/StartSession attached under 'milpa.auth' and throws the typed,
        // learnable denial; the sentinel handler runs only when it admits the request.
        $guard = new RequireScopeMiddleware(...$op->scopes);
        try {
            $guard->process($request, new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(204);
                }
            });
        } catch (AuthContextMissingException | ScopeDeniedException $e) {
            return $this->json($e->statusCode(), ['error' => $e->getMessage(), 'code' => $e->errorCode()]);
        }

        // Authorized. The context stops lying: run the atom through the same policy layer MCP uses,
        // with the honest ToolContext::web (real principal + real scopes). Opt-in — only when the
        // agent-ready surface (milpa/tool-runtime) is installed.
        return $this->enforceWebPolicy($op, $request);
    }

    /**
     * Enforces `$op`'s declared permission for one request, mirroring {@see self::enforceScopes()}.
     * Returns null when authorized, a ready 401/403 JSON response when the milpa/auth permission gate
     * denies, and throws {@see AuthMiddlewareNotInstalledException} (500) when the operation declares a
     * permission but the host wired no auth chain. The permission path runs the honest RequirePermission
     * gate only — the scope-based tool-runtime PolicyGate is not applied here.
     */
    public function enforcePermission(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->authChainInstalled()) {
            throw AuthMiddlewareNotInstalledException::forPermissionedOperation($op->name, (string) $op->permission);
        }

        $resolver = $this->container->has(PermissionResolver::class) ? $this->container->get(PermissionResolver::class) : null;
        $contextFactory = $this->container->has(PermissionContextFactory::class) ? $this->container->get(PermissionContextFactory::class) : null;

        $guard = new RequirePermissionMiddleware(
            Permission::parse((string) $op->permission),
            $resolver instanceof PermissionResolver ? $resolver : null,
            $contextFactory instanceof PermissionContextFactory ? $contextFactory : null,
        );

        try {
            $guard->process($request, new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(204);
                }
            });
        } catch (AuthContextMissingException | PermissionDeniedException $e) {
            return $this->json($e->statusCode(), ['error' => $e->getMessage(), 'code' => $e->errorCode()]);
        }

        return null;
    }

    /**
     * Whether the host wired an auth chain able to produce a verified {@see AuthContext} — i.e. a
     * {@see CredentialVerifier} or {@see AuthContextFactory} is resolvable in the container. When
     * neither is, a scope-declaring operation cannot be honestly enforced, which is a host
     * configuration error (500), not a request failure.
     */
    public function authChainInstalled(): bool
    {
        return $this->container->has(CredentialVerifier::class)
            || $this->container->has(AuthContextFactory::class);
    }

    /**
     * Defense in depth for an already-authorized request: rebuilds the honest {@see ToolContext::web()}
     * from the request's verified actor and runs the atom through the same {@see PolicyGate} that
     * guards the MCP surface, so the HTTP atom is subject to the identical policy layer. Opt-in: a
     * no-op (returns `null`) unless milpa/tool-runtime is installed. Returns a 403 JSON response if
     * the gate denies, or `null` to proceed.
     */
    public function enforceWebPolicy(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!class_exists(ToolContext::class) || !class_exists(PolicyGate::class)) {
            return null;
        }

        $context = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
        if (!$context instanceof AuthContext || $context->actor === null) {
            return null; // unreachable once the scope gate above admitted the request; fail-safe
        }

        $decision = (new PolicyGate())->authorize(
            ToolContext::web($context->actor->id, $context->actor->scopes),
            new ToolDefinition(
                name: $op->name,
                description: $op->description,
                inputSchema: $op->inputSchema ?? [],
                callback: static fn (): null => null,
                scopes: $op->scopes,
                mutating: $op->mutating,
                requiresConfirmation: $op->requiresConfirmation,
            ),
        );

        if (!$decision->allowed) {
            return $this->json(403, ['error' => $decision->reason, 'code' => 'MILPA_SCOPE_DENIED']);
        }

        return null;
    }

    private function json(int $status, mixed $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
}
