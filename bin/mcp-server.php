#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\McpServer\JsonRpcService;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

$root = \dirname(__DIR__);

// Same config-loading contract `bin/coa` uses: config/plugins.php declares the active plugin
// list, config/app.php is the optional app-config bag Kernel::boot() threads into
// Milpa\Runtime\Config. Missing config/app.php is not fatal — an empty bag boots fine.
$pluginsFile = $root . '/config/plugins.php';
/** @var list<class-string> $plugins */
$plugins = \is_file($pluginsFile) ? require $pluginsFile : [];
if (!\is_array($plugins)) {
    $plugins = [];
}

$configFile = $root . '/config/app.php';
/** @var array<string, mixed> $config */
$config = \is_file($configFile) ? require $configFile : [];
if (!\is_array($config)) {
    $config = [];
}

// The generic MCP server the skeleton ships (skeleton 0.5, friction #3 — see
// docs/superpowers/specs/2026-07-09-frictions-command-discovery.md and
// docs/library/vision-milpa-commands.md's "MCP disuelve make:mcp-server"): boots the REAL kernel
// with a fresh ToolRegistry wired in, so every #[Tool] a booted ToolProviderInterface plugin
// registers is exposed here automatically — an app with tools does NOT copy this file, it just
// has one. An app with zero tools still boots clean and serves an empty `tools/list`.
$kernel = Kernel::boot([
    'root' => $root,
    'plugins' => $plugins,
    'config' => $config,
    'toolRegistry' => new ToolRegistry(new NullLogger()),
]);

$registry = $kernel->toolRegistry();
if (!$registry instanceof ToolRegistry) {
    // Unreachable via the boot call above (it always passes a ToolRegistry) — guards the type
    // for static analysis and any future edit that stops wiring one.
    fwrite(STDERR, 'milpa · coa mcp-server — no tool registry wired, exiting.' . PHP_EOL);
    exit(1);
}

$service = new JsonRpcService($registry);

// STDOUT is protocol-only: one JSON-RPC message per line. Human-readable output goes to STDERR
// so it never corrupts the wire — same contract as example-agent-ready-blog's bin/mcp-server.php,
// the model this file follows.
fwrite(STDERR, 'milpa · coa mcp-server — MCP stdio server ready (close stdin to stop)' . PHP_EOL);

/** @param array<string, mixed> $response */
$writeLine = static function (array $response): void {
    fwrite(STDOUT, json_encode($response) . "\n");
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    /** @var mixed $request */
    $request = json_decode($line, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
        $writeLine([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32700, 'message' => 'Parse error'],
            'id' => null,
        ]);
        continue;
    }

    // This transport carries no auth: ToolContext::stdio() is the documented context for exactly
    // this case — process-level trust, principal 'stdio'.
    $ctx = ToolContext::stdio((string) ($request['id'] ?? uniqid('mcp-', true)));

    // JsonRpcService::handle() owns the whole JSON-RPC contract: envelope errors and batch
    // refusals come back as well-formed error arrays (never thrown), and notifications — any
    // message without an "id" member — return null. This transport's only job: write what is
    // non-null, write nothing for null.
    /** @var array<string, mixed> $request */
    $response = $service->handle($request, $ctx);

    if ($response !== null) {
        $writeLine($response);
    }
}
