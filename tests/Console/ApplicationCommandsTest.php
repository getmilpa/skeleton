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

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Everything `coa` answers besides the generators: the two checks, the five
 * inspectors, the help, and what each one does when the app underneath is
 * broken.
 *
 * This is the first command a person runs after `create-project`. A `doctor`
 * that lies, or an `inspect` that reports an empty app as fine, is worse than
 * no command at all — it is the one thing they trust before they know enough
 * to doubt it.
 */
final class ApplicationCommandsTest extends ApplicationTestCase
{
    // ---- the front door ----------------------------------------------------------

    public function testWithNoArgumentsAtAllItPrintsItsHelp(): void
    {
        // Typing just `coa` is how everyone starts. Failing there would make
        // the tool look broken before it has done anything.
        $exit = $this->runCoa();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("coa — the skeleton's minimal CLI", $this->lastOutput());
    }

    public function testTheHelpNamesEveryCommandTheApplicationActuallyRuns(): void
    {
        // A command that runs but is not listed is a command nobody finds.
        $this->runCoa('list');
        $output = $this->lastOutput();

        foreach ([
            'doctor', 'validate',
            'make:controller', 'make:entity', 'make:plugin', 'make:service', 'make:tool', 'make:crud',
            'inspect:plugins', 'inspect:routes', 'inspect:services', 'inspect:tools', 'inspect:commands',
            'agent:enable', 'wow',
        ] as $command) {
            $this->assertStringContainsString('coa ' . $command, $output);
        }
    }

    public function testAnUnknownCommandIsRefusedRatherThanIgnored(): void
    {
        // Exit 0 on a typo is how a broken deploy script looks green.
        $exit = $this->runCoa('no-existe-este-comando');

        $this->assertNotSame(0, $exit);
    }

    // ---- doctor and validate --------------------------------------------------------

    public function testDoctorBootsTheAppAndReportsWhatCameUp(): void
    {
        $exit = $this->runCoa('doctor');
        $output = $this->lastOutput();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('milpa · coa doctor', $output);
        $this->assertStringContainsString('root: ' . $this->root, $output);
        $this->assertStringContainsString('HarnessPlugin', $output);
        $this->assertStringContainsString('✔ container:', $output);
        $this->assertStringContainsString('✔ dispatcher:', $output);
        $this->assertStringContainsString('route(s) declared', $output);
        $this->assertStringContainsString('zero database queries', $output);
    }

    public function testValidateChecksTheCapabilityGraphWithoutBooting(): void
    {
        // The whole point of validate over doctor: it must never run boot(),
        // so a plugin whose boot() would fail still validates.
        $exit = $this->runCoa('validate');
        $output = $this->lastOutput();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('boot() never runs', $output);
        $this->assertStringContainsString('satisfy every #[PluginMetadata]', $output);
    }

    public function testValidateRefusesAClassThatDoesNotExist(): void
    {
        $this->writePluginList(['App\\Plugins\\NoExiste\\NoExiste']);

        $exit = $this->runCoa('validate');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not exist', $this->lastOutput());
    }

