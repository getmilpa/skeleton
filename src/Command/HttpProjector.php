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
use Milpa\Auth\Exceptions\AuthContextMissingException;
use Milpa\Auth\Exceptions\AuthMiddlewareNotInstalledException;
use Milpa\Auth\Exceptions\ScopeDeniedException;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Auth\Http\RequireScopeMiddleware;
use Milpa\Command\Operation;
use Milpa\Command\SurfaceProjector;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Projects Operations to the HTTP surface. It is BOTH a route source — `routes()` synthesizes one
 * bound Route per operation (path declared or derived from the name; verb from `mutating`) — AND the
 * generic adapter controller those routes point at: `handle()` looks the operation up from the
 * matched RouteResult, ENFORCES the operation's declared scopes, applies the two-step confirm gate
 * for mutating operations, merges path + query + body into one input bag, coerces/validates it,
 * invokes the operation's domain handler, and serializes the returned domain data to JSON. A host
 * plugin registers one instance under HttpProjector::class and returns `routes()` from its own
 * RouteProviderInterface::routes().
 *
 * SCOPE ENFORCEMENT — the Artifact 09 hole closes here. HTTP used to run a scope-declaring atom
 * NAKED: `$op->scopes` was ignored on this surface while MCP enforced it via tool-runtime's
 * PolicyGate. Now, for an operation whose `$op->scopes` is non-empty, `handle()` runs milpa/auth's
 * {@see RequireScopeMiddleware} against the {@see AuthContext} that an upstream
 * {@see AuthenticateMiddleware}/StartSession attached under `'milpa.auth'` — 401
 * {@see AuthContextMissingException} when no verified actor is present, 403
 * {@see ScopeDeniedException} when the actor holds none of the required scopes — then rebuilds the
 * honest {@see ToolContext::web()} (real principal + real scopes, never the faked wildcard) and runs
 * it through the same {@see PolicyGate} that guards MCP. An operation with EMPTY scopes touches NONE
 * of this: it is byte-identical to the pre-auth confirm-gate-only path.
 *
 * ENFORCEMENT LIVES IN `handle()`, NOT ROUTE MIDDLEWARE, on purpose: there is exactly ONE
 * HttpProjector instance serving EVERY operation (see below), and a route's `middleware[]` is
 * resolved by class-string to a SINGLE shared container instance — which cannot carry a per-operation
 * scope list. The generic handler is the one place each operation's own scopes are known, so it is
 * where the per-op scope gate must run. The route `middleware[]` slot stays free for app-global
 * middleware (which the runtime pipeline still composes).
 *
 * ROD'S BINDING DISTINCTION: a scope-declaring operation whose host wired NO auth chain (no
 * {@see CredentialVerifier}/{@see AuthContextFactory} resolvable in the container) is a SERVER
 * misconfiguration — `handle()` throws {@see AuthMiddlewareNotInstalledException} (500), NEVER a
 * 401/403. A 4xx would blame the caller; the caller did nothing wrong — the host declared a protected
 * operation and left it unguarded. tool-runtime (the honest-context/PolicyGate layer) is opt-in, so
 * that defense-in-depth step is `class_exists`-guarded; the milpa/auth scope gate above is always on.
 *
 * SINGLE INSTANCE PER APP: there must be exactly ONE HttpProjector registered in the container,
 * under the `HttpProjector::class` key — not one per plugin. Every synthesized route's handler is
 * a `HandlerReference(self::class, 'handle')`, resolved through the container by that same class
 * key at dispatch time. If more than one host plugin registered its own instance under
 * `HttpProjector::class`, the last registration would win the container slot, so routes
 * synthesized by (and pointing back at) an earlier instance would resolve to the wrong instance
 * at dispatch — one that doesn't know their operation — and 404. Supporting multiple
 * command-providing plugins on the HTTP surface (e.g. by merging their operations into one
 * instance, or namespacing the container key) is a follow-up, not something this slice handles.
 */
final class HttpProjector implements SurfaceProjector
{
    /** @var array<string, Operation> keyed by operation name */
    private array $operations = [];

    /**
     * @param iterable<Operation> $operations
     */
    public function __construct(
        iterable $operations,
        private readonly DIContainerInterface $container,
        private readonly SchemaCoercer $coercer = new SchemaCoercer(),
        private readonly ConfirmTokenStore $tokens = new ConfirmTokenStore(),
    ) {
        foreach ($operations as $op) {
            if ($op->supportsSurface('http')) {
                $this->operations[$op->name] = $op;
            }
        }
    }

    public function surface(): string
    {
        return 'http';
    }

    public function supports(Operation $op): bool
    {
        return $op->supportsSurface('http');
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->operations as $op) {
            $routes[] = new Route(
                path: $this->pathFor($op),
                methods: $op->mutating ? HttpMethod::POST : HttpMethod::GET,
                name: $op->name,
                handler: new HandlerReference(self::class, 'handle'),
            );
        }

