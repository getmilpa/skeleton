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

abstract class ApplicationTestCase extends TestCase
{
    protected string $root;

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
    protected function runCoa(string ...$args): int
    {
        ob_start();
        $exit = (new Application($this->root))->run(array_merge(['coa'], $args));
        $this->output = (string) ob_get_clean();

        return $exit;
    }

    protected function lastOutput(): string
    {
        return $this->output;
    }

    protected function removeTree(string $path): void
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
