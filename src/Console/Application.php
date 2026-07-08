<?php

declare(strict_types=1);

namespace App\Console;

use Milpa\Container\DIContainer;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\ControllerGenerator;
use Milpa\DevTools\Make\WriteGuard;
use Milpa\Exceptions\AttributeNotFoundException;
use Milpa\Exceptions\Plugin\PluginDependencyException;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Support\RootNotFoundException;
use Milpa\Services\CapabilityGraphChecker;

/**
 * The skeleton's minimal `coa` CLI: three subcommands wired directly to `milpa/runtime`'s
 * {@see Kernel} and `milpa/devtools`' generate/inspect layers. No Symfony Console, no command
 * bus — just enough `argv` dispatch to prove the wiring and stay genuinely useful in a
 * zero-database app.
 *
 * - `doctor`          boots the REAL kernel ({@see Kernel::boot()}) with `config/plugins.php`
 *   and the `config/app.php` config bag, then reports what actually came up: root, collaborators,
 *   booted plugin names, declared routes, and the `app.greeting` value read back out of the
 *   container's {@see Config}. The live "does this app start" check — every configured plugin's `boot()` runs.
 * - `validate`        runs `milpa/core`'s {@see CapabilityGraphChecker} — the exact checker
 *   `Kernel::boot()` calls internally — over the *instantiated but unbooted* configured
 *   plugins. A static, side-effect-free pre-boot certification (`boot()` never runs).
 * - `make:controller` wires `milpa/devtools`' {@see ControllerGenerator} + {@see WriteGuard} to
 *   scaffold a controller file. Its output targets the LEGACY host convention documented in
 *   devtools' own README (`Milpa\Plugins\*\Controllers`, extends
 *   `Milpa\app\Providers\BaseController`) — not this skeleton's `App\Plugins\*` +
 *   {@see RouteProviderInterface} pattern. The command prints that mismatch explicitly rather
 *   than silently handing back a file that won't boot here (see the README's "Add a plugin"
 *   section and this front's SDD report).
 *
 * `milpa/devtools`' manifest-file-oriented Inspect layer (`CapabilityGraphValidator`,
 * `PluginManifestValidator`, `ProviderImplementsValidator`) expects `milpa.json` files on disk —
 * a good fit for the legacy host's plugin-directory discovery model, but this skeleton's plugin
 * list is a config array, not a manifest directory (see `config/plugins.php`). `validate` below
 * therefore reaches for `milpa/core`'s `CapabilityGraphChecker` directly instead.
 */
final class Application
{
    public function __construct(private readonly string $root)
    {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'list';
        $args = \array_slice($argv, 2);

        return match ($command) {
            'doctor' => $this->doctor(),
            'validate' => $this->validate(),
            'make:controller' => $this->makeController($args),
            default => $this->help(),
        };
    }

    private function doctor(): int
    {
        $this->line('milpa · coa doctor');
        $this->line("root: {$this->root}");

        $plugins = $this->loadPluginList();
        if ($plugins === null) {
            return 1;
        }

        $config = $this->loadConfig();

        try {
            $kernel = Kernel::boot(['root' => $this->root, 'plugins' => $plugins, 'config' => $config]);
        } catch (AttributeNotFoundException|PluginDependencyException|RootNotFoundException $e) {
            $this->line('✗ boot failed: ' . $e->getMessage());

            return 1;
        }

        $booted = $kernel->bootedPluginNames();
        $this->line(\sprintf(
            '✔ %d plugin(s) configured, %d booted: %s',
            \count($kernel->plugins()),
            \count($booted),
            $booted === [] ? '(none)' : implode(', ', $booted),
        ));
        $this->line(\sprintf('✔ container: %s', $kernel->container()::class));
        $this->line(\sprintf('✔ dispatcher: %s', $kernel->dispatcher()::class));

        $routeCount = 0;
        foreach ($kernel->plugins() as $plugin) {
            if ($plugin instanceof RouteProviderInterface) {
                $routeCount += \count($plugin->routes());
            }
        }
        $this->line(\sprintf('✔ %d route(s) declared (RouteProviderInterface plugins)', $routeCount));

        $configBag = $kernel->container()->get(Config::class);
        if ($configBag instanceof Config) {
            $this->line(\sprintf('✔ config: app.greeting = %s', var_export($configBag->get('app.greeting'), true)));
        }
        $this->line('✔ kernel booted — zero database queries.');

        return 0;
    }

