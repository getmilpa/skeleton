<?php

declare(strict_types=1);

namespace App\Plugins\HelloPlugin;

use App\Plugins\HelloPlugin\Controllers\HomeController;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * The skeleton's proof-of-life plugin: provides nothing, requires nothing, and contributes one
 * route (`GET /`) so `composer create-project` followed by `php -S localhost:8000 -t public`
 * shows something alive immediately — zero database, zero configuration beyond this file and
 * `config/plugins.php`.
 *
 * Copy this plugin's shape (metadata + boot + {@see RouteProviderInterface::routes()}) as the
 * starting point for your own — see the README's "Add a plugin" section.
 */
#[PluginMetadata(
    version: '0.1.0',
    author: 'Your Name',
    site: 'https://example.com',
    name: 'HelloPlugin',
    type: 'Web',
)]
final class HelloPlugin implements PluginInterface, RouteProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
        // The container is kept as a promoted readonly property because this plugin reads the
        // app-config bag in boot() (see below). PluginInterface fixes the constructor signature
        // to ($container), so this is the ONLY thing a plugin gets injected — never config values
        // directly. Everything else it needs, it resolves from the container.
    }

    public function boot(): void
    {
        // The Config idiom: read app configuration here in boot() via the container, NOT through
        // a constructor argument or an env var. `config/app.php` is registered by Kernel::boot()
        // as Milpa\Runtime\Config; dot-notation walks the nested array.
        // The default is deliberately DISTINCT from config/app.php's value: if you ever see it on
        // the page, the config bag was empty (e.g. config/app.php missing), which is a useful tell.
        $greeting = $this->container->get(Config::class)->get('app.greeting', 'Milpa is running (default greeting).');
        \assert(\is_string($greeting));

        // Wire the value into the collaborator that renders it. The controller is resolved from
        // the container at request time, so registering the built instance here is what makes the
        // config value reach the page — the plugin owns that wiring, the controller stays dumb.
        $this->container->registerService(HomeController::class, new HomeController($greeting));
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

    /** @return list<Route> */
    public function routes(): array
    {
        return [
            new Route(
                path: '/',
                methods: HttpMethod::GET,
                name: 'home',
                handler: new HandlerReference(HomeController::class, 'index'),
            ),
        ];
    }
}
