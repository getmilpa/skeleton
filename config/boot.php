<?php

declare(strict_types=1);

use Milpa\Container\DIContainer;
use Milpa\Plugin\Activation\ActivePlugins;

/**
 * What every entry point needs before `Milpa\Runtime\Kernel::boot()` can run: the container, and
 * the list of plugins that actually boot.
 *
 * They come from here together on purpose. The list is resolved from two sources — what
 * `config/plugins.php` declares and what `storage/plugins.json` says is switched on — and the
 * management operations write to that same store. If an entry point built the container one way
 * and the list another, the app would boot from one answer and be managed through a different one.
 * `ActivePlugins::wire()` is a single call precisely so that cannot happen.
 *
 * Register your own long-lived services on `$container` below when a plugin needs them at
 * construction time. Everything else belongs in a plugin's `boot()`; `Kernel::boot()` registers
 * the framework's own services into this same container.
 *
 * @return array{container: DIContainer, plugins: list<class-string>}
 */
$container = new DIContainer();

/** @var list<class-string> $declared */
$declared = require __DIR__ . '/plugins.php';

$plugins = ActivePlugins::wire($container, $declared, __DIR__ . '/../storage/plugins.json');

return ['container' => $container, 'plugins' => $plugins];
