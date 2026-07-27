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

/**
 * `coa` run against THIS app, not a synthetic fixture root.
 *
 * Every other console test builds a temp root to keep the real one untouched, which is right —
 * but it means those roots have no `config/boot.php`, so the path a real app actually takes never
 * runs. What is checked here is exactly that path: the container reaching `Kernel::boot()`, and
 * the operations a plugin contributes showing up where somebody would look for them.
 */
final class ApplicationAgainstThisAppTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = \dirname(__DIR__, 2) . '/storage/plugins.json';
        @unlink($this->statePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        parent::tearDown();
    }

    private function coa(string ...$args): string
    {
        ob_start();
        (new Application(\dirname(__DIR__, 2)))->run(array_merge(['coa'], $args));

        return (string) ob_get_clean();
    }

    public function testDoctorBootsThisAppThroughItsOwnContainer(): void
    {
        // Without the container reaching Kernel::boot(), PluginManagement finds
        // no registry and the boot fails — so a clean doctor IS the assertion
        // that config/boot.php was loaded and threaded through.
        $output = $this->coa('doctor');

        self::assertStringNotContainsString('boot failed', $output);
        self::assertStringContainsString('PluginManagement', $output);
        self::assertStringContainsString('HelloPlugin', $output);
    }

    public function testTheOperationsAPluginContributesAreListedWhereSomebodyWouldLookForThem(): void
    {
        // They ran before this was fixed; nothing listed them. A command you
        // cannot find is a command nobody uses.
        $output = $this->coa('inspect:commands');

        self::assertStringContainsString('plugins.list', $output);
        self::assertStringContainsString('plugins.enable', $output);
        self::assertStringContainsString('[PluginManagement]', $output);
        self::assertStringNotContainsString('0 discovered command(s)', $output);
    }

    public function testInspectPluginsReportsWhatThisAppActuallyBoots(): void
    {
        $output = $this->coa('inspect:plugins');

        self::assertStringContainsString('2 plugin(s) configured, 2 booted', $output);
    }

    public function testAnOperationRunsAsACommandAndReturnsItsDataAsJson(): void
    {
        $output = $this->coa('plugins.list');

        /** @var array{plugins: list<array<string, mixed>>} $decoded */
        $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['PluginManagement', 'HelloPlugin'],
            array_column($decoded['plugins'], 'name'),
        );
    }

    public function testSwitchingAPluginOffThroughTheCliRemovesItsRoutes(): void
    {
        // The end-to-end shape of what an admin panel does: state written by one
        // process changes what the next one serves.
        self::assertStringContainsString('HomeController', $this->coa('inspect:routes'));

        $this->coa('plugins.disable', '--name=HelloPlugin');

        $after = $this->coa('inspect:routes');
        self::assertStringNotContainsString('HomeController', $after);
        self::assertStringContainsString('no routes declared', $after);
    }
}