    public function testValidateRefusesAClassThatIsNotAPlugin(): void
    {
        // A class listed in config/plugins.php that is not a plugin fails at
        // boot with a much worse message. Naming it here is the point.
        $this->writePluginList([\stdClass::class]);

        $exit = $this->runCoa('validate');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not implement', $this->lastOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commandsThatNeedThePluginList(): iterable
    {
        yield 'doctor' => ['doctor'];
        yield 'validate' => ['validate'];
        yield 'wow' => ['wow'];
        yield 'inspect:plugins' => ['inspect:plugins'];
        yield 'inspect:routes' => ['inspect:routes'];
        yield 'inspect:services' => ['inspect:services'];
        yield 'inspect:tools' => ['inspect:tools'];
        yield 'inspect:commands' => ['inspect:commands'];
    }

    #[DataProvider('commandsThatNeedThePluginList')]
    public function testWithoutAPluginListEveryCommandSaysWhichFileIsMissing(string $command): void
    {
        // Running coa from the wrong directory is the most common way to get
        // here. The path in the message is what tells the person that.
        unlink($this->root . '/config/plugins.php');

        $exit = $this->runCoa($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('config/plugins.php not found', $this->lastOutput());
    }

    // ---- the inspectors -----------------------------------------------------------------

    public function testInspectPluginsListsEachPluginWithItsCapabilityGraph(): void
    {
        $exit = $this->runCoa('inspect:plugins');
        $output = $this->lastOutput();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('• HarnessPlugin  [booted]', $output);
        $this->assertStringContainsString('class:', $output);
        $this->assertStringContainsString('version:   1.0.0', $output);
        $this->assertStringContainsString('provides:  (none)', $output, 'A plugin that provides nothing says so rather than showing a blank.');
        $this->assertStringContainsString('1 plugin(s) configured, 1 booted.', $output);
    }

    public function testInspectRoutesSaysWhereItsAnswerComesFrom(): void
    {
        // The route table is reconstructed from the plugins, not read off the
        // router. Saying so is what keeps someone from trusting it as the
        // router's own truth.
        $exit = $this->runCoa('inspect:routes');
        $output = $this->lastOutput();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('milpa · coa inspect:routes', $output);
        $this->assertStringContainsString('reconstructed from booted RouteProviderInterface plugins', $output);
    }

    public function testInspectRoutesListsARouteOnceAPluginDeclaresOne(): void
    {
        $this->runCoa('make:controller', 'RutaPlugin', 'RutaController', '--path=/ruta', '--register');

        $exit = $this->runCoa('inspect:routes');
        $output = $this->lastOutput();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('/ruta', $output);
    }

    public function testInspectServicesListsWhatTheContainerHolds(): void
    {
        $exit = $this->runCoa('inspect:services');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('milpa · coa inspect:services', $this->lastOutput());
    }

    public function testInspectToolsTellsAnEmptyRegistryApartFromNoRegistryAtAll(): void
    {
        // The two read alike and only one is fixed by `agent:enable`, so the
        // command has to say which it found. This suite runs with
        // milpa/tool-runtime installed, so it lands on the empty-registry side;
        // the other branch belongs to a stock create-project app, where the
        // runtime is a `suggest` and not installed at all.
        $exit = $this->runCoa('inspect:tools');
        $output = $this->lastOutput();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('milpa · coa inspect:tools', $output);
        $this->assertStringContainsString('(no tools registered)', $output);
        $this->assertStringNotContainsString('agent-ready not enabled', $output, 'The runtime IS here; saying otherwise would send someone to install what they have.');
    }

    public function testInspectCommandsListsTheBuiltInsItCanRun(): void
    {
        $exit = $this->runCoa('inspect:commands');
        $output = $this->lastOutput();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('doctor', $output);
        $this->assertStringContainsString('inspect:commands', $output);
    }

    // ---- the generators' refusals ---------------------------------------------------------

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function generatorsMissingArguments(): iterable
    {
        yield 'make:controller with no name' => ['make:controller', ['SoloElPlugin']];
        yield 'make:controller with nothing' => ['make:controller', []];
        yield 'make:entity with no name' => ['make:entity', ['SoloElPlugin']];
        yield 'make:plugin with nothing' => ['make:plugin', []];
        yield 'make:service with no name' => ['make:service', ['SoloElPlugin']];
        yield 'make:tool with no name' => ['make:tool', ['SoloElPlugin']];
        yield 'make:crud with no entity' => ['make:crud', ['SoloElPlugin']];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('generatorsMissingArguments')]
    public function testAGeneratorCalledWithTooFewArgumentsPrintsItsUsage(string $command, array $args): void
    {
        // Scaffolding from a half-typed command would write files nobody asked
        // for, named after nothing.
        $exit = $this->runCoa($command, ...$args);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('usage: coa ' . $command, $this->lastOutput());
    }

    public function testMakePluginScaffoldsAStandalonePluginThatLints(): void
    {
        $exit = $this->runCoa('make:plugin', 'SoloPlugin', '--provides=App\\Capabilities\\Algo');
        $path = $this->root . '/src/Plugins/SoloPlugin/SoloPlugin.php';

        $this->assertSame(0, $exit);
        $this->assertFileExists($path);
        $this->assertPhpLints($path);
        $this->assertStringContainsString('#[PluginMetadata(', (string) file_get_contents($path));
    }

    public function testMakeServiceScaffoldsAServiceAndRegistersItInBoot(): void
    {
        $exit = $this->runCoa('make:service', 'ServicioPlugin', 'MiServicio');

        $this->assertSame(0, $exit);
        $servicePath = $this->root . '/src/Plugins/ServicioPlugin/Services/MiServicio.php';
        $this->assertFileExists($servicePath);
        $this->assertPhpLints($servicePath);

        $plugin = (string) file_get_contents($this->root . '/src/Plugins/ServicioPlugin/ServicioPlugin.php');
        $this->assertStringContainsString('MiServicio', $plugin, 'A service nobody registered is a service nobody can resolve.');
    }

    public function testMakeToolScaffoldsAToolAttributedMethod(): void
    {
        $exit = $this->runCoa('make:tool', 'ToolPlugin', 'MiHerramienta', '--description=Hace algo');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('#[Tool', $this->generatedFileUnder('ToolPlugin'));
    }

    public function testGeneratingOverAnExistingFileIsRefusedUntilForced(): void
    {
        // Overwriting someone's edited controller because they re-ran a command
        // is the one mistake a generator must never make silently.
        $this->runCoa('make:plugin', 'RepetidoPlugin');

        $exit = $this->runCoa('make:plugin', 'RepetidoPlugin');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--force', $this->lastOutput());
    }

    public function testForcingOverwritesWhatWasThere(): void
    {
        $this->runCoa('make:plugin', 'ForzadoPlugin');
        $path = $this->root . '/src/Plugins/ForzadoPlugin/ForzadoPlugin.php';
        file_put_contents($path, '<?php // editado a mano');

        $exit = $this->runCoa('make:plugin', 'ForzadoPlugin', '--force');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('#[PluginMetadata(', (string) file_get_contents($path));
    }

    /**
     * @param list<string> $classes
     */
    private function writePluginList(array $classes): void
    {
        $lines = array_map(static fn (string $class): string => '    \\' . $class . '::class,', $classes);

        file_put_contents(
            $this->root . '/config/plugins.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n" . implode("\n", $lines) . "\n];\n",
        );
    }

    private function generatedFileUnder(string $plugin): string
    {
        $found = '';
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root . '/src/Plugins/' . $plugin, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($dir as $file) {
            $found .= (string) file_get_contents((string) $file);
        }

        return $found;
    }

    private function assertPhpLints(string $path): void
    {
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($path), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    // ---- agent:enable ------------------------------------------------------------------

    public function testAgentEnableRunsComposerRequireInTheAppRootAndReportsSuccess(): void
    {
        // The command's whole job is to shell out correctly: the right binary,
        // the right two packages, and the app root as the working directory —
        // requiring them into the wrong project is the failure that would be
        // hardest to notice. A stub composer proves all three without
        // downloading anything.
        $bin = $this->withStubComposer(0);

        $exit = $this->withPath($bin, fn (): int => $this->runCoa('agent:enable'));
        $output = $this->lastOutput();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('composer require milpa/tool-runtime milpa/mcp-server', $output);
        $this->assertStringContainsString('agent-ready enabled', $output);

        $log = (string) file_get_contents($this->root . '/composer-stub.log');
        $this->assertStringContainsString('require milpa/tool-runtime milpa/mcp-server', $log);
        $this->assertStringContainsString('cwd=' . $this->root, $log, 'It required into the app, not into wherever coa was run from.');
    }

    public function testAgentEnableReportsComposersOwnExitCodeWhenItFails(): void
    {
        // Swallowing composer's failure would leave the app reporting
        // agent-ready with nothing installed.
        $bin = $this->withStubComposer(7);

        $exit = $this->withPath($bin, fn (): int => $this->runCoa('agent:enable'));

        $this->assertSame(7, $exit);
        $this->assertStringContainsString('composer require failed (exit 7)', $this->lastOutput());
        $this->assertStringNotContainsString('agent-ready enabled', $this->lastOutput());
    }

    public function testAgentEnableWithNoComposerOnPathSaysWhatToRunByHand(): void
    {
        $exit = $this->withPath('/directorio/que/no/existe', fn (): int => $this->runCoa('agent:enable'));
        $output = $this->lastOutput();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('composer executable not found on PATH', $output);
        $this->assertStringContainsString('composer require milpa/tool-runtime milpa/mcp-server', $output);
    }

    /**
     * A directory holding an executable `composer` that records how it was
     * called and exits with `$exitCode`.
     */
    private function withStubComposer(int $exitCode): string
    {
        $bin = $this->root . '/stub-bin';
        mkdir($bin, 0o777, true);

        $log = $this->root . '/composer-stub.log';
        // An absolute interpreter, not `/usr/bin/env php`: the PATH is
        // replaced while this runs, so `env` would have nowhere to look.
        $script = '#!' . PHP_BINARY . "\n<?php\n"
            . 'file_put_contents(' . var_export($log, true) . ", implode(' ', array_slice(\$argv, 1)) . \"\\ncwd=\" . getcwd() . \"\\n\");\n"
            . "exit({$exitCode});\n";

        file_put_contents($bin . '/composer', $script);
        chmod($bin . '/composer', 0o755);

        return $bin;
    }

    /**
     * @param callable(): int $run
     */
    private function withPath(string $dir, callable $run): int
    {
        $before = getenv('PATH');
        putenv('PATH=' . $dir);

        try {
            return $run();
        } finally {
            putenv('PATH=' . ($before === false ? '' : $before));
        }
    }

    // ---- when the app underneath will not boot -------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function commandsThatBoot(): iterable
    {
        yield 'doctor' => ['doctor'];
        yield 'inspect:plugins' => ['inspect:plugins'];
        yield 'inspect:routes' => ['inspect:routes'];
        yield 'inspect:services' => ['inspect:services'];
        yield 'inspect:commands' => ['inspect:commands'];
        yield 'wow' => ['wow'];
    }

    #[DataProvider('commandsThatBoot')]
    public function testABootFailureIsReportedAsOneLineRatherThanAStackTrace(string $command): void
    {
        // A plugin missing its #[PluginMetadata] is an ordinary authoring
        // mistake. Answering it with an uncaught exception would bury the one
        // sentence that says which plugin.
        $this->writeUnannotatedPlugin();

        $exit = $this->runCoa($command);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('✗ boot failed:', $this->lastOutput());
        $this->assertStringContainsString('SinMetadataPlugin', $this->lastOutput());
    }

    public function testValidateReportsABrokenCapabilityGraphWithoutBooting(): void
    {
        $this->writeUnannotatedPlugin();

        $exit = $this->runCoa('validate');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('✗ capability graph:', $this->lastOutput());
    }

    /**
     * Writes a plugin that is a real PluginInterface but carries no
     * `#[PluginMetadata]`, and points config/plugins.php at it.
     */
    private function writeUnannotatedPlugin(): void
    {
        $dir = $this->root . '/src/Plugins/SinMetadataPlugin';
        mkdir($dir, 0o777, true);

        file_put_contents($dir . '/SinMetadataPlugin.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Plugins\SinMetadataPlugin;

            use Milpa\Interfaces\Di\DIContainerInterface;
            use Milpa\Interfaces\Plugin\PluginInterface;

            final class SinMetadataPlugin implements PluginInterface
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

        $this->writePluginList(['App\\Plugins\\SinMetadataPlugin\\SinMetadataPlugin']);
    }

    // ---- registering into config/plugins.php ------------------------------------------------

    public function testRegisteringIntoAPluginListWithNoUseStatementsStillAddsTheImport(): void
    {
        // A freshly emptied plugins.php has a `return [` and nothing else. The
        // import has to land after the declare, not be dropped for lack of a
        // previous `use` line to follow.
        file_put_contents(
            $this->root . '/config/plugins.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n];\n",
        );

        $exit = $this->runCoa('make:controller', 'ImportPlugin', 'ImportController', '--path=/import', '--register');
        $plugins = (string) file_get_contents($this->root . '/config/plugins.php');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('use App\\Plugins\\ImportPlugin\\ImportPlugin;', $plugins);
        $this->assertStringContainsString('ImportPlugin::class,', $plugins);
        $this->assertPhpLints($this->root . '/config/plugins.php');
    }

    public function testRegisteringAPluginThatIsAlreadyListedDoesNotListItTwice(): void
    {
        // Re-running the generator with --register is ordinary. Two entries
        // would boot the same plugin twice.
        $this->runCoa('make:controller', 'DosVecesPlugin', 'UnoController', '--path=/uno', '--register');
        $this->runCoa('make:controller', 'DosVecesPlugin', 'DosController', '--path=/dos', '--register', '--force');

        $plugins = (string) file_get_contents($this->root . '/config/plugins.php');

        $this->assertSame(1, substr_count($plugins, 'DosVecesPlugin::class,'));
        $this->assertSame(1, substr_count($plugins, 'use App\\Plugins\\DosVecesPlugin\\DosVecesPlugin;'));
    }

    public function testWiringAnEntityIntoAPluginWithNoServicesMarkerIsSkippedWithAnExplanation(): void
    {
        // The generator will not rewrite arbitrary PHP. Saying why, and
        // printing the snippet, is what makes the refusal useful instead of
        // just a "no".
        $this->runCoa('make:plugin', 'SinMarcadorPlugin');
        $pluginPath = $this->root . '/src/Plugins/SinMarcadorPlugin/SinMarcadorPlugin.php';
        $contents = (string) file_get_contents($pluginPath);
        file_put_contents($pluginPath, str_replace('coa:services', 'sin-marcador', $contents));

        $this->runCoa('make:entity', 'SinMarcadorPlugin', 'Nota', '--fields=titulo:string', '--wire', '--force');

        $this->assertStringContainsString('wire skipped', $this->lastOutput());
    }
}
