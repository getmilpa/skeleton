<?php

declare(strict_types=1);

namespace App\Tests\Greenhouse;

use App\Command\HttpProjector;
use Milpa\Attributes\PluginMetadata;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * The greenhouse host plugin: declares the create_post operation ONCE (operations()) and projects it
 * to HTTP by delegating routes() to a HttpProjector it registers in the container. The same
 * operation reaches CLI (kernel->commands() -> CliProjector) and MCP (kernel->commands() ->
 * McpProjector) with no additional declaration.
 */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Your Name',
    site: 'https://example.com',
    name: 'GreenhousePlugin',
    type: 'Web',
)]
final class GreenhousePlugin implements PluginInterface, CommandProvider, RouteProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** @return list<Operation> */
    public function operations(): array
    {
        return [$this->createPost()];
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->container->get(HttpProjector::class)->routes();
    }

    public function boot(): void
    {
        $service = new PostService();
        $this->container->registerService(PostService::class, $service);
        $this->container->registerService(
            HttpProjector::class,
            new HttpProjector($this->operations(), $this->container),
        );
    }

    private function createPost(): Operation
    {
        return new Operation(
            name: 'create_post',
            description: 'Create a post',
            handler: [PostService::class, 'create'],
            inputSchema: ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ], 'required' => ['title', 'body']],
            mutating: true,
            requiresConfirmation: true,
            scopes: ['posts:write'],
            path: '/posts',
        );
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}
