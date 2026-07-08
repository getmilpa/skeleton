<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Skeleton

> `composer create-project milpa/skeleton myapp` → an app that **runs** — booted, serving `/`,
> answering `coa` — with zero database. This is your starting point, not a demo.

[![CI](https://github.com/getmilpa/skeleton/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/skeleton/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/skeleton.svg)](https://packagist.org/packages/milpa/skeleton)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

`milpa/skeleton` is the smallest real host of `milpa/runtime`: a `Kernel::boot()` call, one
plugin, one route, one CLI. No Doctrine, no legacy `Milpa\Web`, nothing to configure before you
see it work.

## Quickstart

```bash
composer create-project milpa/skeleton myapp
cd myapp
php -S localhost:8000 -t public
```

Open `http://localhost:8000` — you'll see "Milpa is running", served by
`App\Plugins\HelloPlugin\Controllers\HomeController` through `Milpa\Runtime\Http\RequestHandler`.

Then check the CLI:

```bash
php bin/coa doctor
```

```
milpa · coa doctor
root: /path/to/myapp
✔ 1 plugin(s) configured, 1 booted: HelloPlugin
✔ container: Milpa\Container\DIContainer
✔ dispatcher: Milpa\Eventing\EventDispatcher
✔ 1 route(s) declared (RouteProviderInterface plugins)
✔ config: app.greeting = 'Milpa is running.'
✔ kernel booted — zero database queries.
```

## What's in here

| Path | What it is |
|---|---|
| `public/index.php` | The HTTP entry point: builds a PSR-7 request from globals, boots the kernel, dispatches through `Milpa\Runtime\Http\RequestHandler`, emits the response. |
| `bin/coa` | The CLI entry point — `doctor`, `validate`, `make:controller`. See [`src/Console/Application.php`](src/Console/Application.php). |
| `config/plugins.php` | The active-plugins list — a plain `list<class-string>`. This is the *only* source of truth for what boots; no database, no filesystem discovery. |
| `config/app.php` | The app-config bag. Registered by `Kernel::boot()` as `Milpa\Runtime\Config`; plugins read it in `boot()`. See [The Config idiom](#the-config-idiom). |
| `src/Plugins/HelloPlugin/` | The example plugin: `#[PluginMetadata]`, no `provides`/`requires`, one `GET /` route, and the Config read that drives the homepage greeting. Copy its shape for your own plugins. |
| `tests/Boot/KernelBootTest.php` | The boot smoke test: the kernel boots from `config/plugins.php` and `GET /` returns 200. |

## Add a plugin

1. Create `src/Plugins/YourPlugin/YourPlugin.php` implementing `Milpa\Interfaces\Plugin\PluginInterface`
   with a `#[Milpa\Attributes\PluginMetadata(...)]` attribute (copy `HelloPlugin`'s shape).
2. To contribute routes, also implement `Milpa\Runtime\Http\RouteProviderInterface::routes()` —
   return a `list<Milpa\Http\Routing\Route>`, each bound to a
   `Milpa\Http\Routing\HandlerReference(ControllerClass::class, 'method')`.
3. Write a controller whose method takes a `Psr\Http\Message\ServerRequestInterface` and returns
   a `Psr\Http\Message\ResponseInterface` (see `HomeController` — this skeleton ships
   `nyholm/psr7` as its PSR-7 implementation, since `milpa/http` deliberately ships contracts
   only, no concrete request/response classes).
4. Add the class to `config/plugins.php`.
5. `php bin/coa doctor` to confirm it booted and its routes were counted; `php bin/coa validate`
   for a static pre-boot capability check without running `boot()`.

`Milpa\Runtime\Kernel::boot()` capability-checks every configured plugin's `#[PluginMetadata]`
*before* anything boots — a `requires` with no matching `provides` fails loudly, pre-boot, with
a typed `PluginDependencyException`, not a runtime surprise three requests later.

## The Config idiom

A plugin's constructor is fixed by `Milpa\Interfaces\Plugin\PluginInterface` to a single argument
— `(DIContainerInterface $container)`. It never receives config values directly. So how does a
plugin get a storage path, an API base URL, or a greeting string? It **reads them in `boot()`**
from the app-config bag:

1. Put configuration in `config/app.php`, which returns a nested array. Dot-notation indexes it,
   so `['app' => ['greeting' => 'Hi']]` is read back as `app.greeting`.
2. `public/index.php` (and `bin/coa`) pass it into the kernel:

   ```php
   $kernel = Kernel::boot([
       'plugins' => require $root . '/config/plugins.php',
       'config'  => require $root . '/config/app.php',
   ]);
   ```

3. `Kernel::boot()` registers it in the container as `Milpa\Runtime\Config`. A plugin reads what
   it needs in `boot()` — this is exactly what `HelloPlugin` does:

   ```php
   public function boot(): void
   {
       $greeting = $this->container->get(Config::class)->get('app.greeting', 'Milpa is running.');
       $this->container->registerService(HomeController::class, new HomeController($greeting));
   }
   ```

Edit `app.greeting` in `config/app.php`, reload `http://localhost:8000`, and the heading changes
— no env vars, no constructor plumbing. That is the whole idiom: **config lives in a file,
plugins read it in `boot()`**, `coa doctor` echoes back the value it resolved.

## Scaffolding with `coa`

`bin/coa` wires `milpa/devtools`' generate/inspect layer straight into this project — including
for an agent driving the CLI, not just a human:

```bash
php bin/coa doctor                                        # boot the kernel, report what came up
php bin/coa validate                                      # static capability check, no boot()
php bin/coa make:controller HelloPlugin GreetingController --methods=index
```

`make:controller` is real `milpa/devtools` machinery (`Milpa\DevTools\Make\Generators\ControllerGenerator`
+ `WriteGuard`) — but its output targets the *legacy* Milpa host convention devtools was built
for (`Milpa\Plugins\*\Controllers`, extends `Milpa\app\Providers\BaseController`), not this
skeleton's `App\Plugins\*` + `RouteProviderInterface` pattern. The command prints that mismatch
after writing the file; treat the generated code as a reference and adapt the namespace,
base class, and route wiring by hand. See this front's SDD report for the full context — closing
that gap with a runtime-native generator is a natural next step for `milpa/devtools`.

## What this is NOT

- **Not a finished app.** One plugin, one route, one page. Everything past that is yours to add.
- **Not wired to a database.** `milpa/runtime`'s plugin registry is config-driven, never a
  Doctrine entity — persistence is something a *plugin* opts into, never something the kernel
  requires. Add `doctrine/orm` and a storage plugin when (if) you need one.
- **Not the only PSR-7 choice.** `nyholm/psr7` is declared here as a real dependency because
  `milpa/http` ships routing contracts only, no concrete request/response implementation. Swap
  it for another PSR-7/PSR-17 implementation if you prefer — `public/index.php` and
  `RequestHandler` only depend on the PSR interfaces.

## The family

This skeleton composes six published Milpa packages, unmodified:

- [`milpa/runtime`](https://packagist.org/packages/milpa/runtime) — the bootable kernel that
  wires the rest together
- [`milpa/core`](https://packagist.org/packages/milpa/core) — contracts, capability graph, events
- [`milpa/container`](https://packagist.org/packages/milpa/container) — the DI container
- [`milpa/events`](https://packagist.org/packages/milpa/events) — the event dispatcher
- [`milpa/http`](https://packagist.org/packages/milpa/http) — PSR-15-native routing contracts
- [`milpa/plugin`](https://packagist.org/packages/milpa/plugin) — capability-ordered plugin boot
- [`milpa/devtools`](https://packagist.org/packages/milpa/devtools) — the engine behind `bin/coa`

## Contributing

Contributions are welcome. Please report security issues responsibly, and note that this project
follows a standard code of conduct.

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=skeleton)**.
