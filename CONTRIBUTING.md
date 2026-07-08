# Contributing to Milpa Skeleton

Thanks for your interest in contributing! `milpa/skeleton` is the **starting-point template** for
a Milpa app — the thing people scaffold from with `composer create-project milpa/skeleton myapp`.
It is deliberately tiny: a `Milpa\Runtime\Kernel::boot()` call, one plugin, one route, one page,
and a minimal `coa` CLI, with **zero database** and nothing to configure before it runs.

Because it is a template, the bar here is different from a library's: every change has to keep the
generated starting point **booting and serving on the first try**. Contributions that grow the
template into a fuller app are usually the wrong call — that surface belongs to the app someone
grows *from* this, not to the skeleton itself (see "What this is NOT" in the README).

## Getting started

```bash
composer install
php bin/coa doctor      # boots the real kernel; must exit 0
php -S localhost:8000 -t public   # then: curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/  → 200
vendor/bin/phpunit      # the boot smoke test
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse src public
```

CI runs the boot proof end to end on PHP 8.3 and 8.4 — `composer install`, a `php -l` syntax
pass, code style, `coa doctor` exiting 0, a live `php -S` + `curl /` returning **200**, and
PHPUnit. Run the commands above locally before opening a PR.

> The `composer.json` declares `path` repositories for the sibling `milpa/*` packages so the
> template can be developed inside the framework monorepo. Once published, those packages resolve
> from Packagist and the path entries are simply ignored — you do not need the monorepo checked
> out to work on the skeleton against released dependencies.

## Guidelines

- **PHP >= 8.3**, with `declare(strict_types=1);` in every file.
- **Keep it minimal.** The skeleton teaches the shape of a Milpa app; it is not the place to
  demonstrate every feature. New capabilities belong in a plugin or an example repo.
- **Keep it booting.** If a change touches `public/index.php`, `bin/coa`, `config/`, or
  `HelloPlugin`, prove the app still boots (`coa doctor`), serves `/` (200), and passes the boot
  test before pushing.
- **[Conventional Commits](https://www.conventionalcommits.org/)** — releases and the CHANGELOG
  are generated automatically from commit messages. Use `feat:` / `fix:` / `docs:` / `chore:`
  etc.; a change that alters the scaffold's public shape is a `feat!:` / `BREAKING CHANGE:`
  (bumps MINOR while the package is `0.x`, MAJOR once it reaches `1.0`).

## Code style

The whole Milpa family shares one coding standard, committed verbatim in every repo as
`.php-cs-fixer.dist.php` and enforced by CI. In short:

- **[PSR-12](https://www.php-fig.org/psr/psr-12/) base**: 4 spaces (never tabs); opening braces
  on the **next line** for classes and methods, on the **same line** for control structures; one
  statement per line.
- **Family deltas on top of PSR-12**: short array syntax (`[]`), one space around string
  concatenation (`$a . $b`), fully-multiline method arguments when split, no unused imports,
  aligned/separated/trimmed PHPDoc tags, trailing commas in multiline constructs.

Check and fix locally before pushing:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff   # what CI runs
vendor/bin/php-cs-fixer fix                    # apply
```

Do not tweak `.php-cs-fixer.dist.php` in one package alone — the standard changes in lockstep
across the family or not at all.

## Pull requests

Keep PRs focused, add or update the boot test for behavior changes, and make sure the commands
above are green. A maintainer will review and, once merged to `main`, release-please will handle
versioning.

## License

By contributing, you agree that your contributions are licensed under the
[Apache License 2.0](LICENSE).

---

Milpa is developed and maintained by [TeamX Agency](https://teamx.agency).
