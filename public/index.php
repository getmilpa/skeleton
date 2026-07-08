<?php

declare(strict_types=1);

use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/../vendor/autoload.php';

// Explicit, cwd-independent root: PHP's built-in server chdir()s into the docroot (-t public)
// for every request, so leaving this to Kernel::boot()'s own auto-detection would still work
// here (RootResolver falls back to Composer\InstalledVersions, not getcwd(), when it can) but
// passing it explicitly keeps this entry point honest about where "the app" actually is,
// exactly like config/plugins.php below, which is loaded relative to the same root.
$root = \dirname(__DIR__);

/** @var list<class-string> $plugins */
$plugins = require $root . '/config/plugins.php';

/** @var array<string, mixed> $config */
$config = require $root . '/config/app.php';

$kernel = Kernel::boot([
    'root' => $root,
    'plugins' => $plugins,
    'config' => $config,
]);

$psr17 = new Psr17Factory();
$request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

$handler = new RequestHandler($kernel, $psr17);
$response = $handler->handle($request);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
echo $response->getBody();