    private function validate(): int
    {
        $this->line('milpa · coa validate — static pre-boot capability check (boot() never runs)');

        $plugins = $this->loadPluginList();
        if ($plugins === null) {
            return 1;
        }

        $container = new DIContainer();
        $instances = [];
        foreach ($plugins as $class) {
            if (!\class_exists($class)) {
                $this->line("✗ {$class} does not exist.");

                return 1;
            }
            if (!\is_a($class, PluginInterface::class, true)) {
                $this->line('✗ ' . $class . ' does not implement ' . PluginInterface::class . '.');

                return 1;
            }
            $instances[] = new $class($container);
        }

        try {
            (new CapabilityGraphChecker())->check($instances);
        } catch (AttributeNotFoundException|PluginDependencyException $e) {
            $this->line('✗ capability graph: ' . $e->getMessage());

            return 1;
        }

        $this->line(\sprintf(
            '✔ %d plugin(s) instantiate cleanly and satisfy every #[PluginMetadata] requires/provides.',
            \count($instances),
        ));

        return 0;
    }

    /** @param list<string> $args */
    private function makeController(array $args): int
    {
        if (\count($args) < 2) {
            $this->line('usage: coa make:controller <PluginName> <ControllerName> [--methods=index] [--route=/path] [--force]');

            return 1;
        }

        [$plugin, $name] = $args;
        $options = $this->parseOptions(\array_slice($args, 2));
        $force = isset($options['force']);

        $context = new GenerationContext(plugin: $plugin, name: $name, options: $options, root: $this->root);
        $result = (new ControllerGenerator())->generate($context);

        $guard = new WriteGuard();
        foreach ($result->files as $file) {
            $guard->assertWritable($file->path, $force);
        }
        foreach ($result->files as $file) {
            $guard->write($file->path, $file->contents);
            $this->line("✔ wrote {$file->path}");
        }

        $this->line('');
        $this->line('⚠ this file targets the LEGACY Milpa host convention (namespace Milpa\\Plugins\\...,');
        $this->line('  extends Milpa\\app\\Providers\\BaseController) that milpa/devtools\' ControllerGenerator');
        $this->line('  was built for — NOT this skeleton\'s App\\Plugins\\* + RouteProviderInterface pattern.');
        $this->line('  Treat the output as a reference: adapt the namespace, drop the BaseController parent,');
        $this->line('  and wire a Route with a HandlerReference by hand. See the README\'s "Add a plugin".');

        return 0;
    }

    private function help(): int
    {
        $this->line('milpa · coa — the skeleton\'s minimal CLI');
        $this->line('');
        $this->line('  coa doctor                                    boot the kernel, report what came up');
        $this->line('  coa validate                                  static pre-boot capability check (no boot())');
        $this->line('  coa make:controller <Plugin> <Name> [opts]     scaffold a controller via milpa/devtools');
        $this->line('');
        $this->line('  opts for make:controller: --methods=index,show  --route=/path  --force');

        return 0;
    }

    /** @return list<class-string>|null */
    private function loadPluginList(): ?array
    {
        $path = $this->root . '/config/plugins.php';
        if (!\is_file($path)) {
            $this->line("✗ {$path} not found.");

            return null;
        }

        $plugins = require $path;
        if (!\is_array($plugins)) {
            $this->line("✗ {$path} must return a list<class-string>.");

            return null;
        }

        /** @var list<class-string> $plugins */
        return $plugins;
    }

    /**
     * Loads the app-config bag `Kernel::boot()` registers as `Milpa\Runtime\Config` — the seam
     * plugins read in `boot()` instead of taking constructor args. Missing file is not fatal: an
     * empty bag boots fine, plugins just fall back to their defaults.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $path = $this->root . '/config/app.php';
        if (!\is_file($path)) {
            return [];
        }

        $config = require $path;
        if (!\is_array($config)) {
            return [];
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array<string, string>
     */
    private function parseOptions(array $tokens): array
    {
        $options = [];
        foreach ($tokens as $token) {
            if (!\str_starts_with($token, '--')) {
                continue;
            }
            $body = \substr($token, 2);
            [$key, $value] = \str_contains($body, '=') ? \explode('=', $body, 2) : [$body, '1'];
            $options[$key] = $value;
        }

        return $options;
    }

    private function line(string $message): void
    {
        echo $message . \PHP_EOL;
    }
}
