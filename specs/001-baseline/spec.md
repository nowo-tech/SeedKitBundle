# Feature Specification: SeedKitBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-24
**Status**: Baseline of release 0.1.0 (core, console, provider contract test kit) extended by 0.2.0 (opt-in UI,
Messenger async apply, Scheduler purge)
**Input**: Design contract [`docs/CONTRACT.md`](../../docs/CONTRACT.md) v1.0 (answers and 0.1.0 deviations in §23–§24)
and the 0.2.0 source tree.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md),
[`docs/FORMAT.md`](../../docs/FORMAT.md), [`docs/SECURITY.md`](../../docs/SECURITY.md),
[`code-inventory.md`](code-inventory.md).

## Summary

SeedKitBundle gives a Symfony application one structure for seeds and bulk data: a **provider** per kind of data
(tag `nowo_seed_kit.provider` / `#[AsSeedProvider]`), documents in YAML, JSON or JSON Lines keyed by natural keys and
validated by a JSON Schema per provider, a **dry run** with a plan hash, and an idempotent batched **apply** through
the host's own handlers under leases, with access checks and audit. The configuration root is `nowo_seed_kit`
(strict). Main surfaces in 0.1.0: the provider SPI (`Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface`,
`AbstractSeedProvider`), the application entry points (`SeedOperations`), the console commands
`nowo:seed-kit:{list,schema,export,diff,apply,runs:list,runs:purge,actor:forget,lint}` with `seed:*` aliases, the
bundle tables `<prefix>seed_run` and `<prefix>seed_lease` (DBAL writes, read-only mapping) and the contract test kit
in `src/Testing`. 0.2.0 adds, all opt-in: guarded import / export pages mounted per scope kind (`ui.mounts`; upload, diff, apply,
discard, runs list, progress JSON; FormKit forms on UiKit scaffolds), stored runs (a planned run keeps its gzip
document, batch manifest and a TTL, and is applied or resumed by claim), the Messenger apply for large plans
(`ApplySeedRun`) and the Scheduler purge of expired runs (schedule `nowo_seed_kit`).

## User scenarios

### US-01 — Expose data as a provider (Priority: P1)

- **Given** a standard host, **when** it extends `AbstractSeedProvider` with `#[AsSeedProvider(name: 'x.y')]`,
  **then** the provider is listed by `seed:list`, its schema is published and linted, and it plans with `RowDiffer`.
- **Given** a DDD host, **when** it implements `SeedProviderInterface` and tags the service with `name` and
  `depends_on`, **then** it works the same without any bundle base class.
- **Given** two providers where one depends on an unknown one or both depend on each other, **when** the container is
  compiled, **then** the build fails naming the dependency or the cycle.

### US-02 — Export a template, the current rows and the schema (Priority: P1)

- **Given** a provider, **when** `seed:export <p> --empty` runs, **then** a commented template (key, personal-data and
  secret fields, every field with type and description, examples) is written.
- **Given** current rows, **when** `seed:export <p>` runs, **then** the rows the actor may read are written in
  natural-key order, without ids, with secrets as `vault:` references of the scope or omitted; applying that file
  plans 0 changes.
- **Given** a provider that returns a plain secret value, **when** it is exported, **then** the export fails closed.

### US-03 — Dry run (Priority: P1)

- **Given** a file with errors, **when** `seed:diff` runs, **then** every error is reported by row index, natural key,
  JSON Pointer and rule, never with the value, and nothing is written.
- **Given** a valid file, **when** `seed:diff` runs, **then** the plan shows creates, updates (changed fields),
  unchanged rows and deletions (with `--prune`) and a plan hash `sk1:…`.
- **Given** a hostile file (YAML tags, anchors, aliases, merge keys, oversize, deep nesting, duplicate keys, invalid
  UTF-8), **when** it is read, **then** it is refused with `SEED_002`.

### US-04 — Apply (Priority: P1)

- **Given** a reviewed plan hash, **when** `seed:apply --expect-hash` runs and the data changed meanwhile, **then**
  nothing is written (`SEED_005`).
