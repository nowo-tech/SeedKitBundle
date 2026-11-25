# Seed Kit Bundle demo (Symfony 8.1, FrankenPHP worker, MySQL 8.4)

```bash
make up      # http://localhost:8271 (127.0.0.1 only)
make setup   # composer install + database schema
make seed    # seed:apply config/seeds/*.yaml|jsonl --actor=admin (idempotent)
make down
```

Bundle 0.1.0 is console + provider API, so the demo shows both:

- **Providers** (`src/Seed`): `catalog.categories` (key `slug`, lower-cased) and `catalog.products` (key `sku`,
  `category` is an `x-seed-ref` to a category, `price` an `x-seed-float`), declared with `#[AsSeedProvider]`, storing
  rows in two Doctrine entities through `App\Catalog\CatalogService` (lookup by natural key, pessimistic write lock
  with `lockForUpdate`, prune support, EntityManager cleared in `afterBatch`).
- **Console** (run inside `make shell`): `bin/console seed:list`, `seed:export catalog.products --empty --actor=admin`,
  `seed:diff config/seeds/catalog.yaml --actor=admin`, `seed:apply config/seeds/catalog.yaml --yes --actor=admin`.
  Seed files: `config/seeds/catalog.yaml` (multi-document YAML) and `config/seeds/products-extra.jsonl`.
- **Home page** (`/`): providers via `SeedOperations::providers()`, row counts, last runs via `RunMaintenance::list()`.
- **Downloads** (`/seed/{provider}/{empty|current|schema}`, `?format=yaml|json|jsonl`): sign in as `admin` (may
  export) or `viewer` (the bundle's guard answers 403); password `demo` for both.

FrankenPHP **worker mode** is the default (`FRANKENPHP_MODE=worker` in `.env`; `classic` + `docker compose up -d` to
switch). Dev stack: WebProfiler, DebugBundle, Twig Inspector, Hot Reload (dev/test only). See
[docs/DEMO-FRANKENPHP.md](../../docs/DEMO-FRANKENPHP.md).

```bash
make test             # lint:container, lint:twig, seed:lint
make verify           # HTTP 200 on /
make test-e2e         # Playwright (official image), after make up
make demo-screenshots # refresh ../../docs/images/demo/*.webp
```
