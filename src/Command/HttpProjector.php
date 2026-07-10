<?php

declare(strict_types=1);

namespace App\Command;

use Milpa\Command\Operation;
use Milpa\Command\SurfaceProjector;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Projects Operations to the HTTP surface. It is BOTH a route source — `routes()` synthesizes one
 * bound Route per operation (path declared or derived from the name; verb from `mutating`) — AND the
 * generic adapter controller those routes point at: `handle()` looks the operation up from the
 * matched RouteResult, applies the two-step confirm gate for mutating operations, merges path +
 * query + body into one input bag, coerces/validates it, invokes the operation's domain handler,
 * and serializes the returned domain data to JSON. A host plugin registers one instance under
 * HttpProjector::class and returns `routes()` from its own RouteProviderInterface::routes().
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
