<?php

declare(strict_types=1);

/**
 * The app-config bag — the second source of truth `Milpa\Runtime\Kernel::boot()` reads, via
 * `$config['config']`. It is registered in the container as `Milpa\Runtime\Config` and is the
 * seam plugins use to receive configuration WITHOUT constructor arguments: `PluginInterface`
 * fixes the constructor to `(DIContainerInterface $container)`, so a plugin that needs a value
 * reads it in `boot()` with `$container->get(Config::class)->get('app.greeting')` instead of an
 * env var or a widened constructor. Keys are indexed with dot notation, so a nested array here
 * (`'app' => ['greeting' => ...]`) reads back as `get('app.greeting')`.
 *
 * `HelloPlugin::boot()` reads `app.greeting` from here — edit the string below, reload the page,
 * and watch it change. That is the whole idiom: config lives in this file, plugins read it in
 * `boot()`.
 *
 * @return array<string, mixed>
 */
return [
    'app' => [
        'name' => 'Milpa Skeleton',
        'greeting' => 'Milpa is running.',
    ],
];
