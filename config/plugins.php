<?php

declare(strict_types=1);

use App\Plugins\HelloPlugin\HelloPlugin;

/**
 * The active-plugins list — the single source of truth `Milpa\Runtime\Kernel::boot()` reads via
 * `$config['plugins']`. A plain `list<class-string>`, no database, no filesystem discovery: add a
 * plugin by adding its class here, remove one by deleting the line. `milpa/runtime`'s capability
 * check runs over exactly this list before anything boots.
 *
 * @return list<class-string>
 */
return [
    HelloPlugin::class,
];
