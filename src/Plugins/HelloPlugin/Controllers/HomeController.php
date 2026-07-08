<?php

declare(strict_types=1);

namespace App\Plugins\HelloPlugin\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The skeleton's only controller: proves `Milpa\Runtime\Http\RequestHandler` dispatched a real
 * request end to end. Returns a plain PSR-7 {@see Response} built with `nyholm/psr7` — the
 * skeleton's declared PSR-7 implementation (`milpa/http` ships only the routing contracts, no
 * concrete message/factory classes — every consumer picks one, see the README).
 *
 * The `$greeting` it renders is NOT read here — it is handed in by {@see \App\Plugins\HelloPlugin\HelloPlugin::boot()},
 * which pulls it out of the `config/app.php` bag via `Milpa\Runtime\Config` and constructs this
 * controller with it. The controller stays deliberately dumb: it renders what it is given.
 */
final class HomeController
{
    public function __construct(private readonly string $greeting)
    {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $this->html());
    }

    private function html(): string
    {
        $greeting = htmlspecialchars($this->greeting, \ENT_QUOTES, 'UTF-8');

        return \str_replace('__GREETING__', $greeting, <<<'HTML'
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>Milpa is running</title>
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <style>
                    body { font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                           max-width: 40rem; margin: 4rem auto; padding: 0 1.5rem; color: #1a1a1a; }
                    code { background: #f2f2f2; padding: 0.15em 0.4em; border-radius: 4px; }
                    h1 { font-size: 1.5rem; }
                </style>
            </head>
            <body>
                <h1>__GREETING__</h1>
                <p>This response left <code>App\Plugins\HelloPlugin\Controllers\HomeController</code>,
                   dispatched by <code>Milpa\Runtime\Http\RequestHandler</code> over a kernel booted
                   with zero database.</p>
                <p>The heading above came from <code>config/app.php</code>
                   (<code>app.greeting</code>), read by <code>HelloPlugin::boot()</code> through
                   <code>Milpa\Runtime\Config</code> — edit it and reload.</p>
                <p>Edit <code>config/plugins.php</code> to add your own plugin, or run
                   <code>php bin/coa doctor</code> to see what's booted.</p>
            </body>
            </html>
            HTML);
    }
}
