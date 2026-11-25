# Demo with FrankenPHP (worker mode)

## Table of contents

- [Run it in development](#run-it-in-development)
- [What the demo shows](#what-the-demo-shows)
- [Run it in production mode](#run-it-in-production-mode)
- [Services](#services)
- [PHP version policy](#php-version-policy)
- [Worker and classic mode](#worker-and-classic-mode)
- [Development tools](#development-tools)
- [Bundle registration](#bundle-registration)
- [Makefile targets](#makefile-targets)
- [Timeouts](#timeouts)
- [Troubleshooting](#troubleshooting)

## Run it in development

```bash
cd demo/symfony8
make up       # http://localhost:8271 (bound to 127.0.0.1); last line: "Demo started at: http://localhost:8271"
make setup    # composer install + database schema
make seed     # seed:apply config/seeds/catalog.yaml and products-extra.jsonl --actor=admin (idempotent)
make down
```

From the repository root: `make -C demo up-symfony8`, `make demo-smoke` (boots the demo, expects HTTP 200 on `/`,
REQ-TEST-011), `make test-e2e` (Playwright, demo running) and `make demo-screenshots` (refreshes
`docs/images/demo/*.webp`).

## What the demo shows

0.1.0 has no UI, so the demo shows the console and the provider API:

- **Providers** (`src/Seed`): `catalog.categories` (key `slug`, lower-cased) and `catalog.products` (key `sku`,
  `category` is an `x-seed-ref` to a category, `price` an `x-seed-float`), declared with `#[AsSeedProvider]`, writing
  two Doctrine entities through `App\Catalog\CatalogService` (lookup by natural key, pessimistic write lock with
  `lockForUpdate`, prune support, entity manager cleared in `afterBatch()`).
- **Seed files**: `config/seeds/catalog.yaml` (multi-document YAML: categories then products) and
  `config/seeds/products-extra.jsonl` (JSON Lines).
- **Console** (inside `make shell`): `bin/console seed:list`, `seed:export catalog.products --empty --actor=admin`,
  `seed:diff config/seeds/catalog.yaml --actor=admin`, `seed:apply config/seeds/catalog.yaml --yes --actor=admin`.
- **Home page** (`/`): providers from `SeedOperations::providers()`, row counts, the last runs from
  `RunMaintenance::list()`.
- **Downloads** (`/seed/{provider}/{empty|current|schema}?format=yaml|json|jsonl`): sign in as `admin` (may export) or
  `viewer` (the bundle's guard denies: 403); password `demo` for both. The login is a FormKit form.

## Run it in production mode

The image is the same; the environment decides:

1. Copy `.env.example` to `.env` and set `APP_ENV=prod`, `APP_DEBUG=0`, `FRANKENPHP_MODE=worker` and a real
   `DATABASE_URL`.
2. Install without dev packages and warm the cache:

   ```bash
   docker compose run --rm --no-deps --entrypoint composer php install --no-dev --optimize-autoloader --no-interaction
   docker compose run --rm --no-deps --entrypoint php php bin/console cache:warmup --env=prod
   ```

3. Start it: `docker compose up -d`. The entrypoint keeps `docker/frankenphp/Caddyfile` (worker mode). Hot Reload, Twig
   Inspector, the Web Profiler and the Debug bundle are registered for `dev` / `test` only.
4. Put the server behind your TLS proxy, or set `SERVER_NAME` to your domain so Caddy obtains a certificate.

## Services

| Service | Image | Notes |
| --- | --- | --- |
| `php` | `dunglas/frankenphp:1-php8.5-alpine` | Symfony 8.1; the bundle mounted from the repository root through a Composer path repository; public DNS servers pinned for Composer |
| `db` | `mysql:8.4` (no host port, REQ-DEMO-006) | Demo credentials on the private Compose network (nothing secret in the repository) |

## PHP version policy

Symfony 8 demos run on the **newest PHP minor published in the official FrankenPHP images** that the demo's
`require.php` (`>=8.4,<8.6`) allows: today PHP **8.5**. The demo pins Symfony `8.1.*` (`extra.symfony.require`,
REQ-DEMO-012). When FrankenPHP ships a newer PHP and the constraint allows it, the image moves in a follow-up change
(REQ-DEMO-010).

## Worker and classic mode

`FRANKENPHP_MODE` lives in `.env` (template `.env.example`, default `worker`) and Compose passes it to the container;
it is not baked into the image.

- `worker` (default): `docker/frankenphp/Caddyfile`, `php_server { hot_reload; worker { file /app/public/index.php;
  watch } }`. The kernel boots once and is kept alive between requests, **without a kernel reset**: the setting the
  bundle is built and tested for ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).
- `classic`: the entrypoint uses `docker/frankenphp/Caddyfile.dev` (plain `php_server { hot_reload }`, one process per
  request).

Switch by editing `.env` and recreating the container (`docker compose up -d`); a plain `restart` does not reload the
environment and no rebuild is needed. `APP_SECRET` is generated into `.env` by the entrypoint on the first start.

## Development tools

| Tool | Package (`require-dev`) | Registered for |
| --- | --- | --- |
| Web Profiler (toolbar, `/_profiler`) | `symfony/web-profiler-bundle` | `dev`, `test` |
| Debug (`dump()` server) | `symfony/debug-bundle` | `dev` |
| Nowo Twig Inspector | `nowo-tech/twig-inspector-bundle` | `dev`, `test` |
| Nowo Hot Reload (`config/packages/dev/nowo_hot_reload.yaml`) | `nowo-tech/hot-reload-bundle` | `dev`, `test` |

`config/packages/dev/twig.yaml` turns the Twig cache off and `docker/php-dev.ini` sets `opcache.revalidate_freq=0`, so
changes show on refresh.

## Bundle registration

```php
// demo/symfony8/config/bundles.php
Nowo\SeedKitBundle\NowoSeedKitBundle::class => ['all' => true],
```

`config/packages/nowo_seed_kit.yaml` keeps the bundle defaults (platform scope only, role-based access, run history on
the default entity manager's connection) and changes two things: the console may read the demo's `config/seeds`, and
`ROLE_ADMIN` may prune (the bundle default is nobody). The shell is UiKit's demo layout with its default theme (no CSS
framework, no CDN; REQ-DEMO-014); the login form uses the FormKit `demo` profile (REQ-DEMO-015).

## Makefile targets

`make -C demo/symfony8 help` lists them: `up`, `down`, `restart`, `build`, `ensure-up`, `install`, `setup`,
`db-schema`, `shell`, `logs`, `cache-clear`, `update-bundle`, `update-deps` (REQ-MAKE-008), `test` (container, Twig
and `seed:lint`), `test-coverage` (the same lint: the demo has no PHPUnit suite), `verify` (HTTP 200 on `/`), `seed`,
`release-check` (`update-bundle`, `test`, `verify`), `test-e2e`, `demo-screenshots`.

## Timeouts

The bundle makes no outbound HTTP call and runs no subprocess, and the demo's web pages only read (downloads of a
template, the current rows or a schema). Writes run on the console (`make seed`), where no web timeout applies, so the
FrankenPHP and PHP defaults are kept. In a host, keep big applies on the console (or, from 0.2, Messenger); when the
0.2 UI runs a dry run or a small apply inside a request, keep the operation below PHP `max_execution_time`, itself
below Caddy's `servers.timeouts.write`, and mind FrankenPHP's `max_wait_time` for queued requests
([CONFIGURATION.md](CONFIGURATION.md#timeouts)).

## Troubleshooting

- `Demo not ready (HTTP 000)` right after `make up`: the first `composer install` runs inside the container; wait and
  run `make up` again.
- Composer DNS errors on WSL: the Compose file pins public DNS servers for the `php` service.
- `seed:apply` refuses the file as "outside console.allowed_dirs": keep seed files under `config/seeds` or `var/seeds`,
  or pass `--allow-any-path`.
- Changes not visible in worker mode: `watch` restarts the workers on file changes; if a change is still not picked
  up, switch to `FRANKENPHP_MODE=classic` and recreate the container.
- No toolbar: check `APP_ENV=dev` and `APP_DEBUG=1` in `.env`, then recreate the container.
