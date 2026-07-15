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

namespace App\Console;

use App\Command\CliProjector;
use App\Command\McpProjector;
use Milpa\Attributes\PluginMetadata;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\DevTools\Make\GenerationContext;
use Milpa\DevTools\Make\GenerationResult;
use Milpa\DevTools\Make\MarkerInserter;
use Milpa\DevTools\Make\Markers;
use Milpa\DevTools\Make\PlannedFile;
use Milpa\DevTools\Make\Generators\ControllerGenerator;
use Milpa\DevTools\Make\Generators\CrudGenerator;
use Milpa\DevTools\Make\Generators\EntityGenerator;
use Milpa\DevTools\Make\Generators\PluginGenerator;
use Milpa\DevTools\Make\Generators\ServiceGenerator;
use Milpa\DevTools\Make\Generators\ToolGenerator;
use Milpa\DevTools\Make\VerifyRunner;
use Milpa\DevTools\Make\WriteGuard;
use Milpa\DevTools\Support\ComposerAutoload;
use Milpa\Exceptions\AttributeNotFoundException;
use Milpa\Exceptions\Plugin\PluginDependencyException;
use Milpa\Http\HttpMethod;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\Runtime\CommandProviderInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Support\RootNotFoundException;
use Milpa\Services\CapabilityGraphChecker;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The skeleton's minimal `coa` CLI: subcommands wired directly to `milpa/runtime`'s {@see Kernel}
 * and `milpa/devtools`' generate/inspect layers. No Symfony Console, no command bus — just enough
 * `argv` dispatch to prove the wiring and stay genuinely useful in a zero-database app.
 *
 * Beyond `doctor`/`validate`/`make:controller`/`make:entity` (documented below), 8 more commands
 * plug into the exact same Make engine and a freshly-booted {@see Kernel}: `make:plugin`,
 * `make:service`, `make:tool`, `make:crud` ({@see \Milpa\DevTools\Make\Generators\PluginGenerator}/
 * {@see \Milpa\DevTools\Make\Generators\ServiceGenerator}/{@see \Milpa\DevTools\Make\Generators\ToolGenerator}/
 * {@see \Milpa\DevTools\Make\Generators\CrudGenerator}, via {@see self::writeAndReport()}) and
 * `inspect:plugins`/`inspect:routes`/`inspect:services`/`inspect:tools` — each boots the real
 * kernel via {@see self::bootKernelForInspect()} and reports what it finds, never fabricated data.
 * See `coa` with no arguments ({@see self::help()}) for the full command list.
 *
 * - `doctor`          boots the REAL kernel ({@see Kernel::boot()}) with `config/plugins.php`
 *   and the `config/app.php` config bag, then reports what actually came up: root, collaborators,
 *   booted plugin names, declared routes, and the `app.greeting` value read back out of the
 *   container's {@see Config}. The live "does this app start" check — every configured plugin's `boot()` runs.
 * - `validate`        runs `milpa/core`'s {@see CapabilityGraphChecker} over the *instantiated
 *   but unbooted* configured plugins — a static, side-effect-free pre-boot certification
 *   (`boot()` never runs). Since runtime 0.4, {@see Kernel::boot()} itself resolves the graph
 *   through `milpa/resolver` instead: a blocked architecture throws the typed
 *   `ArchitectureBlockedException` (a `PluginDependencyException`, so existing catches hold)
 *   carrying the full `ResolutionReport`. `validate` stays the lighter same-contracts preview.
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
 *
 * **Discovered commands (skeleton 0.5, runtime 0.2's `CommandProviderInterface` — the first brick
 * of Command-as-atom, see `docs/library/vision-milpa-commands.md`).** Any argv that does not match
 * a built-in above falls through to {@see self::runDiscoveredCommand()}: it boots the real kernel
 * and looks the name up in {@see Kernel::commands()} — the list every booted
 * `CommandProviderInterface` plugin contributed via its `commands()` method. A plugin can
 * therefore add its own `coa <name>` subcommand with zero edits to this file. `inspect:commands`
 * lists both the built-ins and every discovered command (name, description, source plugin).
 * {@see self::bootKernelForInspect()} also now wires a fresh {@see ToolRegistry} into every
 * `inspect:*`/discovered-command boot by default (friction #2,
 * `docs/superpowers/specs/2026-07-09-frictions-command-discovery.md`) — cheap and side-effect-free,
 * so `inspect:tools` sees whatever a booted `ToolProviderInterface` plugin actually registered
 * instead of always reporting "no tool registry". `bin/mcp-server.php` (new) exposes that same
 * registry shape over MCP stdio via `milpa/mcp-server`'s `JsonRpcService` — no per-app copy needed.
 *
 * **Agent-ready is opt-in (skeleton 0.5.1).** `milpa/tool-runtime` and `milpa/mcp-server` are
 * `suggest`-only in composer.json, not hard dependencies — a stock `composer create-project
 * milpa/skeleton` app is minimal and does not pull the AI/MCP surface. {@see
 * self::bootKernelForInspect()} wires a {@see ToolRegistry} only when `milpa/tool-runtime` is
 * actually installed (a `class_exists()` guard, same pattern `bin/mcp-server.php` uses); when it
 * isn't, `inspect:tools` prints a clean "not enabled" message instead of always reporting an empty
 * registry, and every other `inspect:*`/`doctor` command is entirely unaffected. `coa agent:enable`
 * ({@see self::agentEnable()}) is the opt-in switch: a thin `composer require milpa/tool-runtime
 * milpa/mcp-server` wrapper.
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
            'make:plugin' => $this->makePlugin($args),
            'make:service' => $this->makeService($args),
            'make:tool' => $this->makeTool($args),
            'make:crud' => $this->makeCrud($args),
            'inspect:plugins' => $this->inspectPlugins(),
            'inspect:routes' => $this->inspectRoutes(),
            'inspect:services' => $this->inspectServices(),
            'inspect:tools' => $this->inspectTools(),
            'inspect:commands' => $this->inspectCommands(),
            'agent:enable' => $this->agentEnable(),
            default => $this->runDiscoveredCommand($command, $args),
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
            $this->line('usage: coa make:controller <PluginName> <ControllerName> [--path=/route] [--flavor=runtime|legacy] [--register] [--force]');

            return 1;
        }

        [$plugin, $name] = $args;
        $options = $this->parseOptions(\array_slice($args, 2));
        $force = isset($options['force']);

        // devtools picks the convention for THIS root on its own: the skeleton has config/plugins.php
        // and an App\ PSR-4 root with no milpa.json, so ConventionDetector selects the RUNTIME flavor
        // — a plain PSR-7 controller plus a booting RouteProviderInterface plugin — with no --flavor.
        $context = new GenerationContext(plugin: $plugin, name: $name, options: $options, root: $this->root);
        $result = $this->withoutOnlyWrittenContainerProperty((new ControllerGenerator())->generate($context));

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
        if (isset($options['register']) && $this->registerPlugin($plugin)) {
            $this->line('');
            $this->line('✔ registered ' . $this->pluginFqcn($plugin) . ' in config/plugins.php.');
        }

        return 0;
    }

    /** @param list<string> $args */
    private function makeEntity(array $args): int
    {
        if (\count($args) < 2) {
            $this->line('usage: coa make:entity <PluginName> <EntityName> [--fields="name:type[:mods],..."] [--table=name] [--flavor=runtime|legacy] [--wire] [--register] [--force]');

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
        if (isset($options['wire'])) {
            $this->line('');
            $this->line($this->wireEntityIntoExistingPlugin($plugin, $name, $options, $force));
        } elseif ($result->guidance !== null) {
            $this->line('');
            $this->line($result->guidance);
        }
        if (isset($options['register']) && $this->registerPlugin($plugin)) {
            $this->line('');
            $this->line('✔ registered ' . $this->pluginFqcn($plugin) . ' in config/plugins.php.');
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

    /**
     * @param list<string> $args
     */
    private function makePlugin(array $args): int
    {
        if (\count($args) < 1) {
            $this->line('usage: coa make:plugin <Name> [--provides=cap1,cap2] [--requires=cap3] [--flavor=runtime|legacy] [--force]');

            return 1;
        }

        [$name] = $args;
        $options = $this->parseOptions(\array_slice($args, 1));
        $force = isset($options['force']);

        // make:plugin has a single positional argument — the plugin IS the artifact being
        // generated, so the same value is passed for both GenerationContext::$plugin and $name;
        // PluginGenerator never reads $plugin (see the F1a report's design note on this).
        $context = new GenerationContext(plugin: $name, name: $name, options: $options, root: $this->root);
        $result = (new PluginGenerator())->generate($context);

        return $this->writeAndReport($result, $force);
    }

    /**
     * @param list<string> $args
     */
    private function makeService(array $args): int
    {
        if (\count($args) < 2) {
            $this->line('usage: coa make:service <Plugin> <Name> [--interface] [--flavor=runtime|legacy] [--force]');

            return 1;
        }

        [$plugin, $name] = $args;
        $options = $this->parseOptions(\array_slice($args, 2));
        $force = isset($options['force']);

        $context = new GenerationContext(plugin: $plugin, name: $name, options: $options, root: $this->root);
        $result = (new ServiceGenerator())->generate($context);

        return $this->writeAndReport($result, $force);
    }

    /**
     * @param list<string> $args
     */
    private function makeTool(array $args): int
    {
        if (\count($args) < 2) {
            $this->line('usage: coa make:tool <Plugin> <Name> [--description=text] [--tool-name=snake_name] [--flavor=runtime|legacy] [--force]');

            return 1;
        }

        [$plugin, $name] = $args;
        $options = $this->parseOptions(\array_slice($args, 2));
        $force = isset($options['force']);

        $context = new GenerationContext(plugin: $plugin, name: $name, options: $options, root: $this->root);
        $result = (new ToolGenerator())->generate($context);

        // ToolGenerator::generate() always returns verifyKind: null — no ToolVerifier exists yet
        // (see the F1a/F1b reports' Fricciones) — writeAndReport() skips VerifyRunner cleanly.
        return $this->writeAndReport($result, $force);
    }

    /**
     * @param list<string> $args
     */
    private function makeCrud(array $args): int
    {
        if (\count($args) < 2) {
            $this->line('usage: coa make:crud <Plugin> <Entity> [--fields="name:type[:mods],..."] [--table=name] [--flavor=runtime|legacy] [--register] [--force]');

            return 1;
        }

        [$plugin, $name] = $args;
        $options = $this->parseOptions(\array_slice($args, 2));
        $force = isset($options['force']);

        $context = new GenerationContext(plugin: $plugin, name: $name, options: $options, root: $this->root);
        $result = $this->cleanCrudGuidance((new CrudGenerator())->generate($context));

        // Unlike make:tool, CrudGenerator DOES set verifyKind ('controller', pointed at the
        // generated {Entity}Controller — see its class docblock's Fricciones #1). writeAndReport()
        // therefore runs VerifyRunner for it, same as make:entity does today. This is safe (unlike
        // make:controller's own deliberate VerifyRunner skip): ControllerVerifier::verifyRuntime()
        // only touches the legacy BaseController check behind a class_exists() short-circuit — it
        // never throws when that legacy class is absent, so nothing here re-triggers the landmine
        // makeController()'s docblock warns about.
        $exit = $this->writeAndReport($result, $force);
        if ($exit === 0 && isset($options['register']) && $this->registerPlugin($plugin)) {
            $this->line('');
            $this->line('✔ registered ' . $this->pluginFqcn($plugin) . ' in config/plugins.php.');
        }

        return $exit;
    }

    /**
     * Shared write + guidance + optional-verify tail for the 4 newer make:* commands (plugin/
     * service/tool/crud) — the same three steps makeController()/makeEntity() already run inline.
     * Kept as a private helper here (not retrofitted onto those two) to avoid touching their
     * proven, already-shipped bodies for this change.
     */
    private function writeAndReport(GenerationResult $result, bool $force): int
    {
        $guard = new WriteGuard();
        foreach ($result->files as $file) {
            $guard->assertWritable($file->path, $force);
        }
        foreach ($result->files as $file) {
            $guard->write($file->path, $file->contents);
            $this->line("✔ wrote {$file->path}");
        }

        if ($result->guidance !== null) {
            $this->line('');
            $this->line($result->guidance);
        }

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

    /**
     * @param GenerationResult $result
     */
    /**
     * @param GenerationResult $result
     */
    private function withoutOnlyWrittenContainerProperty(GenerationResult $result): GenerationResult
    {
        $files = array_map(function (PlannedFile $file): PlannedFile {
            if (!str_contains($file->contents, 'private readonly DIContainerInterface $container')) {
                return $file;
            }

            $controllerClass = 'Controller';
            foreach (explode("\n", $file->contents) as $line) {
                if (str_contains($line, '\\Controllers\\')) {
                    $controllerClass = substr(trim($line), strrpos(trim($line), '\\') + 1, -1);
                    break;
                }
            }

            $contents = str_replace(
                '        // {coa:services}',
                "        // Keep the scaffold PHPStan-clean and make later --wire insertions safe: the\n"
                . "        // generated plugin retains its container because boot() actually uses it.\n"
                . "        \$this->container->registerService({$controllerClass}::class, new {$controllerClass}());\n"
                . "        // {coa:services}",
                $file->contents,
            );

            return $contents === $file->contents ? $file : new PlannedFile($file->path, $contents, $file->merge);
        }, $result->files);

        return new GenerationResult(
            files: $files,
            verifyKind: $result->verifyKind,
            verifyTarget: $result->verifyTarget,
            flavor: $result->flavor,
            guidance: $result->guidance,
        );
    }

    private function cleanCrudGuidance(GenerationResult $result): GenerationResult
    {
        $guidance = $result->guidance;
        if ($guidance !== null && str_contains($guidance, 'Controller/route wiring:')) {
            $parts = explode("\n\nController/route wiring:\n", $guidance, 2);
            $guidance = $parts[1] ?? $guidance;
        }

        return new GenerationResult(
            files: $result->files,
            verifyKind: $result->verifyKind,
            verifyTarget: $result->verifyTarget,
            flavor: $result->flavor,
            guidance: $guidance,
        );
    }

    /** @param array<string, string> $options */
    private function wireEntityIntoExistingPlugin(string $plugin, string $entity, array $options, bool $force): string
    {
        [$appNamespace, $appDir] = ComposerAutoload::primaryNamespace($this->root) ?? ['App', 'src'];
        $appDir = trim($appDir, '/');
        $pluginPath = $this->root . '/' . $appDir . '/Plugins/' . $plugin . '/' . $plugin . '.php';
        if (!is_file($pluginPath)) {
            return 'wire skipped — no existing plugin file found; the entity generator already created the repository plugin.';
        }

        $existing = (string) file_get_contents($pluginPath);
        $markers = new MarkerInserter();
        if (!$markers->hasMarker($existing, Markers::SERVICES)) {
            return 'wire skipped — existing plugin has no // {' . Markers::SERVICES . '} marker. I will not rewrite arbitrary PHP; add the printed snippet by hand or add the marker where service registrations belong.';
        }

        $table = $options['table'] ?? strtolower($entity) . 's';
        $entityFqcn = $appNamespace . '\\Plugins\\' . $plugin . '\\Entities\\' . $entity;
        $snippet = "\$storage = \$this->container->get(\\Milpa\\Runtime\\Config::class)->get('storage', [\n"
            . "    'driver' => 'file',\n"
            . "    'path' => (new \\Milpa\\Runtime\\Support\\RootResolver())->resolve() . '/var/{$table}.json',\n"
            . "]);\n"
            . "\\assert(\\is_array(\$storage));\n\n"
            . "\$this->container->registerService(\n"
            . "    \\{$entityFqcn}::class . 'Repository',\n"
            . "    \\Milpa\\Data\\RepositoryFactory::fromConfig(\$storage, \\{$entityFqcn}::class),\n"
            . ');';

        $merged = $markers->insertBefore($existing, Markers::SERVICES, $snippet, $force);
        file_put_contents($pluginPath, $merged);

        return '✔ wired ' . $entityFqcn . ' repository into the existing plugin at // {' . Markers::SERVICES . '}. Honest note: --wire is a convenience splice, not a design review; check whether this plugin is really the right composition boundary.';
    }

    private function registerPlugin(string $plugin): bool
    {
        $path = $this->root . '/config/plugins.php';
        if (!is_file($path)) {
            return false;
        }

        $fqcn = $this->pluginFqcn($plugin);
        $short = $plugin;
        $contents = (string) file_get_contents($path);
        if (str_contains($contents, $short . '::class')) {
            return false;
        }

        $useLine = 'use ' . $fqcn . ';';
        if (!str_contains($contents, $useLine)) {
            $useCount = preg_match_all('/^use .*;$/m', $contents, $matches, PREG_OFFSET_CAPTURE);
            if ($useCount !== false && $useCount > 0) {
                $last = end($matches[0]);
                $pos = $last[1] + strlen($last[0]);
                $contents = substr($contents, 0, $pos) . "\n" . $useLine . substr($contents, $pos);
            } else {
                $contents = preg_replace('/(declare\(strict_types=1\);\n)/', "$1\n" . $useLine . "\n", $contents, 1) ?? $contents;
            }
        }

        $updated = preg_replace('/(return\s*\[\s*\n)/', "$1    {$short}::class,\n", $contents, 1);
        if ($updated === null || $updated === $contents) {
            return false;
        }

        file_put_contents($path, $updated);

        return true;
    }

    private function pluginFqcn(string $plugin): string
    {
        [$appNamespace] = ComposerAutoload::primaryNamespace($this->root) ?? ['App', 'src'];

        return $appNamespace . '\\Plugins\\' . $plugin . '\\' . $plugin;
    }

    /**
     * `coa agent:enable` (skeleton 0.5.1) — the opt-in switch for the agent-ready surface. Deliberately
     * NOT its own reimplementation of dependency resolution: it is a thin, honest wrapper over
     * `composer require milpa/tool-runtime milpa/mcp-server`, shelled out via {@see \proc_open()}
     * with an array command (no shell interpolation) and STDIN/STDOUT/STDERR inherited so Composer's
     * own real-time output streams straight through — the same "just run the real tool" spirit as
     * {@see self::makeController()}'s comment about not reflecting a legacy base class that may not
     * exist. Once those two packages land, `bin/mcp-server.php` and `inspect:tools`
     * ({@see self::bootKernelForInspect()}) pick them up automatically via their own `class_exists()`
     * guards — nothing else here needs to change.
     */
    private function agentEnable(): int
    {
        $this->line('milpa · coa agent:enable — enabling the agent-ready surface (MCP/tools)');
        $this->line('');

        $composer = $this->findComposerBinary();
        if ($composer === null) {
            $this->line('✗ composer executable not found on PATH.');
            $this->line('  Install Composer (https://getcomposer.org), then run:');
            $this->line('    composer require milpa/tool-runtime milpa/mcp-server');

            return 1;
        }

        $command = [$composer, 'require', 'milpa/tool-runtime', 'milpa/mcp-server'];
        $this->line('$ ' . \implode(' ', $command));
        $this->line('');

        $exitCode = $this->runComposerRequire($command);
        $this->line('');

        if ($exitCode !== 0) {
            $this->line("✗ composer require failed (exit {$exitCode}) — agent-ready surface not enabled.");

            return $exitCode;
        }

        $this->line('✔ agent-ready enabled — bin/mcp-server.php now exposes your tools over MCP.');
        $this->line('  Try: php bin/coa inspect:tools');
        $this->line('       php bin/mcp-server.php');

        return 0;
    }

    /**
     * A plain PATH scan for a `composer`/`composer.phar` executable — no shell (`command -v` / `which`)
     * involved, so this has no dependency on a POSIX shell being available, and no reliance on
     * `exec()`'s output-parsing quirks. Returns null (never throws) when nothing is found, so
     * {@see self::agentEnable()} can fail gracefully with actionable guidance instead of a fatal
     * "unable to fork" from `\proc_open()`.
     */
    private function findComposerBinary(): ?string
    {
        $pathEnv = \getenv('PATH');
        if (!\is_string($pathEnv) || $pathEnv === '') {
            return null;
        }

        $isWindows = \DIRECTORY_SEPARATOR === '\\';
        $names = $isWindows ? ['composer.bat', 'composer.exe', 'composer'] : ['composer', 'composer.phar'];

        foreach (\explode(\PATH_SEPARATOR, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = \rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR . $name;
                if (\is_file($candidate) && \is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Runs `$command` (already the full argv, e.g. `[composer, require, milpa/tool-runtime,
     * milpa/mcp-server]`) via `\proc_open()` with this app's root as the working directory — the
     * project whose composer.json/vendor should actually change — and STDIN/STDOUT/STDERR wired
     * straight to this process's own, so Composer's real progress output is visible live instead of
     * being buffered and replayed. Returns the child's real exit code; `\proc_open()` itself
     * returning a non-resource (composer binary somehow unspawnable despite passing the PATH scan
     * above — e.g. permissions changed mid-run) is reported as exit code 1.
     *
     * @param list<string> $command
     */
    private function runComposerRequire(array $command): int
    {
        $descriptors = [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR];

        $process = \proc_open($command, $descriptors, $pipes, $this->root);
        if (!\is_resource($process)) {
            $this->line('✗ failed to start the composer process.');

            return 1;
        }

        return \proc_close($process);
    }

    /**
     * Boots the REAL kernel exactly like doctor() does, for the inspect:* commands and discovered
     * commands below — kept as its own helper (rather than reusing doctor()'s inline code) so
     * doctor()'s own output stays byte-for-byte unchanged. Prints the same "✗ ..." diagnostics
     * doctor() would and returns null on any failure, so every caller fails the same way doctor()
     * does on a broken app.
     *
     * Unlike doctor(), this wires a fresh {@see ToolRegistry} into `Kernel::boot()` (friction #2,
     * `docs/superpowers/specs/2026-07-09-frictions-command-discovery.md`): the greenhouse found
     * `inspect:tools` permanently blind because nothing ever passed a `toolRegistry`, even when a
     * booted `ToolProviderInterface` plugin had tools to register. A registry with nothing
     * registered is cheap and side-effect-free, so this is safe to do unconditionally whenever one
     * is available.
     *
     * "Available" is the key word (skeleton 0.5.1): `milpa/tool-runtime` — the package the concrete
     * {@see ToolRegistry} class lives in — is `suggest`-only, not a hard dependency of this
     * skeleton, so a stock app will NOT have it installed. `\class_exists(ToolRegistry::class)` is
     * the guard: it runs Composer's autoloader, which simply fails to find the class when the
     * package is absent (no fatal), so this only constructs and wires a registry when the package
     * is actually there. `Milpa\Runtime\Kernel::boot()` itself never requires one — `toolRegistry`
     * defaults to `null` (see `Kernel::boot()`'s own docblock) — so every other `inspect:*`/`doctor`
     * boot path is unaffected either way.
     */
    private function bootKernelForInspect(): ?Kernel
    {
        $plugins = $this->loadPluginList();
        if ($plugins === null) {
            return null;
        }

        $config = $this->loadConfig();

        $bootConfig = ['root' => $this->root, 'plugins' => $plugins, 'config' => $config];
        if (\class_exists(ToolRegistry::class)) {
            // Kernel::boot()'s config shape types this offset as core's ToolRegistryInterface —
            // and on the base install (tool-runtime absent) static analysis cannot know the
            // optional package's concrete class implements it, so prove it structurally at
            // runtime: a tool-runtime whose registry stopped implementing core's interface
            // degrades to a no-registry boot (inspect:tools teaches the opt-in) instead of
            // handing the Kernel a config it never sanctioned.
            $registry = new ToolRegistry(new NullLogger());
            if ($registry instanceof ToolRegistryInterface) {
                $bootConfig['toolRegistry'] = $registry;
            }
        }

        try {
            return Kernel::boot($bootConfig);
        } catch (AttributeNotFoundException|PluginDependencyException|RootNotFoundException $e) {
            $this->line('✗ boot failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Reflects `#[PluginMetadata]` off a booted plugin instance — mirrors `Milpa\Runtime\Kernel`'s
     * own private `metadataOf()` exactly. Safe to assume it never throws in practice here: every
     * plugin reaching `Kernel::plugins()` already survived `Kernel::boot()`'s own identical check.
     */
    private function pluginMetadata(object $plugin): PluginMetadata
    {
        $attributes = (new \ReflectionClass($plugin))->getAttributes(PluginMetadata::class);
        if ($attributes === []) {
            throw new AttributeNotFoundException($plugin::class . ' has no #[PluginMetadata] attribute.');
        }

        return $attributes[0]->newInstance();
    }

    /**
     * `inspect:plugins` — every configured plugin's identity + capability graph
     * (provides -> requires -> suggests), read straight off its `#[PluginMetadata]`, plus whether
     * it actually booted (`Kernel::bootedPluginNames()` — a plugin can be configured but vetoed by
     * a `plugin.booting` listener without ever booting).
     */
    private function inspectPlugins(): int
    {
        $kernel = $this->bootKernelForInspect();
        if ($kernel === null) {
            return 1;
        }

        $this->line('milpa · coa inspect:plugins');
        $this->line('');

        $booted = $kernel->bootedPluginNames();
        foreach ($kernel->plugins() as $plugin) {
            $meta = $this->pluginMetadata($plugin);
            $status = \in_array($meta->name, $booted, true)
                ? 'booted'
                : 'not booted (vetoed by a plugin.booting listener)';

            $pluginClass = $plugin::class;
            $this->line("• {$meta->name}  [{$status}]");
            $this->line("    class:     {$pluginClass}");
            $this->line("    type:      {$meta->type}");
            $this->line("    version:   {$meta->version}");
            $this->line('    provides:  ' . ($meta->provides === [] ? '(none)' : implode(', ', $meta->provides)));
            $this->line('    requires:  ' . ($meta->requires === [] ? '(none)' : implode(', ', $meta->requires)));
            $this->line('    suggests:  ' . ($meta->suggests === [] ? '(none)' : implode(', ', $meta->suggests)));
            $this->line('');
        }
        $this->line(\sprintf('%d plugin(s) configured, %d booted.', \count($kernel->plugins()), \count($booted)));

        return 0;
    }

    /**
     * `inspect:routes` — the route table, reconstructed from every BOOTED `RouteProviderInterface`
     * plugin's `routes()`. `Milpa\Http\Routing\Router` (what `Kernel::router()` returns) exposes no
     * route-table accessor of its own — the same gap `doctor()` already works around by summing a
     * count off this exact loop; see the REPORT for the precise missing primitive.
     */
    private function inspectRoutes(): int
    {
        $kernel = $this->bootKernelForInspect();
        if ($kernel === null) {
            return 1;
        }

        $this->line('milpa · coa inspect:routes');
        $this->line('(reconstructed from booted RouteProviderInterface plugins — Milpa\\Http\\Routing\\Router');
        $this->line(' exposes no route-table accessor of its own; see report)');
        $this->line('');

        $booted = $kernel->bootedPluginNames();
        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $rows */
        $rows = [];
        foreach ($kernel->plugins() as $plugin) {
            if (!$plugin instanceof RouteProviderInterface) {
                continue;
            }
            $meta = $this->pluginMetadata($plugin);
            if (!\in_array($meta->name, $booted, true)) {
                continue;
            }
            foreach ($plugin->routes() as $route) {
                $methods = implode('|', array_map(static fn (HttpMethod $m): string => $m->value, $route->methods));
                $handler = $route->handler !== null ? (string) $route->handler : '(unbound)';
                $rows[] = [$methods, $route->path, $handler, $meta->name];
            }
        }

        if ($rows === []) {
            $this->line('(no routes declared)');

            return 0;
        }

        foreach ($rows as [$methods, $path, $handler, $pluginName]) {
            $this->line(\sprintf('  %-10s %-24s -> %-40s [%s]', $methods, $path, $handler, $pluginName));
        }
        $this->line('');
        $this->line(\count($rows) . ' route(s).');

        return 0;
    }

    /**
     * `inspect:services` — every service id the DI container knows about, mapped to the class of
     * the instance it resolves to. `Milpa\Interfaces\Di\DIContainerInterface` declares no
     * enumeration method (`getContainer()` returns a plain PSR-11 `ContainerInterface`, which only
     * guarantees `get()`/`has()`), so this reaches past the interface into the concrete
     * `Symfony\Component\DependencyInjection\ContainerBuilder` that `Milpa\Container\DIContainer`
     * always builds internally — the best available primitive, and exactly the gap the REPORT flags.
     */
    private function inspectServices(): int
    {
        $kernel = $this->bootKernelForInspect();
        if ($kernel === null) {
            return 1;
        }

        $this->line('milpa · coa inspect:services');
        $this->line('');

        $psrContainer = $kernel->container()->getContainer();
        if (!$psrContainer instanceof ContainerBuilder) {
            $this->line(
                '✗ cannot enumerate: the underlying container is ' . $psrContainer::class . ', not '
                . 'Symfony\\Component\\DependencyInjection\\ContainerBuilder — '
                . 'Milpa\\Interfaces\\Di\\DIContainerInterface declares no service-id enumeration '
                . 'method of its own. See report.',
            );

            return 1;
        }

        /** @var list<string> $ids */
        $ids = array_values(array_filter(
            $psrContainer->getServiceIds(),
            static fn (string $id): bool => $id !== 'service_container',
        ));
        sort($ids);

        if ($ids === []) {
            $this->line('(no services registered)');

            return 0;
        }

        foreach ($ids as $id) {
            try {
                // ContainerBuilder::get()'s default $invalidBehavior throws rather than returning
                // null for a missing/unresolvable id — every id here came straight off the same
                // container's own getServiceIds(), so this always resolves to a real object.
                $instance = $psrContainer->get($id);
                $impl = $instance::class;
            } catch (\Throwable $e) {
                $impl = '(unresolvable: ' . $e->getMessage() . ')';
            }
            $this->line(\sprintf('  %-70s -> %s', $id, $impl));
        }
        $this->line('');
        $this->line(\count($ids) . ' service(s) registered.');

        return 0;
    }

    /**
     * `inspect:tools` — every `#[Tool]` registered on the kernel's tool registry, via the concrete
     * registry's `getToolSummaries()`. `Kernel::toolRegistry()` is typed against
     * `Milpa\Interfaces\Tooling\ToolRegistryInterface` (core), whose contract has NO
     * summary-listing method of its own (`register()` only) — `getToolSummaries()` exists solely
     * on the concrete `Milpa\ToolRuntime\ToolRegistry`, so this narrows with `instanceof` rather
     * than reflecting.
     *
     * `milpa/tool-runtime` (where that concrete class lives) is `suggest`-only, not a hard
     * dependency of this skeleton (skeleton 0.5.1) — {@see self::bootKernelForInspect()} only wires
     * a registry when the package is actually installed (its own `class_exists()` guard). So the
     * `!$registry instanceof ToolRegistry` branch below now covers a REAL, common case — agent-ready
     * not enabled — not just a defensive one, and prints guidance to opt in instead of a bare "no
     * tools" message.
     */
    private function inspectTools(): int
    {
        $kernel = $this->bootKernelForInspect();
        if ($kernel === null) {
            return 1;
        }

        $this->line('milpa · coa inspect:tools');
        $this->line('');

        $registry = $kernel->toolRegistry();
        if (!$registry instanceof ToolRegistry) {
            $this->line('no tool registry — agent-ready not enabled.');
            $this->line('Run: composer require milpa/tool-runtime milpa/mcp-server  (or: php bin/coa agent:enable)');

            return 0;
        }

        (new McpProjector())->project($kernel->commands(), $registry, $kernel->container());

        $summaries = $registry->getToolSummaries();
        if ($summaries === []) {
            $this->line('(no tools registered)');

            return 0;
        }

        foreach ($summaries as $tool) {
            $name = $tool['name'];
            $description = $tool['description'];
            $this->line(\sprintf('  %-30s %s', $name, $description));
        }
        $this->line('');
        $this->line(\count($summaries) . ' tool(s) registered.');

        return 0;
    }

    /**
     * `inspect:commands` (new, skeleton 0.5) — every command this `coa` can run: the built-ins
     * matched directly in {@see run()}'s `match`, plus every command a booted
     * `CommandProviderInterface` plugin contributed via `commands()`. The discovered half is
     * reconstructed the same way {@see self::inspectRoutes()} reconstructs the route table (walk
     * booted plugins, filter by interface, re-call the declaration method) rather than reading
     * {@see Kernel::commands()} directly, because that flat list carries no per-command source
     * plugin — walking plugins here is what recovers it.
     */
    private function inspectCommands(): int
    {
        $kernel = $this->bootKernelForInspect();
        if ($kernel === null) {
            return 1;
        }

        $this->line('milpa · coa inspect:commands');
        $this->line('');
        $this->line('Built-in:');
        foreach ($this->builtInCommands() as $name => $description) {
            $this->line(\sprintf('  %-20s %-48s [built-in]', $name, $description));
        }

        $booted = $kernel->bootedPluginNames();
        /** @var list<array{0: string, 1: string, 2: string}> $discovered */
        $discovered = [];
        foreach ($kernel->plugins() as $plugin) {
            if (!$plugin instanceof CommandProviderInterface) {
                continue;
            }
            $meta = $this->pluginMetadata($plugin);
            if (!\in_array($meta->name, $booted, true)) {
                continue;
            }
            foreach ($plugin->commands() as $command) {
                $discovered[] = [$command->name, $command->description, $meta->name];
            }
        }

        $this->line('');
        $this->line('Discovered (from plugins):');
        if ($discovered === []) {
            $this->line('  (none)');
        } else {
            foreach ($discovered as [$name, $description, $pluginName]) {
                $this->line(\sprintf('  %-20s %-48s [%s]', $name, $description, $pluginName));
            }
        }

        $this->line('');
        $this->line(\sprintf(
            '%d built-in, %d discovered command(s).',
            \count($this->builtInCommands()),
            \count($discovered),
        ));

        return 0;
    }

    /**
     * `coa <name>` for any `<name>` that matched none of {@see run()}'s built-in `match` arms:
     * boots the real kernel, looks `<name>` up in {@see Kernel::commands()} — the flat list every
     * booted `CommandProviderInterface` plugin contributed — and invokes its handler via
     * {@see self::invokeCommand()} when found. Falls back to {@see self::help()} otherwise, exactly
     * like the old hardcoded `default => $this->help()` did for any unrecognized command.
     *
     * @param list<string> $args
     */
    private function runDiscoveredCommand(string $command, array $args): int
    {
        $kernel = $this->bootKernelForInspect();
        if ($kernel === null) {
            return 1;
        }

        foreach ($kernel->commands() as $definition) {
            if ($definition->name === $command) {
                return $this->invokeCommand($definition, $args, $kernel);
            }
        }

        return $this->help();
    }

    /** @param list<string> $args */
    private function invokeCommand(Operation $definition, array $args, Kernel $kernel): int
    {
        return (new CliProjector())->run(
            $definition,
            $args,
            $kernel->container(),
            fn (string $line) => $this->line($line),
        );
    }

    /**
     * @return array<string, string> command name => one-line description, for `inspect:commands`'
     *                               "Built-in:" section. A separate literal from {@see self::help()}'s
     *                               free-text output (not derived from it) so a future edit to one
     *                               doesn't silently desync the other.
     */
    private function builtInCommands(): array
    {
        return [
            'doctor' => 'boot the kernel, report what came up',
            'validate' => 'static pre-boot capability check (no boot())',
            'make:controller' => 'scaffold a booting PSR-7 controller + route',
            'make:entity' => 'scaffold a persisting entity + FileRepository',
            'make:plugin' => 'scaffold a standalone plugin (composition unit)',
            'make:service' => 'scaffold a domain service, DI-registered in boot()',
            'make:tool' => 'scaffold a #[Tool]-attributed AI tool method',
            'make:crud' => 'scaffold entity + REST controller + routes + plugin',
            'inspect:plugins' => 'list booted plugins + their capability graph',
            'inspect:routes' => 'list the booted route table (method, path, handler)',
            'inspect:services' => 'list what the DI container has registered',
            'inspect:tools' => 'list registered #[Tool]s (or "no tool registry")',
            'inspect:commands' => 'list built-in + plugin-discovered coa commands',
            'agent:enable' => 'opt in to the agent-ready surface (composer require tool-runtime + mcp-server)',
        ];
    }

    private function help(): int
    {
        $this->line('milpa · coa — the skeleton\'s minimal CLI');
        $this->line('');
        $this->line('  coa doctor                                    boot the kernel, report what came up');
        $this->line('  coa validate                                  static pre-boot capability check (no boot())');
        $this->line('  coa make:controller <Plugin> <Name> [opts]     scaffold a booting PSR-7 controller + route');
        $this->line('  coa make:entity <Plugin> <Name> [opts]         scaffold a persisting entity + FileRepository');
        $this->line('  coa make:plugin <Name> [opts]                  scaffold a standalone plugin (composition unit)');
        $this->line('  coa make:service <Plugin> <Name> [opts]        scaffold a domain service, DI-registered in boot()');
        $this->line('  coa make:tool <Plugin> <Name> [opts]           scaffold a #[Tool]-attributed AI tool method');
        $this->line('  coa make:crud <Plugin> <Entity> [opts]         scaffold entity + REST controller + routes + plugin');
        $this->line('  coa inspect:plugins                            list booted plugins + their capability graph');
        $this->line('  coa inspect:routes                             list the booted route table (method, path, handler)');
        $this->line('  coa inspect:services                           list what the DI container has registered');
        $this->line('  coa inspect:tools                              list registered #[Tool]s (or "no tool registry")');
        $this->line('  coa inspect:commands                           list built-in + plugin-discovered coa commands');
        $this->line('  coa agent:enable                               opt in to agent-ready (composer require tool-runtime + mcp-server)');
        $this->line('');
        $this->line('  opts for make:controller: --path=/route  --flavor=runtime|legacy  --register  --force');
        $this->line('  opts for make:entity:     --fields="name:type[:mods],..."  --table=name  --flavor=runtime|legacy  --wire  --register  --force');
        $this->line('  opts for make:plugin:     --provides=cap1,cap2  --requires=cap3  --flavor=runtime|legacy  --force');
        $this->line('  opts for make:service:    --interface  --flavor=runtime|legacy  --force');
        $this->line('  opts for make:tool:       --description=text  --tool-name=snake_name  --flavor=runtime|legacy  --force');
        $this->line('  opts for make:crud:       --fields="name:type[:mods],..."  --table=name  --flavor=runtime|legacy  --register  --force');
        $this->line('');
        $this->line('  Any other <name> is looked up in the discovered command table (a booted');
        $this->line('  CommandProviderInterface plugin\'s commands()) and run if found — see `coa inspect:commands`.');

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
