<?php

declare(strict_types=1);

namespace App\Console;

use Milpa\Container\DIContainer;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\Generators\ControllerGenerator;
use Milpa\DevTools\Make\Generators\EntityGenerator;
use Milpa\DevTools\Make\VerifyRunner;
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
 * The skeleton's minimal `coa` CLI: four subcommands wired directly to `milpa/runtime`'s
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
 *   scaffold a controller that BOOTS here. devtools auto-detects the convention per app root
 *   ({@see \Milpa\DevTools\Make\ConventionDetector}): this skeleton is a `milpa/runtime` app
 *   (`config/plugins.php`, `App\` PSR-4, no `milpa.json`), so it picks the RUNTIME flavor with no
 *   flag — a plain PSR-7 controller (`index(ServerRequestInterface): ResponseInterface`, no
 *   `BaseController`, no `#[Route]`), and because an orphaned controller boots nothing, ALSO a
 *   minimal {@see RouteProviderInterface} plugin wiring `GET <path> → Controller::index` (or, if
 *   that plugin area already exists, the exact route snippet to hand-add). Register the generated
 *   plugin in `config/plugins.php` — the printed
 *   {@see \Milpa\DevTools\Make\GenerationResult::$guidance} says exactly that — and it serves a
 *   real response (proven by `php -S` + `curl`, and by this skeleton's own boot smoke test).
 *   `--flavor=legacy` forces the old host convention; `--path=/route` sets the route.
 * - `make:entity`     wires `milpa/devtools`' {@see EntityGenerator} + {@see WriteGuard} to
 *   scaffold a domain model that PERSISTS here. Same auto-detection as `make:controller`: this
 *   skeleton is a `milpa/runtime` app, so devtools picks the RUNTIME flavor with no flag — a plain
 *   `final readonly class implements Milpa\Data\EntityInterface` (`id()`/`toArray()`/`fromArray()`,
 *   no Doctrine) under `src/Plugins/<Plugin>/Entities/`, and because an orphaned entity persists
 *   nothing, ALSO a minimal {@see \Milpa\Interfaces\Plugin\PluginInterface} plugin whose `boot()`
 *   registers a `Milpa\Data\FileRepository` for it (JSON at `var/<table>.json`) under the DI key
 *   `<Entity>::class . 'Repository'` — or, if that plugin area already exists, the exact `boot()`
 *   snippet to hand-add. Register the generated plugin in `config/plugins.php` (the printed
 *   {@see \Milpa\DevTools\Make\GenerationResult::$guidance} says exactly that) and a consumer
 *   resolves the repository via `$container->get(<Entity>::class . 'Repository')`, `save()`s an
 *   entity, and a fresh process rereads it from the JSON file — zero Doctrine, zero DB. The result
 *   is then run through {@see VerifyRunner} to certify the produced class satisfies the runtime
 *   entity convention (`--fields="name:type[:mods],..."` DSL; `--table=` names the file;
 *   `--flavor=legacy` forces the Doctrine convention).
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
            'make:entity' => $this->makeEntity($args),
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
            $this->line('usage: coa make:controller <PluginName> <ControllerName> [--path=/route] [--flavor=runtime|legacy] [--force]');

            return 1;
        }

        [$plugin, $name] = $args;
        $options = $this->parseOptions(\array_slice($args, 2));
        $force = isset($options['force']);

        // devtools picks the convention for THIS root on its own: the skeleton has config/plugins.php
        // and an App\ PSR-4 root with no milpa.json, so ConventionDetector selects the RUNTIME flavor
        // — a plain PSR-7 controller plus a booting RouteProviderInterface plugin — with no --flavor.
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

        // The generator hands back the one manual step it cannot do deterministically — registering a
        // freshly generated plugin in config/plugins.php, or the route snippet to add to an existing
        // one. Surface it verbatim instead of the old "won't boot here" warning: the code boots now.
        //
        // devtools' VerifyRunner/ControllerVerifier is intentionally NOT wired in here: its runtime
        // path calls ReflectionClass::isSubclassOf('Milpa\app\Providers\BaseController'), which THROWS
        // in a genuine milpa/runtime app because that legacy base class is (correctly) absent. Wiring
        // it would crash every scaffold. The generated controller's shape is instead proven where it
        // matters — by booting it (php -S + curl, and tests/Boot) — not by in-process reflection.
        if ($result->guidance !== null) {
            $this->line('');
            $this->line($result->guidance);
        }

        return 0;
    }

    /** @param list<string> $args */
    private function makeEntity(array $args): int
    {
        if (\count($args) < 2) {
            $this->line('usage: coa make:entity <PluginName> <EntityName> [--fields="name:type[:mods],..."] [--table=name] [--flavor=runtime|legacy] [--force]');

            return 1;
        }

        [$plugin, $name] = $args;
        $options = $this->parseOptions(\array_slice($args, 2));
        $force = isset($options['force']);

        // Same auto-detection as make:controller: the skeleton has config/plugins.php and an App\
        // PSR-4 root with no milpa.json, so ConventionDetector selects the RUNTIME flavor with no
        // --flavor — a plain Milpa\Data\EntityInterface entity plus a booting plugin that registers
        // a Milpa\Data\FileRepository for it. --flavor=legacy forces the Doctrine convention.
        $context = new GenerationContext(plugin: $plugin, name: $name, options: $options, root: $this->root);
        $result = (new EntityGenerator())->generate($context);

        $guard = new WriteGuard();
        foreach ($result->files as $file) {
            $guard->assertWritable($file->path, $force);
        }
        foreach ($result->files as $file) {
            $guard->write($file->path, $file->contents);
            $this->line("✔ wrote {$file->path}");
        }

        // The one manual step the generator cannot do deterministically — registering a freshly
        // generated plugin in config/plugins.php, or the FileRepository snippet to add to an
        // existing one. Surface it verbatim so the scaffolded entity actually reaches persistence.
        if ($result->guidance !== null) {
            $this->line('');
            $this->line($result->guidance);
        }

        // Unlike make:controller, the verify step IS wired here: EntityVerifier::verifyRuntime()
        // only reflects the generated entity itself + checks Milpa\Data\EntityInterface (a hard
        // dependency, always loadable) — it never touches a host-specific base class, so there is no
        // "throws when BaseController is absent" landmine to dodge. Certify the produced class
        // satisfies the runtime entity convention in the same request that just wrote it.
        if ($result->verifyKind !== null && $result->verifyTarget !== null) {
            $verify = (new VerifyRunner())->run($result->verifyKind, $result->verifyTarget, $this->root, $result->flavor);
            $this->line('');
            $this->line($verify['output']);
            if (!$verify['ok']) {
                return 1;
            }
        }

        return 0;
    }

    private function help(): int
    {
        $this->line('milpa · coa — the skeleton\'s minimal CLI');
        $this->line('');
        $this->line('  coa doctor                                    boot the kernel, report what came up');
        $this->line('  coa validate                                  static pre-boot capability check (no boot())');
        $this->line('  coa make:controller <Plugin> <Name> [opts]     scaffold a booting PSR-7 controller + route');
        $this->line('  coa make:entity <Plugin> <Name> [opts]         scaffold a persisting entity + FileRepository');
        $this->line('');
        $this->line('  opts for make:controller: --path=/route  --flavor=runtime|legacy  --force');
        $this->line('  opts for make:entity:     --fields="name:type[:mods],..."  --table=name  --flavor=runtime|legacy  --force');

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
