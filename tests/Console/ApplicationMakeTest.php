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

namespace App\Tests\Console;

use App\Console\Application;
use PHPUnit\Framework\TestCase;

final class ApplicationMakeTest extends TestCase
{
    private string $root;

    private string $output = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/milpa-skeleton-application-make-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/src/Plugins/HarnessPlugin', 0777, true);

        file_put_contents($this->root . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($this->root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
        file_put_contents($this->root . '/config/plugins.php', <<<'PHP'
<?php

declare(strict_types=1);

use App\Plugins\HarnessPlugin\HarnessPlugin;

return [
    HarnessPlugin::class,
];
PHP);
        file_put_contents($this->root . '/src/Plugins/HarnessPlugin/HarnessPlugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Plugins\HarnessPlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;

#[PluginMetadata(
    version: '1.0.0',
    author: 'Milpa Skeleton Test Harness',
    site: 'https://github.com/getmilpa/skeleton',
    name: 'HarnessPlugin',
    type: 'Service',
)]
final class HarnessPlugin implements PluginInterface
{
    public function __construct(DIContainerInterface $container)
    {
    }

    public function boot(): void
    {
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
PHP);
        mkdir($this->root . '/vendor', 0777, true);
        $autoload = <<<'PHP'
<?php
require '%s/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = '%s/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
PHP;
        file_put_contents(
            $this->root . '/vendor/autoload.php',
            sprintf($autoload, dirname(__DIR__, 2), $this->root),
        );
        $root = $this->root;
        spl_autoload_register(static function (string $class) use ($root): void {
            $prefix = 'App\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function testHarnessProvidesItsOwnPluginFixture(): void
    {
        $plugin = $this->root . '/src/Plugins/HarnessPlugin/HarnessPlugin.php';

        $this->assertFileExists($plugin);
        $this->assertStringContainsString('final class HarnessPlugin', (string) file_get_contents($plugin));
    }

    public function testWowShowsTheFirstFiveMinutesPathWithoutMutatingTheApp(): void
    {
        $before = file_get_contents($this->root . '/config/plugins.php');

        $exit = $this->runCoa('wow');

        $this->assertSame(0, $exit);
        $output = $this->lastOutput();
        $this->assertStringContainsString('milpa · coa wow — the first five minutes', $output);
        $this->assertStringContainsString('create → inspect → extend → validate → expose to agents', $output);
        $this->assertStringContainsString('php bin/coa make:controller DemoPlugin DemoController --path=/demo --register', $output);
        $this->assertStringContainsString('php bin/coa agent:enable', $output);
        $this->assertSame($before, file_get_contents($this->root . '/config/plugins.php'));
    }

    public function testMakeControllerCanRegisterTheGeneratedPluginAndKeepContainerUseful(): void
    {
        $exit = $this->runCoa('make:controller', 'ReviewPlugin', 'ReviewController', '--path=/review', '--register');

        $this->assertSame(0, $exit);
        $plugins = file_get_contents($this->root . '/config/plugins.php');
        $this->assertIsString($plugins);
        $this->assertStringContainsString('use App\\Plugins\\ReviewPlugin\\ReviewPlugin;', $plugins);
        $this->assertStringContainsString('ReviewPlugin::class,', $plugins);

        $controllerPath = $this->root . '/src/Plugins/ReviewPlugin/Controllers/ReviewController.php';
        $pluginPath = $this->root . '/src/Plugins/ReviewPlugin/ReviewPlugin.php';
        $controller = file_get_contents($controllerPath);
        $plugin = file_get_contents($pluginPath);
        $this->assertIsString($controller);
        $this->assertIsString($plugin);
        $this->assertPhpFileLints($controllerPath);
        $this->assertPhpFileLints($pluginPath);
        $this->assertStringNotContainsString('DIContainerInterface', $controller);
        $this->assertStringNotContainsString('private readonly DIContainerInterface $container', $controller);
        $this->assertStringContainsString('private readonly DIContainerInterface $container', $plugin);
        $this->assertStringContainsString('registerService(ReviewController::class, new ReviewController())', $plugin);
        $this->assertStringContainsString('boot() registers the generated controller', $plugin);
    }

    public function testMakeEntityCanWireRepositoryIntoAnExistingMarkedPluginWhenExplicitlyRequested(): void
    {
        $this->runCoa('make:controller', 'ReviewPlugin', 'ReviewController', '--path=/review', '--register');

        $exit = $this->runCoa('make:entity', 'ReviewPlugin', 'Note', '--fields=title:string:120,done:bool', '--wire');

        $this->assertSame(0, $exit);
        $pluginPath = $this->root . '/src/Plugins/ReviewPlugin/ReviewPlugin.php';
        $plugin = file_get_contents($pluginPath);
        $this->assertIsString($plugin);
        $this->assertPhpFileLints($pluginPath);
        $this->assertStringContainsString('RepositoryFactory::fromConfig($storage, \\App\\Plugins\\ReviewPlugin\\Entities\\Note::class)', $plugin);
        $this->assertStringContainsString('\\App\\Plugins\\ReviewPlugin\\Entities\\Note::class . \'Repository\'', $plugin);

        $kernel = $this->bootTempKernel();
        $repositoryId = \App\Plugins\ReviewPlugin\Entities\Note::class . 'Repository';
        $this->assertTrue($kernel->container()->has($repositoryId));
        $this->assertInstanceOf(\Milpa\Data\RepositoryInterface::class, $kernel->container()->get($repositoryId));
    }

    public function testMakeCrudPrintsOneCleanNewPluginRegistrationGuidance(): void
    {
        $this->runCoa('make:crud', 'TaskPlugin', 'Task', '--fields=title:string:200,status:string:20');

        $output = $this->lastOutput();
        $this->assertStringContainsString('New plugin — register it so the kernel boots it: add App\\Plugins\\TaskPlugin\\TaskPlugin::class', $output);
        $this->assertStringNotContainsString('Entity/repository wiring (from make:entity', $output);
        $this->assertStringNotContainsString('Controller/route wiring:', $output);
    }

    private function bootTempKernel(): \Milpa\Runtime\Kernel
    {
        /** @var list<class-string> $plugins */
        $plugins = require $this->root . '/config/plugins.php';
        /** @var array<string, mixed> $config */
        $config = require $this->root . '/config/app.php';

        return \Milpa\Runtime\Kernel::boot(['root' => $this->root, 'plugins' => $plugins, 'config' => $config]);
    }

    private function assertPhpFileLints(string $path): void
    {
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($path), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    private function runCoa(string ...$args): int
    {
        ob_start();
        $exit = (new Application($this->root))->run(array_merge(['coa'], $args));
        $this->output = (string) ob_get_clean();

        return $exit;
    }

    private function lastOutput(): string
    {
        return $this->output;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removeTree($path . DIRECTORY_SEPARATOR . $item);
        }

        rmdir($path);
    }
}
