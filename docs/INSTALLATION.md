# Installation

## Table of contents

- [Require the package](#require-the-package)
- [Register the bundle](#register-the-bundle)
- [Flex recipe](#flex-recipe)
- [Supported databases](#supported-databases)
- [Create the tables](#create-the-tables)
  - [Table prefix](#table-prefix)
  - [Columns](#columns)
- [Optional packages](#optional-packages)
- [First provider and first run](#first-provider-and-first-run)

## Require the package

```bash
composer require nowo-tech/seed-kit-bundle
# optional
composer require symfony/security-bundle          # SEED_* voter, role-based checker, user identifier as actor
composer require symfony/translation              # translated console messages and row errors
composer require --dev phpunit/phpunit            # the provider contract test kit
```

## Register the bundle

Flex does it for you. Without Flex, add the bundle to `config/bundles.php`:

```php
return [
    // ...
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Nowo\SeedKitBundle\NowoSeedKitBundle::class          => ['all' => true],
];
```

DoctrineBundle must be registered: the bundle prepends a read-only mapping of its two tables (named
`NowoSeedKitBundle`, XML by default) to the entity manager configured in `doctrine.entity_manager` (the default one
when unset), so your schema tool and your migrations see the tables.

## Flex recipe

The recipe (`.symfony/recipe/nowo-tech/seed-kit-bundle/0.1/`) registers `Nowo\SeedKitBundle\NowoSeedKitBundle` for
all environments, copies `config/packages/nowo_seed_kit.yaml` (the defaults, commented) and prints the next steps:
create the tables, write a provider, run `nowo:seed-kit:list`. Without Flex, create the file yourself; every key is
optional ([CONFIGURATION.md](CONFIGURATION.md)).

## Supported databases

| Database | Notes |
| --- | --- |
| MySQL 8.4 | Identifier columns get `utf8mb4_0900_bin` (binary, NO PAD) through the mapping listener |
| MariaDB 11.4 | Identifier columns get `utf8mb4_nopad_bin` |
| PostgreSQL 16+ | Text compares exactly by default |
| SQLite | Development and tests |

The bundle tables live on the connection of the entity manager named in `doctrine.entity_manager`. Your providers'
writes are inside the batch transaction only when they use the same connection ([CONCURRENCY.md](CONCURRENCY.md)).

## Create the tables

The bundle ships no migrations. The tables are mapped (read-only entities `SeedRun` and `SeedLease`), so the usual
tools create them:

```bash
# Doctrine Migrations
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate

# or, in development
bin/console doctrine:schema:update --dump-sql
bin/console doctrine:schema:update --force
```

With several entity managers, pass `--em=<name>` (the one in `doctrine.entity_manager`). Check the generated SQL before
running it: the identifier columns must carry the binary collation on MySQL / MariaDB.

### Table prefix

`doctrine.table_prefix` (default `nowo_`, `^[a-z][a-z0-9_]{0,15}$` or empty) is applied to the mapping (tables and
index names) **and** to every DBAL statement of the bundle, so both always agree:

| Prefix | Run history | Leases |
| --- | --- | --- |
| `nowo_` (default) | `nowo_seed_run` | `nowo_seed_lease` |
| `app_` | `app_seed_run` | `app_seed_lease` |
| `''` | `seed_run` | `seed_lease` |

Changing the prefix of an installed application means renaming the tables in a migration.

### Columns

`<prefix>seed_run` (run history, one row per apply that writes; metadata only in 0.1):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | string(36), PK | UUID v7 |
| `scope_kind`, `scope_id` | string(32), string(128) | `scope_id` is `''` for kinds without id; binary on MySQL / MariaDB |
| `actor_ref` | string(190), nullable | Nulled by `actor:forget` |
| `channel`, `status` | string(16), string(24) | `console`; `running`, `applied`, `partially_applied`, `failed` in 0.1 |
| `providers`, `batch_digests`, `counts` | json | Provider versions, reviewed batch digests, written rows per operation |
| `prune` | boolean | |
| `document_hash`, `plan_hash` | string(71), string(68) | `sha256:…`, `sk1:…` |
| `batches_done`, `version` | integer | Progress (committed with each batch) and row version |
| `document` | blob, nullable | Always empty in 0.1 (reserved for the 0.2 upload flow) |
| `error_code` | string(16), nullable | `SEED_006`, `SEED_010`, `CONFLICT_001`, `CONFLICT_002` |
| `created_at`, `updated_at`, `expires_at`, `finished_at` | datetime | UTC |

Indexes: `(scope_kind, scope_id, created_at)`, `(status, expires_at)`, `(actor_ref)`.

`<prefix>seed_lease` (one row per provider and scope): primary key `(provider, scope_kind, scope_id)`, `run_id`
string(36), `locked_until` datetime (UTC).

## Optional packages

| Package | What it adds | Switch |
| --- | --- | --- |
| `symfony/security-bundle` | `SeedKitVoter`, the role-based checker with the role hierarchy, the user identifier as actor | always on when installed |
| `nowo-tech/audit-kit-bundle` | Audit entries in the action log | `audit.auditkit: true` (default); used only when the recorder service exists |
| `nowo-tech/vault-bundle` | `vault:<item-uuid>` references checked against the target scope and the actor | `secrets.vault_bridge.enabled: true` |
| `symfony/translation` | Translated console messages and pages (10 locales) | always on when installed |
| `nowo-tech/ui-kit-bundle` ^2.74.1, `nowo-tech/form-kit-bundle` ^2.25.1, `symfony/twig-bundle`, `symfony/form`, `symfony/validator`, `symfony/security-bundle` | The import / export pages (0.2) | `ui.enabled: true` + `ui.mounts` + the routes below |
| `symfony/messenger` | Asynchronous apply of large UI runs (`ApplySeedRun`) | `messenger.enabled: true` + a route to an async transport |
| `symfony/scheduler` | Schedule `nowo_seed_kit` (expiry + purge) | `scheduler.enabled: true` |

### UI routes (0.2)

```yaml
# config/routes/nowo_seed_kit.yaml
nowo_seed_kit:
    resource: .
    type: nowo_seed_kit
```

One route set per `ui.mounts` entry, named `nowo_seed_kit_<kind>_<page>` (`index`, `export`, `schema`, `upload`,
`runs`, `run`, `run_apply`, `run_discard`, `run_progress`). Put the prefixes (`nowo_seed_kit.ui_paths`) behind your
firewall, e.g. `- { path: '^/developer/seeds', roles: IS_AUTHENTICATED_FULLY }`.

## First provider and first run

1. Write a provider ([USAGE.md](USAGE.md#writing-a-provider)) and tag it with `#[AsSeedProvider(name: '...')]`.
2. `bin/console nowo:seed-kit:lint` (schema rules) and `bin/console nowo:seed-kit:list`.
3. Create the seed directory the console may read: `mkdir -p config/seeds` (see `console.allowed_dirs`).
4. Export a template, fill it, dry-run it and apply it:

```bash
bin/console nowo:seed-kit:export catalog.categories --empty --actor=admin --output=config/seeds/categories.yaml
bin/console nowo:seed-kit:diff config/seeds/categories.yaml --actor=admin
bin/console nowo:seed-kit:apply config/seeds/categories.yaml --yes --actor=admin
```

On a fresh install without users, use `--actor=system` (console only).
