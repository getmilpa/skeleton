<?php

declare(strict_types=1);

namespace App\Tests\Boot;

use App\Plugins\HelloPlugin\HelloPlugin;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton's own boot smoke test: the exact thing `composer create-project` + `php -S` +
 * `curl /` proves manually, pinned as a real assertion — the kernel boots from
 * `config/plugins.php`'s configured plugin list, `GET /` dispatches to `HomeController::index()`,
 * and the `config/app.php` greeting reaches the page through `Milpa\Runtime\Config`, zero database.
 */
final class KernelBootTest extends TestCase
{
    public function testTheKernelBootsWithTheConfiguredPluginList(): void
    {
        $kernel = Kernel::boot($this->bootConfig());

        /** @var list<class-string> $configuredPlugins */
        $configuredPlugins = $this->bootConfig()['plugins'];

        $this->assertContains(HelloPlugin::class, $configuredPlugins);
        $this->assertSame($configuredPlugins, \array_map(static fn (object $p): string => $p::class, $kernel->plugins()));
        $this->assertContains('HelloPlugin', $kernel->bootedPluginNames());
    }

    public function testGetSlashDispatchesToTheHomeControllerAndReturns200(): void
    {
        $kernel = Kernel::boot($this->bootConfig());
        $handler = new RequestHandler($kernel, new Psr17Factory());

        $response = $handler->handle(new ServerRequest('GET', '/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Milpa is running', (string) $response->getBody());
    }

    public function testTheConfigBagGreetingReachesThePage(): void
    {
        $root = \dirname(__DIR__, 2);
        /** @var array<string, mixed> $appConfig */
        $appConfig = require $root . '/config/app.php';
        $greeting = (new \Milpa\Runtime\Config($appConfig))->get('app.greeting');
        $this->assertIsString($greeting);

        $kernel = Kernel::boot($this->bootConfig());
        $handler = new RequestHandler($kernel, new Psr17Factory());

        $response = $handler->handle(new ServerRequest('GET', '/'));

        // The greeting the page renders is the one HelloPlugin::boot() read from config/app.php,
        // not a hard-coded string — proof the Config seam is wired end to end.
        $this->assertStringContainsString('<h1>' . $greeting . '</h1>', (string) $response->getBody());
    }

    public function testAnUnmatchedPathReturns404(): void
    {
        $kernel = Kernel::boot($this->bootConfig());
        $handler = new RequestHandler($kernel, new Psr17Factory());

        $response = $handler->handle(new ServerRequest('GET', '/does-not-exist'));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Loads the same plugin list and config bag `public/index.php` and `bin/coa` boot with.
     *
     * @return array{root: string, plugins: list<class-string>, config: array<string, mixed>}
     */
    private function bootConfig(): array
    {
        $root = \dirname(__DIR__, 2);
        /** @var list<class-string> $plugins */
        $plugins = require $root . '/config/plugins.php';
        /** @var array<string, mixed> $config */
        $config = require $root . '/config/app.php';

        return ['root' => $root, 'plugins' => $plugins, 'config' => $config];
    }
}