- **Given** a plan larger than one batch, **when** a row changes during the run, **then** the run stops at that batch
  (`SEED_006`), earlier batches stay (`partially_applied`), and applying the file again converges.
- **Given** an applied file, **when** it is applied again, **then** nothing is written.
- **Given** two applies of one provider in one scope, **when** they overlap, **then** one holds the lease and the other
  gets `CONFLICT_001` with the seconds to wait.
- **Given** `--prune`, **when** the actor lacks `SEED_PRUNE`, the prune would delete more than `prune.max_ratio` of the
  rows, or the document is empty, **then** the apply is refused unless the explicit flag is given.

### US-05 — Access, secrets and audit (Priority: P1)

- **Given** the defaults, **when** a user without `ROLE_ADMIN` exports or applies, **then** it is denied; nobody may
  prune until mapped; a `nowo_seed_kit.access_check` listener can grant or deny and a deny sticks.
- **Given** an unknown or invisible scope, **when** any operation targets it, **then** it is refused (`ACCESS_001`).
- **Given** `--actor=system`, **when** used on the console, **then** it is accepted and audited as `system`; on any
  other channel it is refused.
- **Given** a secret field with plain text or an unusable reference, **when** a file is planned, **then** it is
  refused (`SEED_004`) without echoing the value.
- **Given** an apply or prune that passed the access check, or an export of a provider with personal-data or secret
  fields, **when** it ends, **then** an audit entry is recorded (every outcome for applies).

### US-06 — Worker mode and contention (Priority: P2)

- **Given** one kernel kept alive and not reset, **when** it serves 1 000 mixed exports, dry runs and applies for two
  actors, **then** no answer leaks the other actor's rows and memory stays flat.
- **Given** a host transaction already open, **when** a batch runs, **then** it is a savepoint of it.

### US-07 — Contract test kit (Priority: P2)

- **Given** a host provider test extending `SeedProviderContractTestCase`, **when** it runs, **then** it checks the
  schema lint, idempotency and round trip, stable exports without known secrets, refusal of escalating items and row
  visibility for a restricted actor.

### US-08 — Upload, review and apply in the UI (Priority: P1)

- **Given** the UI enabled and a kind mounted at a path, **when** a viewer with `SEED_EXPORT` and `SEED_APPLY` uploads
  a file for a provider, **then** the page shows the diff (creates, updates, unchanged, deletions, errors by row and
  pointer, never values) and a stored run with its plan hash and expiry.
- **Given** that run, **when** the same viewer confirms the apply form (CSRF-checked), **then** access and the plan
  hash are re-checked, the plan is applied once and the run page shows the outcome; a discard removes the stored
  document.
- **Given** a prune that would delete many rows, **when** the viewer applies, **then** the delete count must be typed
  to confirm.

### US-09 — Large apply queued to Messenger (Priority: P2)

- **Given** `messenger.enabled` and a plan with more changes than `async_threshold_changes`, **when** the viewer
  applies, **then** the run is claimed as queued, an `ApplySeedRun` message is dispatched and the progress JSON shows
  the batches done.
- **Given** the message is redelivered after a worker crash or a retryable failure, **when** the handler runs again,
  **then** the claim is taken by compare-and-set, the run resumes after `batches_done` and every batch is applied
  exactly once; a run already finished or claimed elsewhere is skipped.
- **Given** the `system` actor or a run whose actor may no longer apply, **when** the handler runs, **then** it is
  refused and the run fails without writing.

### US-10 — Viewer without access (Priority: P1)

- **Given** a mounted scope kind, **when** the viewer targets an unknown or invisible scope, **then** the answer is 404
  without revealing whether it exists.
- **Given** a visible scope, **when** the viewer lacks the area permission (export or apply), **then** the answer is
  403; another actor's run is never shown or applied.
- **Given** a current-rows export with personal-data or secret fields, **when** it is requested with GET, **then** a
  POST confirmation is required first.

## Functional requirements

### Bundle and DI