        return $routes;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::ATTRIBUTE);

        $route = null;
        $parameters = [];
        if ($result instanceof RouteResult) {
            $route = $result->route;
            $parameters = $result->parameters;
        }

        $op = $route?->name !== null ? ($this->operations[$route->name] ?? null) : null;

        if ($op === null) {
            return $this->json(404, ['error' => 'operation not found']);
        }

        // Scope enforcement — the Artifact 09 hole closes. An operation with EMPTY scopes skips this
        // entirely and is byte-identical to the pre-auth path below.
        if ($op->scopes !== []) {
            $denied = $this->enforceScopes($op, $request);
            if ($denied !== null) {
                return $denied;
            }
        }

        if ($op->mutating && $op->requiresConfirmation) {
            $token = $request->getHeaderLine('Confirm-Token');
            if ($token === '') {
                return $this->json(428, [
                    'requires_confirmation' => true,
                    'confirm_token' => $this->tokens->issue($op->name),
                ]);
            }
            if (!$this->tokens->consume($token, $op->name)) {
                return $this->json(428, [
                    'requires_confirmation' => true,
                    'error' => 'invalid or expired confirmation token',
                    'confirm_token' => $this->tokens->issue($op->name),
                ]);
            }
        }

        $raw = $parameters;
        foreach ($request->getQueryParams() as $key => $value) {
            $raw[$key] = $value;
        }
        $body = json_decode((string) $request->getBody(), true);
        if (\is_array($body)) {
            foreach ($body as $key => $value) {
                $raw[$key] = $value;
            }
        }

        try {
            $input = $this->coercer->coerce($op->inputSchema ?? [], $raw);
        } catch (SchemaCoercionException $e) {
            return $this->json(422, ['errors' => $e->errors]);
        }

        $handler = $op->handler;
        if (\is_callable($handler)) {
            /** @var mixed $data */
            $data = $handler($input);
        } else {
            [$class, $method] = $handler;
            $instance = $this->container->get($class);
            if (!\is_object($instance)) {
                return $this->json(500, ['error' => "operation '{$op->name}': {$class} did not resolve to an object."]);
            }
            /** @var mixed $data */
            $data = $instance->{$method}($input);
        }

        return $this->json($op->mutating ? 201 : 200, $data);
    }

    /**
     * Enforces `$op`'s declared scopes for one request. Returns `null` when the request is authorized
     * (the caller proceeds to the confirm gate + handler), or a ready-to-send 401/403 JSON response
     * when the milpa/auth scope gate denies it. Throws {@see AuthMiddlewareNotInstalledException}
     * (500) — Rod's binding distinction — when the operation declares scopes but the host wired no
     * auth chain to enforce them: a server misconfiguration, deliberately NOT a 401/403.
     */
    private function enforceScopes(Operation $op, ServerRequestInterface $request): ?ResponseInterface
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
     * Whether the host wired an auth chain able to produce a verified {@see AuthContext} — i.e. a
     * {@see CredentialVerifier} or {@see AuthContextFactory} is resolvable in the container. When
     * neither is, a scope-declaring operation cannot be honestly enforced, which is a host
     * configuration error (500), not a request failure.
     */
    private function authChainInstalled(): bool
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
    private function enforceWebPolicy(Operation $op, ServerRequestInterface $request): ?ResponseInterface
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

    /**
     * Resolves the route path for an operation: an explicit `$op->path` is returned verbatim (the
     * author owns it), otherwise one is derived from `$op->name` (`_` -> `-`, `:` -> `/`) and
     * validated against the Router's grammar before being handed back.
     */
    private function pathFor(Operation $op): string
    {
        if ($op->path !== null) {
            return $op->path;
        }
        $segments = array_map(
            static fn (string $s): string => str_replace('_', '-', $s),
            explode(':', $op->name),
        );

        $this->assertValidDerivedSegments($op->name, $segments);

        return '/' . implode('/', $segments);
    }

    /**
     * Guards a DERIVED path (never an explicit `$op->path`) against degenerating into something
     * the Router's grammar cannot express: a segment must be non-empty and free of `{`/`}` (which
     * would accidentally form — or half-form — a `{placeholder}`). A name that starts/ends with
     * `:`, or contains `::`, yields an empty segment (`//` or a trailing `/`); a name containing
     * `{` or `}` risks synthesizing an unintended path parameter. Fails loudly at route-synthesis
     * time (boot) rather than silently registering a broken route.
     *
     * @param list<string> $segments
     */
    private function assertValidDerivedSegments(string $name, array $segments): void
    {
        foreach ($segments as $segment) {
            if ($segment === '' || str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new \InvalidArgumentException(
                    "Operation '{$name}' derives an invalid HTTP path segment from its name "
                    . "(derived path: '/" . implode('/', $segments) . "'). "
                    . 'Set an explicit path: on the Operation instead of relying on derivation.'
                );
            }
        }
    }

    private function json(int $status, mixed $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
}
