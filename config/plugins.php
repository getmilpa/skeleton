<?php

declare(strict_types=1);

use App\Plugins\HelloPlugin\HelloPlugin;
use Milpa\Plugin\Operations\PluginManagementPlugin;

/**
 * What this app has — a plain `list<class-string>` you edit and read in a diff. Add a plugin by
 * adding its class, remove one by deleting the line. No database, no filesystem discovery.
 *
 * What is *switched on* is a separate question, answered by `storage/plugins.json` and resolved in
 * `config/container.php`. A plugin listed here boots unless that store says otherwise, so an app
 * that never manages anything behaves exactly as if this list were the only thing there is — and
 * never grows the file.
 *
 * `PluginManagementPlugin` is what makes plugins manageable at all: it contributes the
 * `plugins.*` operations that the terminal, HTTP and MCP each project into their own shape.
 * Delete the line and this app goes back to being governed by the list alone.
 *
 * @return list<class-string>
 */
return [
    PluginManagementPlugin::class,
    HelloPlugin::class,
];