- **FR-BUNDLE-001**: The bundle registers in a standard host without configuration; services are built by explicit
  definitions; the read-only mapping of the two tables (XML by default, attributes on request) is prepended to the
  entity manager of `doctrine.entity_manager`; the AuditKit and Vault bridges are wired only when present and enabled.
- **FR-BUNDLE-002**: The `nowo_seed_kit` tree is strict (ranges, enums, cross-field rules: `allow_all` needs
  `allow_unauthenticated`, `secrets.validator` and the Vault bridge are exclusive, the Vault bridge needs its package).

### Provider SPI

- **FR-PROVIDER-001**: Providers implement `SeedProviderInterface` and are registered by tag or attribute; the
  compiler pass checks names, uniqueness, dependencies and cycles and fixes the dependency order; the registry is a
  lazy locator and refuses a provider whose name differs from its tag.
- **FR-PROVIDER-002**: `AbstractSeedProvider` (optional) plans with `RowDiffer` (changed fields, fingerprints,
  `x-seed-omit-means`), returns schema examples as the template and has no-op defaults.

### Identifiers

- **FR-ID-001**: Provider names, scope kinds and ids, actor refs, natural keys, run ids and document hashes are value
  objects with exact-text rules (no trimming or folding, control / format characters refused), compared byte for byte.

### Documents and reader

- **FR-READ-001**: YAML (multi-document), JSON (one object or `documents`) and JSON Lines (streamed) are read into a
  document set with a raw-bytes hash; only the known top-level and `meta` keys are accepted.
- **FR-READ-002**: Reader caps (bytes, tree, documents, items, depth, line) and hardening (UTF-8 only, YAML tags /
  anchors / aliases / merge keys / directives refused, no objects / constants / dates, JSON duplicate keys refused).

### Schema

- **FR-SCHEMA-001**: Items are validated against the provider's item schema (draft 2020-12, opis/json-schema, no
  resolver, reduced backtrack limit); violations carry pointer and keyword only.
- **FR-SCHEMA-002**: The schema lint (command, cache warmer, test kit) and the published document schema (envelope,
  hoisted `$defs`, `maxItems`).

### Export

- **FR-EXPORT-001**: Empty template or current rows in YAML (with header comments), JSON or JSON Lines; natural-key
  order checked; properties in schema order, read-only last; secrets as checked references or omitted; contract
  breaches fail closed.

### Plan

- **FR-PLAN-001**: The planner prepares documents (provider, version, scope kind, duplicates, dependency order),
  normalises items (structure budget, floats, key normalisers, schema, secrets, read-only removal), plans slices of
  `apply.batch_size` through the provider, re-checks keys and order, applies prune guards and masks values the actor
  may not see; any error stops before any write.
- **FR-PLAN-002**: Plan hash and batch digests over canonical JSON (RFC 8785 rules), with the golden vectors of
  `docs/PLAN-HASH.md`.

### Apply

- **FR-APPLY-001**: The applier takes the leases in name order, re-plans and refuses a stale plan, records the run,
  applies writing batches in write units with a locked re-plan and digest check, records progress in the same
  transaction, calls `afterBatch()`, renews leases, finishes the run and dispatches events; a plan without changes
  writes nothing.
- **FR-APPLY-002**: Prune is opt-in, needs `SEED_PRUNE`, is guarded (mass ratio, empty document) and deletes in reverse
  dependency order after all upserts.

### Concurrency

- **FR-CONCURRENCY-001**: Write units of transaction-doctrine on the bundle's connection with a registry holding no
  entity manager; replays (`transaction.max_replays`) for deadlocks, lock waits and serialization failures; lease
  statements with conditional updates; `CONFLICT_001` / `CONFLICT_002` semantics.

### Storage

- **FR-STORAGE-001**: `<prefix>seed_run` and `<prefix>seed_lease` written through DBAL with bound parameters; table
  prefix applied to mapping and SQL; binary NO PAD identifier columns on MySQL / MariaDB; read-only entities.
- **FR-STORAGE-002**: Run history list, purge of finished runs older than `runs.history_days` in batches, and actor
  forget (actor nulled, history kept).

