# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Scopes and resolvers](#scopes-and-resolvers)
- [Doctrine](#doctrine)
- [Reader caps](#reader-caps)
- [Validation, apply, prune, plan, runs](#validation-apply-prune-plan-runs)
- [Transaction](#transaction)
- [Secrets](#secrets)
- [Audit](#audit)
- [Console](#console)
- [Security](#security)
- [Timeouts](#timeouts)
- [Rules checked at build time](#rules-checked-at-build-time)
- [Translations](#translations)
- [Not configurable in 0.1](#not-configurable-in-01)

The tree is strict (REQ-SF-006): an unknown key, a value out of range or an invalid combination fails the container
build with a message naming the path. Every key below is optional. None of them is an `.env` setting or a secret.

## Full reference

```yaml
nowo_seed_kit:
    scope_kinds:                        # kind => { requires_id }; default: platform only
        platform: { requires_id: false }
    scope_resolver: null                # SeedScopeResolverInterface service id (default: platform only)
    actor_resolver: null                # SeedActorResolverInterface service id (default: security user identifier)
    doctrine:
        entity_manager: null            # EM whose connection holds the tables and runs the batches (null: default EM)
        table_prefix: nowo_             # ^[a-z][a-z0-9_]{0,15}$ or ''
        mapping: xml                    # xml | attribute
    reader:
        max_bytes: 268435456            # 256 MiB, min 1024
        max_tree_bytes: 4194304         # 4 MiB, 1024..67108864 (YAML / JSON above this: refused, use JSON Lines)
        max_items: 1000000              # items per document, min 1
        max_documents: 20               # documents per file, 1..100
        max_depth: 16                   # nesting depth, 2..64
        max_string_bytes: 65536         # one string or key, min 64
        max_properties: 256             # properties of one object, min 1
        max_line_bytes: 1048576         # one JSON Lines line, min 1024
        max_nodes_per_item: 10000       # nodes of one item, min 1
    validation:
        max_errors: 200                 # row errors kept in a report, 1..10000
    apply:
        batch_size: 500                 # rows per batch transaction, 1..5000
        lease_seconds: 300              # lease TTL, renewed after every batch, 10..86400
    transaction:
        max_replays: 3                  # attempts of a batch after a lock conflict, 1..10
    prune:
        max_ratio: 0.5                  # share of current rows a prune may delete without --allow-mass-prune, 0.01..1.0
    plan:
        preview_rows: 200               # changes kept per operation in a plan preview, 1..100000
    runs:
        history_days: 365               # runs:purge default window, 1..3650
    secrets:
        validator: null                 # SecretReferenceValidatorInterface service id
        vault_bridge:
            enabled: false              # needs nowo-tech/vault-bundle
            scope_format: '{kind}:{id}' # Vault owner scope of a seed scope
    audit:
        auditkit: true                  # use AuditKit when its recorder service exists
        sink: null                      # SeedAuditSinkInterface service id (overrides log and AuditKit)
        log_level: notice               # debug | info | notice | warning
    console:
        require_actor: true             # writing commands need --actor (else the actor is "system")
        allowed_dirs: ['%kernel.project_dir%/config/seeds', '%kernel.project_dir%/var/seeds']
        short_aliases: true             # also register seed:* aliases
    security:
        access_roles: [ROLE_ADMIN]      # area: any seed action at all
        access_checker: null            # null (roles below), allow_all (demos) or a service id
        allow_unauthenticated: false    # demos only
        export_roles: [ROLE_ADMIN]      # SEED_EXPORT
        apply_roles: [ROLE_ADMIN]       # SEED_APPLY
        prune_roles: []                 # SEED_PRUNE: nobody until mapped
        providers: {}                   # per-provider overrides: name => { export_roles, apply_roles, prune_roles }
```

## Scopes and resolvers

| Key | Default | Meaning |
| --- | --- | --- |
| `scope_kinds` | `{platform: {requires_id: false}}` | The scope kinds of the host. Each name matches `[a-z][a-z0-9_]{0,31}`; at least one kind. `requires_id` defaults to `true` for a declared kind. A target scope whose id is missing or extra for its kind is refused (`ACCESS_001`). |
| `scope_resolver` | `null` | Service id of a `SeedScopeResolverInterface`. The default `PlatformScopeResolver` knows only `platform` (no id). A multi-tenant host returns the scope when it exists and the actor may see it, `null` otherwise (answered as `ACCESS_001`, a 404 in the 0.2 UI). |
| `actor_resolver` | `null` | Service id of a `SeedActorResolverInterface`. The default `SecurityActorResolver` returns the security user identifier (no token: none; the reserved `system` is never returned). The console `--actor` option wins over it. |

## Doctrine

| Key | Default | Meaning |
| --- | --- | --- |
| `doctrine.entity_manager` | `null` | Name of the entity manager whose **connection** holds `<prefix>seed_run` / `<prefix>seed_lease` and runs the batch transactions; the mapping is prepended to that manager. `null`: `doctrine.orm.default_entity_manager`, else the first declared manager, else `default`. The bundle never persists, flushes, clears or resets any entity manager: it uses only the connection. |
| `doctrine.table_prefix` | `nowo_` | Prefix of both tables and their indexes, applied to the mapping and to every DBAL statement ([INSTALLATION.md](INSTALLATION.md#table-prefix)). |
| `doctrine.mapping` | `xml` | `xml` (`src/Resources/config/doctrine/*.orm.xml`) or `attribute` (the entity classes). Both give the same schema (tested). |

## Reader caps

The reader counts bytes while it reads (never trusting headers) and refuses with `SEED_002` and a reason
([FORMAT.md](FORMAT.md#reader-caps)). The same caps apply to files, stdin and, in 0.2, uploads.

| Key | Default | Refusal reason |
| --- | --- | --- |
| `reader.max_bytes` | 268435456 (256 MiB) | `too_large` |
| `reader.max_tree_bytes` | 4194304 (4 MiB) | `too_large_for_tree`: YAML and JSON are parsed whole only up to this size; bigger files must be JSON Lines |
| `reader.max_items` | 1000000 | `too_many_items` (per document) |
| `reader.max_documents` | 20 | `too_many_documents` |
| `reader.max_depth` | 16 | JSON decoder depth and item nesting (`too_deep`) |
| `reader.max_string_bytes` | 65536 | `string_too_long` (values and keys) |
| `reader.max_properties` | 256 | `too_many_properties` (one object) |
| `reader.max_line_bytes` | 1048576 | `line_too_long` (JSON Lines) |
| `reader.max_nodes_per_item` | 10000 | `too_many_nodes` |

`reader.max_items` is also the `maxItems` of the published document schema.

## Validation, apply, prune, plan, runs

| Key | Default | Meaning |
| --- | --- | --- |
| `validation.max_errors` | 200 | Row errors collected before the report is cut (the report says it was truncated). |
| `apply.batch_size` | 500 | Items per slice: one provider `planBatch()` call when planning and one transaction per writing batch when applying. Plans of one batch are atomic; larger plans are batch-atomic ([CONCURRENCY.md](CONCURRENCY.md)). The console has no `--batch-size` option in 0.1. |
| `apply.lease_seconds` | 300 | TTL of the lease per (provider, scope), renewed after every batch. A crashed run's lease expires after it. |
| `prune.max_ratio` | 0.5 | A prune that deletes more than this share of the current rows (updated + unchanged + deleted) is refused (`SEED_012`) unless `--allow-mass-prune`. |
| `plan.preview_rows` | 200 | Changes kept per operation in the plan preview; counts and hashes always cover the whole plan. |
| `runs.history_days` | 365 | Default window of `runs:purge` (runs created before now minus this many days). |

## Transaction

| Key | Default | Meaning |
| --- | --- | --- |
| `transaction.max_replays` | 3 | Attempts of one batch write unit after a deadlock, lock-wait timeout or serialization failure (transaction-doctrine, full jitter). After the last one: `CONFLICT_001` (no batch committed) or `CONFLICT_002`. |

## Secrets

| Key | Default | Meaning |
| --- | --- | --- |
| `secrets.validator` | `null` | Service id of a `SecretReferenceValidatorInterface`. Without a validator and without the Vault bridge every secret reference is refused on import (`SEED_004`) and omitted on export (fail closed). |
| `secrets.vault_bridge.enabled` | `false` | Use `VaultSecretReferenceValidator` (needs `nowo-tech/vault-bundle`, else the build fails): `vault:<item-uuid>` must name an item that exists, is not trashed and is owned by the target scope; on import the actor must be allowed to view it (the console `system` actor is checked on ownership only). |
| `secrets.vault_bridge.scope_format` | `{kind}:{id}` | The Vault owner scope of a seed scope: `{kind}` and `{id}` are replaced; a scope without id uses the kind alone. |

## Audit

| Key | Default | Meaning |
| --- | --- | --- |
| `audit.auditkit` | `true` | When `nowo-tech/audit-kit-bundle` is installed **and** its recorder service exists, entries go to AuditKit's action log (`seed_kit.export`, `seed_kit.apply`, `seed_kit.prune`). |
| `audit.sink` | `null` | Service id of your own `SeedAuditSinkInterface`; it replaces both default sinks. |
| `audit.log_level` | `notice` | Level of the default PSR-3 sink (channel `seed_kit`). |

What is audited: [USAGE.md](USAGE.md#audit-and-events).

## Console

| Key | Default | Meaning |
| --- | --- | --- |
| `console.require_actor` | `true` | `export`, `diff` and `apply` need `--actor` (or an actor from the resolver). With `false` they act as `system`. |
| `console.allowed_dirs` | `config/seeds`, `var/seeds` under the project dir | Console file arguments (input and `--output`) must resolve inside one of them unless `--allow-any-path`. |
| `console.short_aliases` | `true` | Registers `seed:list`, `seed:schema`, … next to `nowo:seed-kit:*`. Turn it off when another package owns `seed:*`. |

## Security

House access pattern (REQ-UI-002). The defaults (`ROLE_ADMIN` for the area, export and apply, nobody for prune) make a
standard host work without configuration while the destructive action stays closed.

| Key | Default | Meaning |
| --- | --- | --- |
| `security.access_roles` | `[ROLE_ADMIN]` | The user needs one of them for any seed action (`canUseArea()`). |
| `security.access_checker` | `null` | `null`: `ConfigurableSeedKitAccessChecker` (the roles here, role hierarchy applied); `allow_all`: `AllowAllSeedKitAccessChecker` (demos; needs `allow_unauthenticated: true`); any other value: the service id of your `SeedKitAccessCheckerInterface`. |
| `security.allow_unauthenticated` | `false` | Lets a call without a user reach the checker outside the console (demos only). |
| `security.export_roles` | `[ROLE_ADMIN]` | `SEED_EXPORT`. |
| `security.apply_roles` | `[ROLE_ADMIN]` | `SEED_APPLY`. |
| `security.prune_roles` | `[]` | `SEED_PRUNE`: nobody until you map it. |
| `security.providers.<name>.{export,apply,prune}_roles` | `null` | Per-provider override of one action; `null` keeps the global list. |

The decision flow (checker, then the `nowo_seed_kit.access_check` event) is described in
[USAGE.md](USAGE.md#access-mapping).

## Timeouts

REQ-RUNTIME-001: SeedKit makes **no outbound HTTP call**, starts no process and fetches nothing: `$schema` in a
document is informational and never loaded, and the JSON Schema validator has no resolver. The bounds that exist:

| What | Bound |
| --- | --- |
| Lease of a (provider, scope) | `apply.lease_seconds` (300 s), renewed after every committed batch; a run that stops (crash, kill) frees it at expiry |
| One batch transaction | `apply.batch_size` rows (≤ 5 000) through one bounded provider query set; lock waits end with the database's lock-wait timeout, then up to `transaction.max_replays` attempts |
| Schema patterns | `pattern` keywords run with `pcre.backtrack_limit` lowered to 100 000 during validation and restored afterwards |
| Reader | Size and structure caps above; JSON Lines items are read line by line between batches |

A long apply belongs on the console (no web request timeout). In 0.2 a web upload runs a dry run only and big applies
go to Messenger; the hierarchy "operation < PHP `max_execution_time` < Caddy write timeout" is then documented with
the UI ([DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#timeouts)).

## Rules checked at build time

- `scope_kinds`: at least one kind, each matching `[a-z][a-z0-9_]{0,31}`.
- `doctrine.table_prefix` matches `^[a-z][a-z0-9_]{0,15}$` or is empty; `doctrine.mapping` is `xml` or `attribute`.
- Integer and float ranges of the tables above; `audit.log_level` is one of four levels.
- `security.access_checker: allow_all` needs `security.allow_unauthenticated: true`.
- `secrets.validator` and `secrets.vault_bridge.enabled` are exclusive; the Vault bridge needs `nowo-tech/vault-bundle`.
- `security.providers.*.*_roles` is `null` or a list of strings.
- Providers (compiler pass): every tag has a valid `name`, names are unique, every `depends_on` exists, no cycle (the
  message names the cycle's members).

## Translations

Console messages and row errors use the translation domain `NowoSeedKitBundle` with catalogues in 10 locales: `en`,
`es`, `it`, `fr`, `pt`, `de`, `nl`, `ca`, `eu`, `gl` (keys `seed_kit.error.*`, `seed_kit.read.*`, `seed_kit.row.*`).
Override a message in your application (your `translations/` directory wins over the bundle's catalogue):

```yaml
# translations/NowoSeedKitBundle.es.yaml
seed_kit:
    error:
        SEED_005: 'Los datos han cambiado desde el plan revisado; revisa el nuevo diff.'
```

Without `symfony/translation` the console prints the English fallback.

## Not configurable in 0.1

There is no dead configuration: the `ui` and `messenger` sections, `runs.document_ttl`, separate HTTP reader caps and
`messenger.trust_run_actor` arrive with the 0.2 UI and Messenger apply. The deviations from the contract are listed in
[CONTRACT.md](CONTRACT.md#24-deviations-in-010).
