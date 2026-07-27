<?php

declare(strict_types=1);

namespace App\Tests\Boot;

use Milpa\Plugin\Activation\ActivePlugins;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Operations\PluginManagementPlugin;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Switching a plugin off has to actually stop it — end to end, through the same `config/boot.php`
 * every entry point loads.
 *
 * The unit tests in `milpa/plugin` prove the decision; what is proven here is that this app's
 * wiring reaches it. The two can drift apart in exactly one way — an entry point that assembles
 * the container and the plugin list separately — and that is the drift this file catches.
 */
final class PluginActivationTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = \dirname(__DIR__, 2) . '/storage/plugins.json';
        // A template ships without state; a leftover file from a previous run would
        // decide this test's outcome before it started.
        @unlink($this->statePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        parent::tearDown();
    }

    /**
     * @return array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>}
     */
    private function boot(): array
    {
        /** @var array{container: \Milpa\Interfaces\Di\DIContainerInterface, plugins: list<class-string>} $boot */
        $boot = require \dirname(__DIR__, 2) . '/config/boot.php';

        return $boot;
    }

    public function testWithNoStateFileEverythingDeclaredBoots(): void
    {
        $boot = $this->boot();

        self::assertSame(require \dirname(__DIR__, 2) . '/config/plugins.php', $boot['plugins']);
        self::assertFileDoesNotExist($this->statePath, 'An app that manages nothing must not grow a state file.');
    }

    public function testTheActivationStoreIsInTheContainerBeforeThefirstPluginIsBuilt(): void
    {
        // PluginManagement reads it in operations(); a container without it
        // fails the boot loudly rather than serving an empty menu.
        self::assertTrue($this->boot()['container']->has(PluginRegistryInterface::class));
    }

    public function testSwitchingAPluginOffThroughTheOperationStopsItBootingNextTime(): void
    {
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'plugins' => $this->boot()['plugins'],
            'config' => require \dirname(__DIR__, 2) . '/config/app.php',
            'container' => $this->boot()['container'],
        ]);
        self::assertContains('HelloPlugin', $kernel->bootedPluginNames());

        $disable = null;
        foreach ($kernel->commands() as $command) {
            if ($command->name === 'plugins.disable') {
                $disable = $command;
            }
        }
        self::assertNotNull($disable, 'PluginManagement contributes it through the CommandProvider seam.');

        ($disable->handler)(['name' => 'HelloPlugin']);

        // A second, independent resolution — the next request, in effect.
        self::assertNotContains(
            \App\Plugins\HelloPlugin\HelloPlugin::class,
            ActivePlugins::from(require \dirname(__DIR__, 2) . '/config/plugins.php', $this->statePath),
        );
    }

    public function testTheManagementPluginItselfIsJustAnotherLineInTheList(): void
    {
        // Nothing about it is special-cased into the framework: delete the line
        // and this app goes back to being governed by the list alone.
        /** @var list<class-string> $declared */
        $declared = require \dirname(__DIR__, 2) . '/config/plugins.php';

        self::assertContains(PluginManagementPlugin::class, $declared);
    }
}