### Secrets

- **FR-SECRET-001**: Secret fields accept only `vault:` references validated by the secret-reference port (fail closed
  without one), absent means keep, plaintext never echoed; exports carry references of the scope only.
- **FR-SECRET-002**: The optional Vault bridge checks item existence, scope ownership and, on import, the actor's view
  access.

### Access

- **FR-ACCESS-001**: Every export, plan and apply resolves the scope and asks the guard: checker (roles by default,
  per-provider overrides, role hierarchy) then the `nowo_seed_kit.access_check` event (a deny sticks); deny by default;
  console granted by default and deniable.
- **FR-ACCESS-002**: `SeedKitVoter` supports `SEED_EXPORT`, `SEED_APPLY`, `SEED_PRUNE` on `SeedPermissionSubject`.
- **FR-ACCESS-003**: Scope and actor resolver ports with platform-only and security-user defaults; the `system` actor
  only on the console channel.

### Stored runs

- **FR-RUN-001**: A stored UI run is a planned run row carrying the gzip-compressed document (codec port), the batch
  manifest, the plan hash, the actor and an expiry (`runs.document_ttl`, default `PT1H`); an identical pending upload
  of the same actor and document reuses its run; `SeedRuns` plans, stores and reads runs through `RunStoreInterface`.
- **FR-RUN-002**: A run is claimed by compare-and-set (`planned` or interrupted to `applying` or `queued`); apply
  re-checks access and the plan hash, applies the remaining batches (resuming after `batches_done`), reports an
  `ApplyOutcome`, can be queued (`RunQueueInterface`) or discarded; unavailable, expired or foreign runs raise
  `RunNotAvailableException`, plans over `ui.max_sync_changes` without a queue raise `PlanTooLargeException`.
- **FR-RUN-003**: Expired stored documents are cleared and expired runs purged in batches (`RunMaintenance`, command
  `runs:purge`, scheduled purge).

### UI

- **FR-UI-001**: With `ui.enabled`, the route loader (`resource: . type: nowo_seed_kit`) mounts the pages
  `nowo_seed_kit_<kind>_<page>` (index, export, schema, upload, runs, run, run_apply, run_discard, run_progress) under
  each `ui.mounts` path; the guard resolves the scope (current scope port or route) and checks the area: unknown or
  invisible scope 404, denied area 403, `system` actor refused; schema downloads and current-row exports of
  personal-data or secret providers need a POST confirmation.
- **FR-UI-002**: Upload (dialog or page, `ui.max_upload_bytes`, `ui.max_items`), diff, apply, discard, runs list
  (`ui.runs_per_page`, bound to the actor) and progress JSON (`ui.poll_interval_ms`); mass prune needs the delete
  count typed; errors never echo values.
- **FR-UI-003**: Forms (upload, apply, discard) are FormKit forms with CSRF protection and a form profile.
- **FR-UI-004**: Twig templates on UiKit scaffolds (index, upload page and fragment, upload errors, runs, run,
  message) honouring `ui.layout`, breadcrumbs and translations.

### Messenger and Scheduler

- **FR-ASYNC-001**: With `messenger.enabled`, plans over `messenger.async_threshold_changes` are queued: the
  `ApplySeedRun` message carries the run id only; its handler resumes the run by claim, applies the batches once under
  a retry policy (retryable conflicts retry, others fail the run), refuses the `system` actor and, unless
  `messenger.trust_run_actor`, re-checks the actor's access; `MessengerRunQueue` implements the queue port.
- **FR-ASYNC-002**: With `scheduler.enabled`, the schedule `nowo_seed_kit` dispatches `PurgeSeedRuns` at
  `scheduler.frequency`; its handler runs the expired-run purge.

### Audit and events

- **FR-AUDIT-001**: Audit records through `SeedAuditSinkInterface` (PSR-3 default, AuditKit bridge when its recorder
  service exists) for applies and prunes after the access check and sensitive exports; no values, keys or file names.
- **FR-EVENT-001**: Domain events after commit carrying value objects and ids only.

### Errors

- **FR-ERROR-001**: Coded exceptions (`SEED_001` … `SEED_007`, `SEED_010` … `SEED_012`, `ACCESS_001` / `002`,
  `CONFLICT_001` / `002`) extending `SeedKitException`, static messages with translation keys.

### Console

- **FR-CLI-001**: The nine commands of [`docs/USAGE.md`](../../docs/USAGE.md#console-reference) with `seed:*` aliases
  (switchable), stderr for messages, stdout for data, plan printing as text or JSON.
- **FR-CLI-002**: Actor handling (`--actor`, `console.require_actor`), confined file paths (`console.allowed_dirs`,
  no wrappers, regular files, no silent overwrite) and the exit codes 0–7 and 9.

### Contract test kit

- **FR-TESTKIT-001**: `SeedProviderContractTestCase` with its hooks and five tests, plus in-memory support doubles,
  shipped in `src/Testing` (PHPUnit suggested).

### Translations

- **FR-I18N-001**: Ten catalogues (ca, de, en, es, eu, fr, gl, it, nl, pt) in the domain `NowoSeedKitBundle` with the
  same keys.

## Key entities

| Entity | Meaning |
| --- | --- |
| Provider | Host service for one kind of data (`SeedProviderInterface`) |
| `SeedScope` | Target scope: kind + optional id, resolved by the host |
| `NaturalKey` | 1–4 exact components identifying a row |
| `DocumentSet` / `SeedDocument` / `SeedItem` | A read file, its documents and their items |
| `SeedPlan` / `RowChange` / `PlanHash` | The dry run, one change per row, the reviewed hash |
| `RunRecord` | A row of the run history |
| Stored run | A planned run with its gzip document, batch manifest and expiry, applied or resumed by claim |
| `BatchManifest` | Per-batch digests of a stored run, used to resume after `batches_done` |
| `ApplySeedRun` | Messenger message (run id) that applies a queued run |

## Success criteria

- **SC-001**: Every file under `src/` appears in [`code-inventory.md`](code-inventory.md): **185 files mapped at
  0.2.0** (185 / 185, `find src -type f | wc -l`).
- **SC-002**: Exporting the current rows and applying the file plans 0 changes; a second apply writes nothing to the
  host tables (database test and contract test kit).
- **SC-003**: The query count per batch is constant (database test); JSON Lines files are read line by line.
- **SC-004**: The access matrix (no user, roles, checker, event deny, voter, console, system actor) answers as
  documented (integration tests).
- **SC-005**: No known secret value appears in any export (contract test kit, exporter tests).
- **SC-006**: PHPUnit (Unit, Integration, Database on SQLite; MySQL 8.4, MariaDB 11.4 and PostgreSQL in CI) passes and
  `src/` coverage is 100 %; PHPStan level max with the `phpstan-frankenphp` rulesets, Deptrac, Igor, PHP-CS-Fixer and
  Rector are green.
- **SC-007**: Without the UI, Messenger and Scheduler options nothing is mounted or registered (route loader, queue and
  schedule services absent); a viewer without access gets 404 / 403 and no stored run is created.

## Non-goals

- A replacement for CRUD pages, migrations or Doctrine fixtures; direct SQL or entity writes on host data; a generic
  Doctrine entity provider.
- Cross-instance sync, scheduled imports (only the run purge is scheduled), AI generation inside the bundle, CSV / XLSX, includes between files.
- A public JSON API and MCP tools in 0.x (the UI, Messenger apply and Scheduler purge shipped in 0.2.0; the only JSON
  endpoint is the run progress of the UI).

## Assumptions

- Providers write through the host's handlers on the bundle's connection (otherwise batches are at-least-once).
- The host's scope resolver hides scopes the actor may not see; the host maps permissions with roles, a checker or the
  access event.

## Validation commands

```bash
make test-coverage-100   # PHPUnit + 100 % of src/
make test-with-db        # database suite on a server
make phpstan igor        # static analysis and worker-state audit
composer deptrac         # layer rules
composer qa              # cs-check + test
make release-check       # the whole pre-release chain
```
