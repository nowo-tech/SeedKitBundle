# Seed kit bundle (SeedKitBundle). Contract v1.0

Status 2026-11-24: **v1.0, answered.** The owner accepted every Q-SK question as recommended and Symfony Beacon
answered QB-SK-1 … QB-SK-6 on 2026-11-24 ([§23](#23-answers)). Requested by Beacon (owner decision D-20261124-10,
spec `257`). Repository `nowo-tech/SeedKitBundle` (public, MIT); the owner registers `nowo-tech/seed-kit-bundle` on
Packagist before the first tag. What 0.1.0 implements differently from the text below is listed in
[§24](#24-deviations-in-010); the code and [CONFIGURATION.md](CONFIGURATION.md) are the reference for 0.1.0.

## Table of contents

- [Summary (10 lines)](#summary-10-lines)
- [Sources read for this contract](#sources-read-for-this-contract)
- [0. Findings that shaped the contract](#0-findings-that-shaped-the-contract)
- [1. Purpose and non-goals](#1-purpose-and-non-goals)
- [2. Package](#2-package)
- [3. Shape](#3-shape)
- [4. Layout and architecture rules (REQ-ARCH-001 / 002)](#4-layout-and-architecture-rules-req-arch-001--002)
- [5. Domain model (all `final readonly`, validated in the constructor)](#5-domain-model-all-final-readonly-validated-in-the-constructor)
  - [5.1 Identifiers (REQ-SEC-006: exact, no trimming, no folding)](#51-identifiers-req-sec-006-exact-no-trimming-no-folding)
  - [5.2 Documents and items](#52-documents-and-items)
  - [5.3 Plan](#53-plan)
  - [5.4 Enums](#54-enums)
  - [5.5 Exceptions (`Domain\Exception`, base `SeedKitException`) and codes](#55-exceptions-domainexception-base-seedkitexception-and-codes)
- [6. Provider SPI](#6-provider-spi)
- [7. Schema format](#7-schema-format)
  - [7.1 Envelope](#71-envelope)
  - [7.2 Allowed JSON Schema subset and `x-seed-*` keywords](#72-allowed-json-schema-subset-and-x-seed--keywords)
  - [7.3 Schema lint (`nowo:seed-kit:lint`, warmer, test kit)](#73-schema-lint-nowoseed-kitlint-warmer-test-kit)
- [8. Document and export format](#8-document-and-export-format)
- [9. Plan hash and batch digests](#9-plan-hash-and-batch-digests)
- [10. Apply semantics](#10-apply-semantics)
  - [10.1 Order](#101-order)
  - [10.2 Steps](#102-steps)
  - [10.3 Atomicity, concurrency and conflicts (REQ-RUNTIME-003)](#103-atomicity-concurrency-and-conflicts-req-runtime-003)
  - [10.4 Prune](#104-prune)
  - [10.5 Async (Messenger, 0.2)](#105-async-messenger-02)
- [11. Storage](#11-storage)
- [12. Access, events, audit](#12-access-events-audit)
  - [12.1 House access pattern (REQ-UI-002) + voter attributes (FR-006)](#121-house-access-pattern-req-ui-002--voter-attributes-fr-006)
  - [12.2 Events (`Domain\Event`, after commit, VOs and ids only)](#122-events-domainevent-after-commit-vos-and-ids-only)
  - [12.3 Audit (FR-009)](#123-audit-fr-009)
  - [12.4 Console (`nowo:seed-kit:*`; `seed:*` aliases per Q-SK-8)](#124-console-nowoseed-kit-seed-aliases-per-q-sk-8)
  - [12.5 UI (0.2.0; UiKit + FormKit, REQ-UI-001 / 003, REQ-DEMO-014 / 015)](#125-ui-020-uikit--formkit-req-ui-001--003-req-demo-014--015)
- [13. Security model](#13-security-model)
  - [13.1 Threats and controls](#131-threats-and-controls)
  - [13.2 Secrets (FR-007)](#132-secrets-fr-007)
  - [13.3 Reader hardening](#133-reader-hardening)
  - [13.4 Files and paths](#134-files-and-paths)
- [14. Privacy (GDPR)](#14-privacy-gdpr)
- [15. Configuration tree](#15-configuration-tree)
- [16. Worker mode (FrankenPHP, kernel reset false; REQ-RUNTIME-002)](#16-worker-mode-frankenphp-kernel-reset-false-req-runtime-002)
- [17. Standard vs DDD host (REQ-ARCH-001)](#17-standard-vs-ddd-host-req-arch-001)
- [18. Phases](#18-phases)
- [19. Test strategy (PHPUnit, 100 % PHP coverage, PHPStan max, real databases)](#19-test-strategy-phpunit-100--php-coverage-phpstan-max-real-databases)
- [20. Docs set](#20-docs-set)
- [21. Beacon integration sketch (informative)](#21-beacon-integration-sketch-informative)
- [22. REQ mapping](#22-req-mapping)
- [23. Answers](#23-answers)
  - [23.1 Owner (Q-SK)](#231-owner-q-sk)
  - [23.2 Symfony Beacon (QB-SK)](#232-symfony-beacon-qb-sk)
- [24. Deviations in 0.1.0](#24-deviations-in-010)
- [25. Deviations in 0.2.0](#25-deviations-in-020)

## Summary (10 lines)

1. One structure for seeds and bulk data: a **provider** per kind of data (menus, API Studio services, project roles,
   CRM contacts…), registered by the tag `nowo_seed_kit.provider`; the bundle owns the file format, validation, diff,
   plan hash, batching, locking, access, audit, console and UI; the host owns the data and writes through its own
   application handlers.
2. Documents are YAML (default), JSON or JSON Lines: `provider`, `version`, `scope`, `$schema`, `items`, rows keyed by
   **natural keys** (never database ids); exports are *empty* (commented template) or *current* (round-trip stable).
3. Each provider publishes a **JSON Schema** (draft 2020-12 subset + `x-seed-*` keywords for natural key, secret,
   personal data and references); the document is validated before any planning; errors name row index, natural key,
   field and rule, never the value.
4. **Dry run** = plan as a diff (create / update / unchanged / delete, changed fields per row) plus a **plan hash**
   (SHA-256 over a canonical change stream); **apply** re-plans and refuses a stale plan.
5. Apply runs in **batches, one transaction each**, under a **lease per (provider, scope)**; each batch re-plans its
   slice inside the transaction and must match the batch digest of the reviewed plan; progress is committed with the
   batch, so a replay (Messenger, retry, crash) never applies a batch twice; re-applying a file changes nothing.
6. **Prune** (delete rows the file omits) is opt-in per call and needs its own permission (`SEED_PRUNE`, Q-SK-13).
7. Access: voter attributes `SEED_EXPORT` / `SEED_APPLY` / `SEED_PRUNE` on a `(provider, scope)` subject, implemented
   with the house pattern (`security.*` keys, `SeedKitAccessCheckerInterface`, `SEED_ACCESS_CHECK` event, `SeedKitGuard`),
   deny by default for anything not mapped; scopes (platform / organization / project or any host kind) are resolved
   by a host port.
8. Secrets never leave in clear: schema-marked secret fields export as `vault:<ref>` or are omitted, and import accepts
   only a reference that a host / Vault port validates; plaintext is refused without being echoed.
9. Hardened reader: size, item, depth, string and node caps; YAML without tags, objects, constants, anchors or aliases;
   no file paths from requests; console paths confined to allowed directories; streaming for JSON Lines.
10. House bars from the first PR: REQ-ARCH-001 / 002 (Deptrac), REQ-RUNTIME-002 / 003, REQ-SEC-006, REQ-UI-002 / 003,
    FormKit, 7 locales, PHPStan max, 100 % coverage, standard + DDD hosts; **0.1.0 = core + console**, **0.2.0 = UI +
    Messenger** (Q-SK-1), each with an immediate security audit before its tag (an import is a write path).

## Sources read for this contract

| Source | Version read | What was read |
| --- | --- | --- |
| Beacon request | `docs/ops/BUNDLES-REQUESTS/2026-11-24-seed-kit.md` @ `feat/1.176.0` | FR-001 – FR-010, adoption plan |
| Beacon spec 257 | `specs/257-seed-kit/{spec,tasks}.md` @ `feat/1.176.0` | Clarifications table, scenarios 1–5, FR-001 – FR-014, SC-001 – SC-005, out of scope, blocks A – E, Beacon mount paths (`/settings/seeds`, `/organizations/{uuid}/seeds`, `/projects/{id}/settings/seeds`) |
| CurrencyKitBundle contract | `.uic-work/currencykit-1119/CONTRACT.md` v0.1 (answered) | House shape of a new-bundle contract; DBAL-on-configured-connection storage; lease pattern; console-access precedent (audit VR Q1) |
| CrmKitBundle | `.uic-work/crmkit-0101` (0.10.1) | `ImportService` (claims, chunked transactions, `import.max_rows`), `Api\Operation` layer decision, house access pattern |
| House rules | `bundles/BUNDLES_FULL_SPECS_DETAILS.md`, `BUNDLES_FIX_RULES.md` | REQ-ARCH-001 (rule 3 strict), REQ-ARCH-002, REQ-RUNTIME-002 / 003, REQ-SEC-005 / 006, REQ-UI-002 / 003, REQ-DEMO-014 / 015, REQ-ASSETS-005, public API path rule |
| AuditKitBundle | local `v1.16.0` | `ActionLogRecorderInterface::record()` / `recordAndFlush()` |
| VaultBundle | local `v1.19.3` | No `vault:` reference syntax exists today; items are UUID-identified and owned by a user or a scope (QB-SK-2) |
| Owner memories | 2026-11-10 … 2026-11-24 | Worker mode without reset, standard + DDD bar, house access pattern, API paths, runtime settings env/DB, Deptrac / PER-CS, "touched bundle = 100 %", batch vs immediate audits, Packagist by owner |

## 0. Findings that shaped the contract

| # | Finding | Effect |
|---|---|---|
| F1 | "Nothing written when any row fails" (FR-003) and "one transaction per batch" (FR-005) conflict for files larger than one batch: a batch can fail after earlier ones committed | Everything that can fail is moved **before** the first write: parse, schema, provider domain checks (`planBatch` may return row errors), references, secrets, access. A failure after that (lock conflict, row changed meanwhile, handler bug) stops the run, reports the committed batches (`partially_applied`, `CONFLICT_002` style) and a re-apply converges (idempotent). Small plans (≤ one batch) are atomic. Q-SK-3 |
| F2 | A plan hash computed once at dry-run time does not protect the window between the re-plan and the writes of batch *n* | Two checks: (a) apply re-plans the whole document and compares the hash; (b) each batch re-plans its slice **inside** its transaction with row locks and compares a per-batch digest stored with the run (§9) |
| F3 | Symfony YAML has no streaming parser; a 10 000-row CRM file (SC-003) parsed as one YAML/JSON tree costs several times its size in memory | YAML / JSON are read whole only up to `reader.max_tree_bytes` (default 4 MiB); larger files must be **JSON Lines** (header line + one item per line), read line by line with a per-line cap; no new parser dependency. Q-SK-4 |
| F4 | YAML aliases make a small file expand into a huge tree (billion laughs); Symfony YAML tags can build objects / read constants | Reader: no `PARSE_OBJECT*`, `PARSE_CONSTANT`, `PARSE_CUSTOM_TAGS`, `PARSE_DATETIME`; any tag, anchor or alias refused by a pre-scan; node budget during normalisation as a second line (§13.3) |
| F5 | The UI flow is upload → diff → apply across requests, and large applies go to Messenger: the document must live somewhere between steps | A bundle-owned **run** row stores the normalised document (gzip) for a short TTL, then the blob is deleted and only metadata stays (Q-SK-5). Console runs need no storage |
| F6 | VaultBundle has no `vault:<ref>` syntax; secrets in Beacon pilots (API Studio service credentials) may live in Vault or in encrypted columns | `SecretReference` VO + `SecretReferenceValidatorInterface` port (fail closed when none); an optional VaultBundle bridge; a provider whose stored secret is not a reference **omits** the field on export, and an omitted secret means "keep the current value" (Q-SK-6, QB-SK-2) |
| F7 | Host handlers (the CRUD's own application services) usually flush the host EM; REQ-RUNTIME-003 rule 2 forbids the **bundle** from flushing / clearing it | SeedKit only opens the batch transaction on the configured connection (transaction-contract runner) and never touches any EM; the provider (host code) decides flushes; an `afterBatch()` hook lets it clear its own state so memory stays flat |
| F8 | With Symfony's default `affirmative` strategy, a host voter that grants wins over a bundle voter that denies | The bundle's voter is the default mapping (roles + event, deny by default); a host maps permissions either with an `access_checker`, a `SEED_ACCESS_CHECK` listener, or its own voter for the three attributes (documented: a host voter **grants**, it cannot be overridden by the bundle) |
| F9 | Row-level visibility (a manager sees only some CRM contacts) cannot be known by the bundle | The provider receives the actor in `SeedContext` and must export, plan and prune only the rows that actor may read / write; the contract test kit checks it with two actors (§19) |
| F10 | Exported CRM contacts are personal data leaving the system as a file; uploaded files sit in the run table | Schema flag `x-seed-personal-data`; UI notice before download; exports of such providers are audited; run blobs deleted at the end of the run or after the TTL; errors and logs never contain values (§14) |
| F11 | The brief names `seed:list` … `seed:apply`; house commands are `nowo:<bundle>:…` | Canonical `nowo:seed-kit:*` with optional `seed:*` aliases (Q-SK-8) |

## 1. Purpose and non-goals

**Purpose.** Give any Symfony host a second door to its own data, next to the CRUD pages: per kind of data, export an
empty or current file, edit it by hand or with an AI using the published schema, upload it, read the diff, apply it.
Same validation, events and audit as the CRUD, because the provider writes through the host's handlers. First host:
Beacon (pilots: menus / breadcrumbs, API Studio services, project roles, CRM companies and contacts; then
`app:seed-platform` and the other `app:seed-*` commands).

**Non-goals (0.x):**

- Not a replacement for CRUD pages, migrations, or Doctrine fixtures for tests (a host may use it for dev seeds).
- No direct SQL or entity writes by the bundle on host data; no generic "Doctrine entity provider" (it would bypass
  handlers; Q-SK-10 only offers a scaffolding command later).
- No cross-instance sync, scheduled imports, Git-backed seed repository, binary assets, AI generation inside the bundle
  (spec 257 out of scope).
- No CSV / XLSX (the CRM keeps its own CSV import); no includes between files.
- No public JSON API or MCP tools in 0.x (Q-SK-9); Application operations are shaped so they can be added.
- Docs describe what the bundle does; they never compare it with or cite other products.

## 2. Package

| Item | Proposal |
|---|---|
| Name | `SeedKitBundle`, Composer `nowo-tech/seed-kit-bundle`, GitHub `nowo-tech/SeedKitBundle` (public, MIT) |
| Namespace / class | `Nowo\SeedKitBundle` / `NowoSeedKitBundle`; config root `nowo_seed_kit`; Twig `@NowoSeedKit`; translation domain `NowoSeedKitBundle` (en, es, ca, eu, gl, fr, pt); tag `nowo_seed_kit.provider`; attribute `#[AsSeedProvider]`; routes `nowo_seed_kit_*` |
| PHP / Symfony | `>=8.2 <8.6`; `^7.4 \|\| ^8.0`; demos Symfony 7.4 + 8.1 on FrankenPHP worker, MySQL |
| Runtime deps | `symfony/{framework-bundle,config,dependency-injection,http-kernel,console,yaml,uid,clock,event-dispatcher}`, `doctrine/dbal ^3.8.2 \|\| ^4.0`, `doctrine/doctrine-bundle`, `doctrine/orm` (read-only mapping of the bundle tables, as CurrencyKit), `opis/json-schema ^2.4` (Q-SK-2), `nowo-tech/transaction-contract ^1.0`, `nowo-tech/transaction-doctrine ^1.7`, `psr/log`, `psr/clock`, `ext-json`, `ext-zlib` |
| Suggest | `symfony/security-bundle` (required when `ui.enabled` or voters used), `symfony/messenger` (async apply), `symfony/scheduler` (run purge), `nowo-tech/ui-kit-bundle` + `nowo-tech/form-kit-bundle` (UI, latest minors at build time), `nowo-tech/audit-kit-bundle` (audit bridge), `nowo-tech/vault-bundle` (secret-reference bridge), `nowo-tech/breadcrumb-kit-bundle` (crumbs) |
| Not used | No front-end framework; no own TypeScript in 0.x (UiKit dialogs and polling only; if TS is ever added, REQ-ASSETS-001 … 005 apply) |
| First tag | 0.1.0 after S1 and an immediate security audit; 1.0.0 after `bundle-req-audit` 100 % and SEC-004 |

## 3. Shape

```
                                   Host (Beacon, any Symfony app)
  ports (host implements / may replace) ────────────────────────────────────────────────────────
  SeedProviderInterface* (tagged) · SeedScopeResolverInterface · SeedActorResolverInterface
  SeedKitAccessCheckerInterface · SecretReferenceValidatorInterface · SeedAuditSinkInterface
  ─────────────────────────────────────────────────────────────────────────────────────────────
  UI (opt-in, 0.2)                          Bridges (auto when installed, switchable)
    Import / export page per scope kind       AuditKit (audit sink) · Vault (secret refs)
    upload dialog · diff · apply · runs       Messenger (async apply) · Scheduler (purge) · BreadcrumbKit
  Console  nowo:seed-kit:{list,schema,export,diff,apply,runs:list,runs:purge,actor:forget,lint}
  ─────────────────────────────────────────────────────────────────────────────────────────────
  Application  ProviderRegistry · DocumentReader/Writer ports · SchemaValidator port · Planner · PlanHasher
               Applier (batches, lease, digests) · Exporter · SeedOperations (access re-check per call)
  Domain       VOs · SeedPlan/RowChange · enums · ports · events · exceptions (plain PHP)
  Infra        YAML / JSON / JSONL readers & writers · opis adapter · DBAL run + lease store · write units
```

The bundle owns: format, reader limits, schema validation, diff, plan hash, ordering, batching, leases, run storage,
access gate, audit calls, console, UI. The host owns: providers (data, handlers, row-level visibility), scope
resolution, permission mapping, actor id, secret-reference policy, mount paths.

## 4. Layout and architecture rules (REQ-ARCH-001 / 002)

```
src/Domain/          VOs, enums, SeedPlan, RowChange, ports (SPI), events, exceptions        (plain PHP)
src/Application/     registry, planner, applier, exporter, hasher, SeedOperations           (ports only)
src/Infrastructure/Reader/        YamlDocumentReader, JsonDocumentReader, JsonLinesDocumentReader, YamlPreScanner
src/Infrastructure/Writer/        YAML / JSON / JSONL writers, EmptyTemplateRenderer
src/Infrastructure/Schema/        OpisSchemaValidator, SchemaLinter, CanonicalJson
src/Infrastructure/Dbal/          DbalRunStore, DbalLeaseStore, write units, collation listener, reference DDL
src/Infrastructure/Doctrine/      read-only entities SeedRun / SeedLease + XML mapping + attribute twins
src/Infrastructure/Symfony/       DI (compiler pass: names, cycles, order), config tree, commands, controllers,
                                  forms, voter, guard, Messenger handler, Twig
src/Bridge/{AuditKit,Vault,Messenger,Scheduler,BreadcrumbKit}/
src/Testing/                      SeedProviderContractTestCase (shipped in autoload so hosts extend it; phpunit/phpunit
                                  in suggest; own Deptrac layer "Testing", nothing in src/ depends on it)
src/Resources/{config,views,translations}/
```

- Deptrac (`deptrac.yaml` from `.uic-work/deptrac-pilot/TEMPLATE.md`): layers Domain, Application, Infrastructure,
  Bridge, UI (controllers, forms, console, voter, guard, Twig, Messenger handler), DependencyInjection; strict, no
  wildcards, `--fail-on-uncovered`, `debug:unassigned` clean. Only `skip_violations` allowed by REQ-ARCH-002 rule 8
  (Application catching transaction-doctrine `ConcurrencyConflict`), each with its removal note.
- `tests/Architecture`: no `persist(`, `flush(`, `clear(`, `resetManager(` in `src/` (outside `src/Testing`); no
  Symfony / Doctrine / HTTP types in `Domain` signatures (rule 3, no edge exception: controllers convert `Request` /
  `UploadedFile` into `UploadedDocument` VO before calling Application).
- Public surface = interfaces + VOs + events + enums; nothing to extend except the optional `AbstractSeedProvider`
  convenience base (standard hosts) whose every method is also reachable by implementing the interface (DDD hosts).

## 5. Domain model (all `final readonly`, validated in the constructor)

### 5.1 Identifiers (REQ-SEC-006: exact, no trimming, no folding)

| VO | Rule |
|---|---|
| `ProviderName` | `\A[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){0,3}\z` (`crm.contacts`, `navigation.menus`), ≤ 64 bytes, `/D` |
| `ScopeKind` | `\A[a-z][a-z0-9_]{0,31}\z`, must be a configured kind (`platform`, `organization`, `project` in Beacon) |
| `ScopeId` | 1–128 bytes, valid UTF-8, no control / format chars (ZWSP, BOM, bidi), no edge whitespace / NBSP; compared with `===` |
| `SeedScope` | `kind` + `?ScopeId` (null only for kinds declared `requires_id: false`); `key()` = `kind` or `kind:id` |
| `NaturalKey` | Ordered tuple of 1–4 components, each a string (≤ 255 bytes, rules of `ScopeId`) or int; canonical form = canonical JSON array; equality on canonical bytes (`Bob` ≠ `bob` ≠ `bob `); a provider may declare a key normaliser in the schema (`x-seed-key-normalise: lower` for e-mail) applied **before** validation and shown in the diff, never silently at match time |
| `RunId` | UUID v7 |
| `ActorRef` | 1–190 bytes, rules of `ScopeId`; `system` reserved for console without `--actor` only when `console.require_actor: false` |
| `SecretReference` | `vault:` + `\A[A-Za-z0-9][A-Za-z0-9._:/#-]{0,199}\z` (`/D`); opaque to the bundle |
| `DocumentHash` | `sha256:` + 64 hex of the **raw uploaded bytes** (audit, duplicate upload detection) |
| `PlanHash` | `sk1:` + 64 hex (§9) |

### 5.2 Documents and items

| Type | Content |
|---|---|
| `SeedDocumentHeader` | `ProviderName provider`, `int version` (1–999), `ScopeKind scope`, `?string schemaRef` (informational), `SeedDocumentMeta meta` (`?exportedAt`, `?ScopeId exportedFrom`, `?string generator` ≤ 64) |
| `SeedItem` | `int index` (0-based position in the document), `NaturalKey key`, `array<string, JsonValue> data` (JSON-compatible: null, bool, int, string, list, map; floats only where the schema says `x-seed-float`) |
| `SeedItemStream` | `iterable<SeedItem>` with `header()`, read once (generators; JSON Lines streams) |
| `UploadedDocument` | `string bytesOrPath` handle (bundle temp stream), `DocumentFormat format`, `int size`, `DocumentHash hash`, `string clientName` (display only, never a path) |

### 5.3 Plan

```php
final readonly class RowChange
{
    public ChangeOp $op;                 // Create | Update | Unchanged | Delete
    public NaturalKey $key;
    public ?int $index;                  // item index in the document (null for Delete)
    /** @var list<FieldChange> */       // Update: changed fields only; Create: all set fields; Delete: none
    public array $fields;
    public ?string $currentFingerprint;  // provider-supplied: version column or sha256 of canonical current values (null for Create)
    public ?string $desiredFingerprint;  // sha256 of the canonical normalised item (null for Delete)
}

final readonly class FieldChange
{
    public string $path;                 // JSON Pointer inside the item (/address/city)
    public FieldValueView $before;       // value, or Hidden (secret / no export permission), or Absent
    public FieldValueView $after;
}

final readonly class RowError           // plan-time domain error from the provider or the bundle
{
    public int $index; public ?NaturalKey $key; public string $path; public string $rule; public string $messageKey;
    /** @var array<string, scalar> */ public array $messageParams;   // never the submitted value
}
```

`SeedPlan`: per document `DocumentPlan` (provider, version, scope, counts by op, `list<RowChange>` up to
`plan.preview_rows` per op for display, `list<RowError>`, batch digests), plus the run-level `PlanHash`, `prune`, and
`dependencyOrder`. The full change stream is never held in memory: it is hashed and digested as it is produced, and
the console prints it as it goes; the UI shows the preview and offers "download full diff" (recomputed, JSON).

### 5.4 Enums

`ChangeOp { Create; Update; Unchanged; Delete }`, `DocumentFormat { Yaml; Json; JsonLines }`, `ExportMode { Empty;
Current }`, `SeedAction { Export; Apply; Prune }` (voter attributes `SEED_EXPORT`, `SEED_APPLY`, `SEED_PRUNE`),
`RunStatus { Planned; Queued; Running; Applied; PartiallyApplied; Failed; Discarded; Expired }`, `Channel { Http;
Console; Messenger }`.

### 5.5 Exceptions (`Domain\Exception`, base `SeedKitException`) and codes

| Code | Exception | When | HTTP (UI) / console exit |
|---|---|---|---|
| `SEED_001` | `UnknownProviderException` | Provider name or version not registered / not allowed in the scope kind | 404 / 2 |
| `SEED_002` | `DocumentReadException` | Size, format, parse, caps, tag / anchor / alias, unknown top-level key, multi-doc rules | 422 / 2 |
| `SEED_003` | `DocumentInvalidException` | Schema or plan-time row errors (carries `list<RowError>`, capped at `validation.max_errors`) | 422 / 2 |
| `SEED_004` | `SecretValueRefusedException` | Plaintext in a secret field, or a reference the validator refuses | 422 / 2 |
| `SEED_005` | `StalePlanException` | Re-plan hash ≠ reviewed hash | 409 (domain conflict, no `X-Conflict-Code`) / 3 |
| `SEED_006` | `RowChangedDuringApplyException` | A batch digest differs inside its transaction | 409 / 6 when earlier batches committed, else 3 |
| `SEED_007` | `ScopeMismatchException` | Document scope kind ≠ target scope kind; provider not available in that kind | 422 / 2 |
| `SEED_008` | `RunNotAvailableException` | Unknown, expired, discarded run, or another actor's run | 404 / 2 |
| `SEED_009` | `PlanTooLargeException` | Changes above `ui.max_sync_changes` without Messenger | 413 / — |
| `SEED_010` | `ProviderApplyException` | The provider's handler failed (wraps the cause, logged; message static) | 500 page "apply failed, N batches applied" / 1 or 6 |
| `CONFLICT_001` / `CONFLICT_002` | transaction-doctrine `ConcurrencyConflict`, lease held | Lock conflict after replays; lease held by another run | 409 + `Retry-After` + `X-Conflict-Code` / 5 (001) or 6 (002) |
| `ACCESS_001` / `ACCESS_002` | guard | Hidden (404) / forbidden (403) | 404 / 403 / 4 |

Messages are static translation keys; no value, path, key or secret in exception messages or logs.

## 6. Provider SPI

```php
namespace Nowo\SeedKitBundle\Domain\Port;

#[AutoconfigureTag('nowo_seed_kit.provider')]
interface SeedProviderInterface
{
    public function name(): ProviderName;
    public function version(): int;                      // document version written and read (one per provider in 0.x)
    /** @return list<ScopeKind> */
    public function scopes(): array;
    public function schema(): SeedSchema;                // JSON Schema of ONE item + x-seed-* keywords (§7)
    /** @return list<ProviderName> */
    public function dependsOn(): array;                  // must be applied before this one (roles before members)

    /** @return iterable<array<string, mixed>> */
    public function exportEmpty(SeedContext $context): iterable;      // example items for the commented template
    /** @return iterable<SeedItem> rows the actor may read, natural-key byte order, secrets as refs or absent */
    public function exportCurrent(SeedContext $context): iterable;

    /**
     * Plans one slice (≤ batch size) with one bounded set of queries (lookup by natural keys).
     * With $options->lockForUpdate the provider reads the current rows with its write lock (inside the batch transaction).
     * @param list<SeedItem> $items
     */
    public function planBatch(SeedContext $context, array $items, PlanOptions $options): BatchPlan;  // RowChanges + RowErrors

    /**
     * Prune only: rows in scope that the actor may delete and whose key is not in $seen, natural-key byte order.
     * @return iterable<RowChange> (op Delete)
     */
    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable;

    /** Applies one batch through the host's own handlers, inside the transaction the bundle opened. No commit, no events before commit. */
    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult;

    /** Called after each batch commit or rollback: clear the provider's own state / its own EM (never required). */
    public function afterBatch(SeedContext $context): void;
}
```

- `SeedContext` (VO): `SeedScope scope`, `ActorRef actor`, `Channel channel`, `RunId runId`, `PendingKeys pending`
  (natural keys to be created by earlier documents of the same run, so a contacts document may reference a company the
  companies document creates), `bool prune`, `bool canSeeCurrentValues`.
- Registration: tag `nowo_seed_kit.provider` or `#[AsSeedProvider]` (autoconfigure). Compiler pass: unique names,
  `dependsOn` exist, **no cycles** (container build fails with the cycle), topological order fixed at compile time
  (Kahn, ties by name byte order), scopes ⊆ configured kinds, schema lint (§7.3) in `cache:warmup` / `lint` command
  (schemas are built at runtime, so lint runs in the warmer and in the contract test kit, not in the pass).
  Providers are lazy (service locator), stateless, worker-safe.
- `AbstractSeedProvider` (optional): defaults for `dependsOn() = []`, `afterBatch()` no-op, `exportEmpty()` from the
  schema's `examples`, and a `fingerprint(array $current): string` helper (canonical JSON + SHA-256).
- Rules written in the SPI docblocks and enforced by the contract test kit: write only through host handlers; filter
  by the actor; never return ids in items; return exactly the requested keys (`===` re-check by the bundle, REQ-SEC-006
  rule 4); deterministic order; bounded queries per batch.

## 7. Schema format

### 7.1 Envelope

The provider returns the schema of **one item**; the bundle wraps it in the document schema it publishes:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "urn:nowo-seed:crm.contacts:1",
  "title": "CRM contacts (seed document, version 1)",
  "type": "object",
  "required": ["provider", "version", "scope", "items"],
  "additionalProperties": false,
  "properties": {
    "$schema": {"type": "string", "maxLength": 255},
    "provider": {"const": "crm.contacts"},
    "version": {"const": 1},
    "scope": {"enum": ["organization"]},
    "meta": {"type": "object", "additionalProperties": false, "properties": {
      "exportedAt": {"type": "string", "format": "date-time"},
      "exportedFrom": {"type": "string", "maxLength": 128},
      "generator": {"type": "string", "maxLength": 64}}},
    "items": {"type": "array", "maxItems": 10000, "items": {"$ref": "#/$defs/item"}}
  },
  "$defs": {"item": { "...": "provider schema" }}
}
```

### 7.2 Allowed JSON Schema subset and `x-seed-*` keywords

- Draft 2020-12 validation keywords; `$ref` **local only** (`#/$defs/...`); no remote `$ref`, `$dynamicRef`,
  `$vocabulary` loading; formats: `date`, `date-time`, `email`, `uri`, `uuid`, `hostname`, `regex` disabled (pattern
  only, with a length bound); `pattern` compiled with a PCRE backtrack limit; `additionalProperties: false` required on
  every object (lint).
- Item keywords: `x-seed-key: ["email"]` (required; 1–4 top-level required string / integer properties),
  `x-seed-key-normalise: {"email": "lower"}` (optional; `lower` / `nfc` only; applied before validation, visible in
  the diff), `x-seed-ref: {"company": "crm.companies"}` (a property holding another provider's natural key; must be in
  `dependsOn`).
- Property keywords: `x-seed-secret: true` (§13.2), `x-seed-personal-data: true` (§14), `x-seed-readonly: true`
  (exported for context, ignored on plan, e.g. `createdAt`), `x-seed-float: true`, `x-seed-omit-means: keep|clear`
  (default `keep`: an absent optional field never clears a value; `clear` must be explicit per field).
- `description`, `examples`, `enum`, `title` are expected (an AI reads them); lint warns when a property has no
  description.

### 7.3 Schema lint (`nowo:seed-kit:lint`, warmer, test kit)

Fails when: no `x-seed-key`; key property not required; object without `additionalProperties: false`; remote `$ref`;
`x-seed-ref` to a provider not in `dependsOn`; a property whose name matches
`/(pass(word|phrase)?|secret|token|api[_-]?key|private[_-]?key|credential|iban|card)/i` without an explicit
`x-seed-secret` (true or false); unbounded `string` without `maxLength` (cap applies anyway).

## 8. Document and export format

- Top-level keys: `$schema`, `provider`, `version`, `scope`, `meta`, `items`; anything else ⇒ `SEED_002`.
- **YAML** (default): UTF-8, no BOM required (stripped if present); multi-document stream (`---`) = a run with several
  providers (§10.1). Empty export: header comments (provider title, schema file name, field list with descriptions,
  required marks, secret / personal-data notes) + `items: []` + commented example items.
- **JSON**: one object, or `{"documents": [ … ]}` for several providers.
- **JSON Lines** (`.seed.jsonl`): line 1 = header object (all keys except `items`), each following line = one item;
  one provider per file; the only streamed format (Q-SK-4).
- Exports are round-trip stable: natural-key byte order, keys in schema property order, no ids, `readonly` fields
  last, secrets as references or absent, dates as strings. `export current` → `apply` plans 0 changes (SC-001; test kit).
- Download names: `{provider}.{scope-kind}.{Ymd-His}.seed.{yaml|json|jsonl}` and `{provider}.v{n}.schema.json`
  (ASCII only, built from VOs, never from input); `Content-Disposition: attachment`; `X-Content-Type-Options: nosniff`.

Example (empty export, YAML):

```yaml
# yaml-language-server: $schema=crm.contacts.v1.schema.json
# SeedKit document: CRM contacts (provider crm.contacts, version 1, scope organization)
# Natural key: email (lower-cased). Personal data: name, email, phone. Secrets: none.
# Fields: email* (string, e-mail), name* (string, ≤ 120), phone (E.164), company (key of crm.companies), tags (list)
$schema: crm.contacts.v1.schema.json
provider: crm.contacts
version: 1
scope: organization
items: []
# items:
#   - email: ana.example@example.com
#     name: Ana Example
#     phone: '+34600000000'
#     company: acme
#     tags: [customer]
```

Example (current export, then edited and re-uploaded):

```yaml
$schema: crm.contacts.v1.schema.json
provider: crm.contacts
version: 1
scope: organization
meta: { exportedAt: '2026-11-24T10:00:00+00:00', exportedFrom: 0193a6f0-…, generator: 'seed-kit 0.1.0' }
items:
  - { email: ana.example@example.com, name: Ana Example, company: acme, tags: [customer] }
  - { email: luis.example@example.com, name: Luis Example, company: acme, tags: [] }
```

## 9. Plan hash and batch digests

**Canonical JSON** (used for fingerprints and keys): RFC 8785 (JCS) rules: object keys sorted by UTF-16 code units,
no whitespace, strings with minimal escaping, integers as-is, floats only for `x-seed-float` (ECMAScript shortest form;
NaN / Inf refused), `null` kept.

**Change line** for each `RowChange`:
`op || 0x1F || canonical(key) || 0x1F || (currentFingerprint ?? "-") || 0x1F || (desiredFingerprint ?? "-") || 0x1E`
(`op` = `C` / `U` / `N` / `D`). Unchanged rows are included, so a row that changes after the dry run turns `N` into
`U` and changes the hash.

**Plan hash** = `"sk1:" . hex(SHA-256(stream))`, stream =
`"nowo-seed-kit/plan/1" 0x1E scope.key() 0x1E prune(0|1) 0x1E` then, for each document in dependency order:
`provider 0x1F version 0x1F schemaFingerprint 0x1E` + its change lines (items in document order, then deletes in
natural-key byte order). `schemaFingerprint` = SHA-256 of the canonical item schema, so a provider upgrade between
dry run and apply is stale too. Computed incrementally (`hash_init` / `hash_update`), constant memory. The actor is
not in the hash; the run is bound to its actor instead (§10.3). Golden vectors are part of the test suite and
`docs/PLAN-HASH.md`, so a host can recompute.

**Batch digest**: same construction over the change lines of one batch (`"nowo-seed-kit/batch/1" 0x1E provider 0x1E
batchNo 0x1E …`); the list of digests is stored with the run (≈ 20 × 64 bytes for 10 000 rows at batch 500).
Unchanged rows are not sent to `applyBatch` but are in the digest.

## 10. Apply semantics

### 10.1 Order

- One run = one document or a multi-document file. Documents are sorted by the compile-time dependency order;
  duplicate providers in one run ⇒ `SEED_002`. Creates / updates go in dependency order (companies, then contacts);
  **deletes go in reverse order** (contacts, then companies) after all upserts.
- A document whose `dependsOn` provider is absent from the run is planned against current data (references must
  exist); `PendingKeys` carries keys created by earlier documents in the same run.

### 10.2 Steps

1. **Read** (limits, safe parsing, §13.3) → **normalise** (key normalisers) → **validate** (schema; then secrets via
   the validator port) → duplicate natural keys in one document ⇒ row error.
2. **Plan** in slices of `apply.batch_size` via `planBatch()` (one bounded query set per slice), then
   `planDeletions()` when `prune`; collect `RowError`s (capped). Any error ⇒ `SEED_003`, nothing stored, nothing
   written.
3. **Dry run result**: counts, preview, plan hash, batch digests. UI: stored as a `Planned` run (§11). Console
   `diff`: printed; nothing stored.
4. **Apply** (same actor, `SEED_APPLY` re-checked, `SEED_PRUNE` when prune):
   a. take the **lease** for every `(provider, scope)` of the run, in name byte order (held ⇒ `CONFLICT_001` +
      `Retry-After` = lease left);
   b. **re-plan** the whole run (no locks) and compare the plan hash ⇒ `SEED_005` with the new diff on mismatch;
   c. for each batch: open a write unit (transaction-contract runner on the configured connection) → `planBatch()`
      with `lockForUpdate` → digest must match ⇒ else `SEED_006` and rollback → `applyBatch()` → update the run row
      (`batches_done`, counts) **in the same transaction** → commit → `afterBatch()` → renew the lease;
   d. release leases in `finally`; dispatch `SeedRunAppliedEvent` / `SeedRunFailedEvent` after the last commit;
      audit (§12.3).
5. **Idempotency**: unchanged rows are never written; a second apply of the same file plans only `Unchanged` and
   writes nothing (SC-002); a replay of a run (Messenger redelivery, double click, retry) skips batches whose number
   is ≤ `batches_done` (committed atomically with the batch); a re-upload of a partially applied file converges.

### 10.3 Atomicity, concurrency and conflicts (REQ-RUNTIME-003)

| Write path | Unit | Control | Conflict answer |
|---|---|---|---|
| Batch apply | 1 per batch (`apply.batch_size`, default 500, 1–5 000) | Lease per `(provider, scope)`; provider row locks taken by `planBatch(lockForUpdate)` in natural-key order; digest check | Lock: replay ×3 (`ResetBetweenAttemptsInterface`, actor re-resolved), then `CONFLICT_001` (no batch committed yet in this request, CommitLedger watermark) or `CONFLICT_002`; changed row: `SEED_006` |
| Lease take / renew / release | tiny | Unique `(provider, scope_kind, scope_id)`; conditional `UPDATE … WHERE locked_until < now OR run_id = ?` | Held ⇒ `CONFLICT_001` + `Retry-After` |
| Run create (upload) | 1 | Insert; `(actor, document_hash, status Planned)` reuse instead of duplicate | — |
| Run claim (apply / Messenger) | 1 | `UPDATE … SET status = Running, version = version + 1 WHERE id = ? AND status IN (Planned, Queued, PartiallyApplied) AND version = ?` | 0 rows ⇒ already running / done ⇒ 409 `CONFLICT_001` or idempotent "already applied" page |
| Run discard / purge / actor forget | batches of 500 | Keyset, by id | Lock ⇒ replay, exit 1 |

- Plans of one batch (≤ `batch_size`) are atomic; larger plans are batch-atomic with resumable progress (F1, Q-SK-3).
- Never clears / resets / flushes any EM; a host transaction already open ⇒ the unit is a savepoint (PostgreSQL:
  rolled back to it before rethrow); no file / HTTP I/O inside a unit (the document is read and normalised before;
  JSON Lines batches are read between units).
- Host handlers on a different connection than `transaction.connection` are outside the batch transaction:
  documented, and `SeedKit` logs a warning once per provider if the provider declares it (`transactional: false` in
  its schema options); progress is then at-least-once per batch and the provider must be idempotent (it is: upsert by
  natural key).
- `docs/CONCURRENCY.md` with this table; real-database job "Databases" (MySQL 8.4, MariaDB 11.4, PostgreSQL 17; DBAL
  3 / 4) required.

### 10.4 Prune

Off by default; `--prune` / UI checkbox shown only with `SEED_PRUNE`. Deletes only rows of the target scope that the
provider returns for this actor. Guards: `prune.max_ratio` (default 0.5: a prune that would delete more than half of
the current rows needs `--allow-mass-prune` / a second UI confirmation typed with the count), and an empty `items`
with prune is refused unless `--allow-empty-prune`. Deletes go through the host's delete handlers (soft delete if
that is what the CRUD does).

### 10.5 Async (Messenger, 0.2)

When the plan has more than `messenger.async_threshold_changes` (default 2 000) changes and Messenger is enabled, the
UI queues `ApplySeedRunMessage(runId, planHash)`; the handler runs steps 4a–4d with `Channel::Messenger`, the actor of
the run, and the same checks (access re-checked with the stored actor through the access checker: the run stores
actor ref + the access decision is re-evaluated by `SeedKitAccessCheckerInterface::canForActor()` since there is no
token). Progress page polls `GET …/runs/{id}/progress` (internal UI JSON). Without Messenger the UI refuses plans above
`ui.max_sync_changes` (`SEED_009`, "use the console"). Console: synchronous with a progress bar; `--async` dispatches.

## 11. Storage

Same pattern as CurrencyKit (QC-3): bundle tables on the configured connection, written through DBAL in write units,
read-only entities mapped (XML default + attribute twin) so the host's schema tool / migrations see them; never
`persist` / `flush`. Collation listener: binary NO PAD on every identifier column (REQ-SEC-006 rule 3/8).

| Table | Columns |
|---|---|
| `nowo_seed_run` | `id` UUID v7 PK; `scope_kind`, `scope_id` (bin); `actor_ref` (bin, nullable after forget); `channel`; `status`; `providers` JSON (names + versions); `prune` bool; `document_hash`; `plan_hash`; `batch_digests` JSON; `batches_done` int; `counts` JSON; `document` LONGBLOB gzip nullable (normalised canonical JSON lines, **not** the raw upload); `error_code` nullable; `version` int; `created_at`, `updated_at`, `expires_at`, `finished_at`; index `(scope_kind, scope_id, created_at)`, `(status, expires_at)` |
| `nowo_seed_lease` | PK `(provider, scope_kind, scope_id)` (bin); `run_id`; `locked_until` |

- `document` is set at upload, cleared (`NULL`) when the run ends (applied, failed for good, discarded) and at
  `runs.document_ttl` (default 1 h for `Planned`); metadata kept `runs.history_days` (default 365) for the runs list
  and audit cross-checks (Q-SK-5). `nowo:seed-kit:runs:purge` (+ Scheduler bridge, + opportunistic bounded purge on
  upload).
- Size of a stored document bounded by `reader.max_bytes`; gzip level 6.

## 12. Access, events, audit

### 12.1 House access pattern (REQ-UI-002) + voter attributes (FR-006)

```yaml
nowo_seed_kit:
    security:
        access_roles: [ROLE_ADMIN]       # area: may open the Import / export page at all
        access_checker: null             # service id implementing SeedKitAccessCheckerInterface
        allow_unauthenticated: false     # demos only; AllowAll checker selectable only with this
        export_roles: [ROLE_ADMIN]       # default for SEED_EXPORT (Q-SK-7)
        apply_roles: [ROLE_ADMIN]        # default for SEED_APPLY
        prune_roles: []                  # default for SEED_PRUNE: nobody until mapped
        providers:                       # per-provider overrides
            crm.contacts: { export_roles: [ROLE_CRM_ADMIN], apply_roles: [ROLE_CRM_ADMIN], prune_roles: [] }
```

- `SeedKitAccessCheckerInterface { canUseArea(?object $user, SeedScope $scope): bool; can(?object $user, SeedAction
  $action, ProviderName $provider, SeedScope $scope): bool; canForActor(ActorRef $actor, SeedAction, ProviderName,
  SeedScope): bool }` with `ConfigurableSeedKitAccessChecker` (roles above; `canForActor` = deny unless the host
  implements it, so Messenger needs a host checker or `messenger.trust_run_actor: true`, Q-SK-7b inside Q-SK-7) and
  `AllowAllSeedKitAccessChecker` (demos).
- `SeedKitVoter` supports `SEED_EXPORT`, `SEED_APPLY`, `SEED_PRUNE` on a `SeedPermissionSubject(provider, scope)`:
  checker → `SeedKitEvents::ACCESS_CHECK` (`SeedAccessCheckEvent`: action, provider, scope, channel, actor;
  `grant()` / `deny()` / default = checker result) → GRANTED / DENIED. A host may instead register its own voter for
  the same attributes (F8).
- `SeedKitGuard` first in every action: no user and not `allow_unauthenticated` ⇒ 302 login / 401; scope not
  resolvable or not visible ⇒ 404; area false ⇒ 403; provider not granted for the action ⇒ **404 for listing
  purposes** (a provider the viewer cannot export or apply is not shown; direct URLs answer 404, SC-004 accepts
  403 / 404).
- Console: no token; access granted by default, `ACCESS_CHECK` dispatched with `Channel::Console` so a host listener
  can deny (house precedent, CurrencyKit audit VR Q1); writing commands require `--actor` unless
  `console.require_actor: false`.
- Every POST: CSRF (`nowo_seed_kit`), FormKit forms, `#[AsWriteUnit]` not used on controllers (the applier opens its
  own units; controllers do not wrap the whole request).

| Page / action | Area | `SEED_EXPORT` | `SEED_APPLY` | `SEED_PRUNE` |
|---|---|---|---|---|
| Index (providers listed) | ✓ | any of the three for that provider | | |
| Download empty / current / schema | ✓ | ✓ | | |
| Upload + dry run | ✓ | | ✓ | |
| Before values in the diff | ✓ | ✓ (else field names only) | ✓ | |
| Apply | ✓ | | ✓ | + ✓ when prune |
| Runs list / run page | ✓ | | own runs; all runs of the scope with `SEED_APPLY` on every provider of the run | |

### 12.2 Events (`Domain\Event`, after commit, VOs and ids only)

`SeedAccessCheckEvent`, `SeedExportedEvent` (provider, scope, mode, row count, actor), `SeedRunPlannedEvent`,
`SeedBatchCommittedEvent` (run, provider, batch no, counts), `SeedRunAppliedEvent` (run, providers, scope, counts,
document hash, plan hash, actor, channel), `SeedRunFailedEvent` (run, error code, batches committed).

### 12.3 Audit (FR-009)

`SeedAuditSinkInterface::record(SeedAuditRecord)` (VO: action `export|apply|prune|discard`, actor, scope, providers,
counts, document hash, plan hash, run id, channel, outcome). Default sink: PSR-3 logger channel `seed_kit` at
`notice`. AuditKit bridge (auto when `ActionLogRecorderInterface` exists, `audit.auditkit: true`): `record()` +
flush **after** the run's last commit (outside any bundle unit). What is audited: every apply (all outcomes), every
prune, every export of a provider with personal-data or secret fields; other exports only at `info` in the log
(Q-SK-11). No natural keys, values or file names in audit context.

### 12.4 Console (`nowo:seed-kit:*`; `seed:*` aliases per Q-SK-8)

| Command | Options | Exit codes |
|---|---|---|
| `list` | `--scope-kind`, `--format=table\|json` | 0 |
| `schema <provider>` | `--output=<file>\|-` | 0, 2 |
| `export <provider>` | `--empty`, `--scope=kind[:id]`, `--format=yaml\|json\|jsonl`, `--output=<file>\|-` (default stdout), `--actor` | 0, 2, 4 |
| `diff <file\|->` | `--scope`, `--prune`, `--format=text\|json`, `--max-rows=N`, `--exit-code`, `--actor` | 0 (no changes or no `--exit-code`), 9 (changes, with `--exit-code`), 2, 4 |
| `apply <file\|->` | `--scope`, `--prune`, `--allow-mass-prune`, `--allow-empty-prune`, `--yes`, `--expect-hash=sk1:…`, `--batch-size`, `--async`, `--actor` | 0 applied / nothing to do, 2 invalid, 3 stale (`--expect-hash` or digest before any commit), 4 denied, 5 conflict (`CONFLICT_001`), 6 partially applied, 7 declined at the prompt or non-interactive without `--yes`, 1 unexpected |
| `runs:list` | `--scope`, `--status`, `--limit` | 0 |
| `runs:purge` | `--before`, `--dry-run` | 0, 1 |
| `actor:forget <ref>` | `--yes` | 0, 1 |
| `lint` | `[provider]` | 0, 2 |

- Interactive `apply` prints the diff summary + plan hash and asks; `--yes` skips the question (CI); without a TTY
  and without `--yes` ⇒ exit 7. Stdout carries data (export), stderr carries messages.
- File arguments: §13.4. `-` reads stdin (capped).

### 12.5 UI (0.2.0; UiKit + FormKit, REQ-UI-001 / 003, REQ-DEMO-014 / 015)

Mount per scope kind (Beacon T002): routes generated by a route loader from config, e.g.

```yaml
nowo_seed_kit:
    ui:
        enabled: true
        mounts:
            platform:     { path: /settings/seeds }
            organization: { path: '/organizations/{scopeId}/seeds' }
            project:      { path: '/projects/{scopeId}/settings/seeds' }
```

Route names `nowo_seed_kit_{kind}_{page}`; prefixes exposed as container parameter `nowo_seed_kit.ui_paths` for the
host's `access_control`; the bundle answers only its own routes.

| Route (under the mount) | Page |
|---|---|
| `GET /` | `ListPage` scaffold: providers the viewer may use (name, description, dependencies, personal-data badge); row actions *Download empty*, *Download current*, *Download schema*, *Upload*; header action *Upload file* (multi-document) and *History* |
| `GET /{provider}/export?mode=empty\|current&format=` | file download (personal-data providers: UiKit confirm dialog first) |
| `GET /{provider}/schema.json` | schema download |
| `GET\|POST /upload` | UiKit remote dialog, FormKit form: file, prune (only with `SEED_PRUNE`); POST validates + plans; errors listed by row / key / field; success ⇒ redirect to the run |
| `GET /runs/{id}` | `ShowPage` scaffold: counts by provider and op, preview table (UiKit list region; changed fields before → after), plan hash, warnings (mass prune, origin scope differs from target), *Apply* (UiKit confirm contract, prune confirmations), *Discard* |
| `POST /runs/{id}/apply` | hidden plan hash; sync apply ⇒ result page, or queued ⇒ progress |
| `POST /runs/{id}/discard` | clears the document |
| `GET /runs/{id}/progress` | internal UI JSON `{status, batchesDone, batchesTotal, counts}` |
| `GET /runs` | history `ListPage` (keyset pager, 50 per page) |

Phone-first, axe clean, translated × 7, branded conflict page (409), no own JS in 0.2 (UiKit remote dialogs and
UiKit polling attribute; fallback refresh link).

## 13. Security model

### 13.1 Threats and controls

| Threat | Control |
|---|---|
| Privilege escalation through an import (write roles, permissions, menus pointing to external URLs) | Provider writes through the host handlers (same validation and authorisation as the CRUD); `SEED_APPLY` per provider + scope; row-level filtering by the provider; prune needs `SEED_PRUNE`; project-roles provider in Beacon must refuse grants above the actor's own (QB-SK-3) |
| Cross-tenant writes | Target scope comes from the route / `--scope`, resolved and authorised by the host resolver; the document's `scope` is a kind only; `meta.exportedFrom` never selects a target (warning when it differs, Q-SK-12); providers receive the scope VO, never an id from the file |
| Look-alike keys (`Bob` vs `bob`) | Exact keys (§5.1), NO PAD binary columns for bundle tables, `===` re-check of provider-returned keys, explicit normalisers only |
| Stale / replayed / double apply | Plan hash, batch digests, lease, run claim, batches progress in the same transaction |
| CSRF / forged apply | CSRF on every POST; run bound to its actor; apply re-checks access |
| Secrets leaking | §13.2 |
| DoS / resource exhaustion | §13.3 caps; batch size bounds; UI sync cap; Messenger for big plans; PCRE limits |
| Malicious YAML / JSON | §13.3 |
| Path traversal / SSRF via file arguments | §13.4 |
| Information leak in errors | Errors carry index, key (shown only to the uploader, never logged), JSON Pointer, rule, translated message; never the value |
| Formula injection | Not applicable (no CSV) |

### 13.2 Secrets (FR-007)

- Export: a secret field is written as `vault:<ref>` when the provider returns a `SecretReference`; otherwise the
  field is **omitted** and the YAML header says which fields were omitted. A provider returning a plain string for a
  secret field ⇒ the exporter throws (fail closed) and the contract test kit fails (SC-005).
- Import: a secret field accepts only `SecretReference` syntax; the `SecretReferenceValidatorInterface` (Vault bridge
  or host) must confirm the reference exists and is usable by the actor in the target scope; no validator configured
  ⇒ every non-absent secret field is refused (`SEED_004`). Absent = keep current (`x-seed-omit-means: keep` forced for
  secrets); clearing a secret is not possible through seeds in 0.x.
- Plaintext detection never echoes or logs the value; the run blob never contains plaintext (refused before storage).
- Diff shows reference changes (`vault:a` → `vault:b`), never resolved values.

### 13.3 Reader hardening

- Caps (config, §15): `max_bytes` (UI default 8 MiB, console 256 MiB), `max_tree_bytes` 4 MiB (above: JSON Lines
  only), `max_items` per document (UI 10 000, console 1 000 000), `max_documents` 20, `max_depth` 16,
  `max_string_bytes` 64 KiB, `max_properties` per object 256, `max_line_bytes` (JSONL) 1 MiB, `max_nodes` per item
  10 000, `validation.max_errors` 200. Bytes counted while reading the stream (not trusted from headers); gzip
  uploads not accepted.
- YAML: `Yaml::parse($text, 0)`-equivalent flags: no `PARSE_OBJECT`, `PARSE_OBJECT_FOR_MAP`, `PARSE_CONSTANT`,
  `PARSE_CUSTOM_TAGS`, `PARSE_DATETIME`, `PARSE_EXCEPTION_ON_INVALID_TYPE` **on**; a pre-scanner refuses any tag (`!`,
  `!!`), anchor (`&`) and alias (`*`) token outside quoted scalars and comments, and `<<` merge keys; duplicate keys
  refused; then a node-budget walk during normalisation.
- JSON: `json_decode(…, true, max_depth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)`; duplicate keys detected by
  the JSON Lines / JSON tokenizer check (PHP keeps the last silently: refused via a lightweight duplicate-key scan on
  objects ≤ `max_tree_bytes`); invalid UTF-8 refused.
- Schema validation runs with opis limits (max errors, no remote loader, own format set); `pattern` with
  `pcre.backtrack_limit` scoped per call and restored.
- Hostile corpus in tests: billion laughs (anchors), `!php/object`, `!php/const`, merge keys, 1 MB single line,
  deep nesting, 1 M empty items, duplicate keys, BOM / UTF-16, NUL bytes, invalid UTF-8, giant integers, `-0`, `1e999`.

### 13.4 Files and paths

- HTTP: the uploaded file is read from the PHP temp file via a capped stream into the normalised form; the client
  file name is display-only (sanitised, ≤ 120 chars), never a path; MIME / extension only select the parser,
  content decides (parse failure ⇒ `SEED_002`); temp streams closed in `finally`.
- Console: `<file>` must resolve (`realpath`) under `console.allowed_dirs` (default `%kernel.project_dir%/config/seeds`
  and `%kernel.project_dir%/var/seeds`) unless `--allow-any-path`; regular file only (no directory, FIFO, device);
  symlinks resolved and re-checked against the allowed dirs; stream wrappers refused (`://`, `phar:`, `php:`,
  `data:`, `glob:`); `-` = stdin. `--output` follows the same rules plus no overwrite without `--force`.
- No include / import directive in documents; `$schema` is never fetched.

## 14. Privacy (GDPR)

- The bundle processes personal data only as a processor for the host: documents of providers with
  `x-seed-personal-data` fields (CRM contacts). Controls:
  - export of such a provider: UI confirm with a notice (file contains personal data, handle per the host's policy),
    audited (who, provider, scope, row count), filename carries no personal data;
  - uploaded documents: kept only as the normalised blob, deleted at the end of the run or after `document_ttl`
    (default 1 h); purge command; no copy in logs, events, audit or Messenger messages (the message carries the run
    id only);
  - errors: the natural key of a failing row is shown to the uploader on the result page, never logged;
  - actor refs in runs: `actor:forget <ref>` nulls them (history kept); audit retention is the sink's;
  - erasure: SeedKit holds no subject data after the TTL; providers' data erasure stays with the host / CrmKit.
- Consent and lawful-basis fields: a provider must not set consent records from a seed unless the host decides so;
  CRM providers in Beacon mark consents `x-seed-readonly` (QB-SK-4).
- `docs/PRIVACY.md` lists the above; LegalKit text not needed for the bundle itself.

## 15. Configuration tree

```yaml
nowo_seed_kit:
    scope_kinds:                                # host scope kinds; default only `platform`
        platform:     { requires_id: false }
        organization: { requires_id: true }
        project:      { requires_id: true }
    scope_resolver: null                        # service id (SeedScopeResolverInterface); default: platform-only resolver
    actor_resolver: null                        # service id (SeedActorResolverInterface); default: user identifier
    doctrine:
        connection: default                     # bundle tables + batch transactions
        table_prefix: nowo_
        mapping: xml                            # xml | attribute
    transaction:
        runner: null                            # TransactionRunnerInterface service id; default transaction-doctrine forConnection()
        max_replays: 3
        conflict_status: 409                    # 409 | 503 (REQ-RUNTIME-003 rule 6)
    reader:
        max_bytes: { http: 8388608, console: 268435456 }
        max_tree_bytes: 4194304
        max_items: { http: 10000, console: 1000000 }
        max_documents: 20
        max_depth: 16
        max_string_bytes: 65536
        max_properties: 256
        max_line_bytes: 1048576
        max_nodes_per_item: 10000
    validation:
        max_errors: 200
    apply:
        batch_size: 500                         # 1..5000
        lease_seconds: 300                      # renewed per batch
    prune:
        max_ratio: 0.5                          # 0.01..1.0
    plan:
        preview_rows: 200                       # per op, UI and default console
    runs:
        document_ttl: PT1H
        history_days: 365
    messenger:
        enabled: false                          # true when symfony/messenger is installed and the host routes the message
        async_threshold_changes: 2000
        trust_run_actor: false                  # Q-SK-7
    secrets:
        validator: null                         # SecretReferenceValidatorInterface service id; Vault bridge when installed + enabled
        vault_bridge: false
    audit:
        auditkit: true                          # bridge used when AuditKit is installed
        export_of_sensitive: true               # audit exports of personal-data / secret providers
        log_level: notice
    console:
        require_actor: true
        allowed_dirs: ['%kernel.project_dir%/config/seeds', '%kernel.project_dir%/var/seeds']
        short_aliases: true                     # seed:* aliases (Q-SK-8)
    ui:
        enabled: false
        max_sync_changes: 2000
        mounts: {}                              # kind => { path }
    security: { … }                             # §12.1
```

Strict tree (REQ-SF-006): unknown keys fail; `ui.enabled` without SecurityBundle, UiKit or FormKit fails at compile
time; `allow_unauthenticated` true outside `dev` / `test` logs a critical warning at boot. Runtime settings env / DB
(owner 2026-11-16): none of these are `.env` settings or secrets; nothing to register in 0.x.

## 16. Worker mode (FrankenPHP, kernel reset false; REQ-RUNTIME-002)

- Services hold configuration only; registry data is compile-time (arrays in the container); providers stateless
  (lint in the contract kit: a second export in the same kernel returns the same bytes).
- No document, plan, user, scope or locale in properties or statics; readers and hashers are created per call;
  streams and temp files closed / deleted in `finally`.
- Compiled opis schemas cached in a bounded per-process map keyed by schema fingerprint (immutable data, at most one
  entry per provider version), documented as the only memo; `ResetInterface` empties it anyway.
- Lease released in `finally`; DBAL reconnect tolerated; no EM touched.
- Tests: 300 requests in one kernel mixing exports, uploads, applies of two providers and two actors with different
  visibility (no cross-request leak, flat memory); 10 000-row JSON Lines apply under 64 MB peak with a constant query
  count per batch (DBAL middleware counter); Igor + `phpstan-frankenphp` clean.

## 17. Standard vs DDD host (REQ-ARCH-001)

| Topic | Standard Symfony host | DDD / hexagonal host |
|---|---|---|
| Provider | Extend `AbstractSeedProvider`, call the CRUD's services / repositories | Implement `SeedProviderInterface` in the infrastructure layer, call application command handlers |
| Scope / actor | Default resolvers (platform kind; user identifier) | Own resolvers mapping to tenant VOs |
| Storage | Default connection, attribute or XML mapping | Separate connection by config; XML mapping |
| Access | `security.*_roles` | Own checker / voter / `ACCESS_CHECK` listener over permission sets |
| Events | Symfony dispatcher, after commit | Same; host maps to its bus |

Both kinds run the same functional suite (standard fixture: attribute entities, Doctrine-service provider; DDD
fixture: XML mapping, second connection, command-bus provider, custom resolvers and checker).

## 18. Phases

| Slice | Version | Content | Audit |
|---|---|---|---|
| S0 scaffold | `main`, no tag | Composer, Docker (MySQL 8.4, MariaDB 11.4, PostgreSQL 17), Makefile + release-check, CI matrix SF 7.4 / 8.0 / 8.1 × DBs × DBAL 3/4, PHPStan max + `phpstan-frankenphp`, Igor, Rector, PER-CS, Deptrac, coverage 100 %, Spec Kit baseline, docs skeleton, Flex recipe, strict config | — |
| **A: core + console** | **0.1.0** | §5–§11, §12.1 (checker, voter, event, console channel), §12.2–§12.4, §13, §14, §16, §17; readers YAML / JSON / JSONL; opis adapter + lint; plan hash + digests; applier with leases and batches; run store (used by `--async` later; console runs recorded as history rows without document); AuditKit + Vault bridges; contract test kit; demo with two providers (console + minimal UiKit page listing providers and downloads); 7 locales | **Immediate** security audit (write path, permissions, secrets) |
| **B: UI + async** | **0.2.0** | §12.5 pages, FormKit upload, diff, apply, runs, progress; Messenger async apply; Scheduler purge; BreadcrumbKit crumbs; e2e Playwright per profile, WebP screenshots | **Immediate** (permissions) |
| C | 0.3.0 | Document version upgraders (`SeedDocumentUpgraderInterface`: provider reads v*n−1* files), `export --where` filters, diff download as JSON patch, per-provider `transactional: false` hardening | Batched |
| Later | 0.4+ | Public API `<prefix>/seed/v1` + Api\Operation layer for MCP tools (`seed_schema`, `seed_export`, `seed_diff`, `seed_apply`) if the owner wants it (Q-SK-9); *Ask AI* hook; entity provider scaffolding command (Q-SK-10) | Immediate (API) |
| 1.0.0 | after Beacon blocks B – C | `bundle-req-audit` 100 %, SEC-004 | Immediate |

Beacon's pilot (spec 257 block B) can start on 0.1.0 (providers + console + `app:seed-platform` on SeedKit) and add the
Settings pages on 0.2.0.

## 19. Test strategy (PHPUnit, 100 % PHP coverage, PHPStan max, real databases)

- **Unit:** every VO (exact-key table: `Bob`, `bób`, `bob `, `bob\n`, NBSP, ZWSP, NFC vs NFD), canonical JSON (JCS
  vectors), plan hash and batch digest golden vectors, schema lint rules, reader caps one by one, hostile corpus
  (§13.3), JSON Lines streaming (memory flat over 1 M lines with a small cap), YAML pre-scanner (quoted `&`/`*` kept,
  comments ignored), path rules (§13.4: `..`, symlink escape, `phar://`, FIFO, directory).
- **Application:** planner (ops, changed fields, unchanged rows, duplicates, row errors, pending keys across
  documents, dependency order, reverse deletes, prune guards), applier (stale hash, digest mismatch, partial apply,
  resume, replay skip, lease held, lease expiry takeover), exporter (empty template comments, current round trip,
  secret omission, fail-closed plaintext).
- **Contract test kit** (`Nowo\SeedKitBundle\Testing\SeedProviderContractTestCase`, shipped for hosts and used by the
  demo providers): schema lint; round trip 0 changes (SC-001); idempotent second apply with a query log assertion of
  no INSERT / UPDATE / DELETE (SC-002); constant queries per batch at 1, 2 and 4 batches (SC-003); no secret value in
  any export (SC-005: seeds a known secret and greps the export bytes); two actors with different visibility export /
  prune only their rows; exact-key re-check.
- **Concurrency (two DBAL connections; MySQL, MariaDB, PostgreSQL in CI):** two applies same provider / scope ⇒ one
  lease, other `CONFLICT_001` + `Retry-After`; CRUD edit between dry run and apply ⇒ `SEED_005`; edit during batch 2
  ⇒ `SEED_006`, batch 1 committed, status `PartiallyApplied`, re-apply converges; injected deadlock ⇒ replay then
  `CONFLICT_001` (nothing committed) / `CONFLICT_002` (after a committed batch, CommitLedger); Messenger redelivery ⇒
  each batch once; host transaction open ⇒ savepoint path; connection usable afterwards.
- **Security:** route × profile matrix (guest, user without roles, export-only, apply-only, prune, custom checker,
  event deny, host voter grant, `allow_unauthenticated` demo) ⇒ 302 / 403 / 404 / 200 (SC-004); CSRF; another actor's
  run ⇒ 404; scope id look-alikes ⇒ 404; provider hidden in list when not granted.
- **Worker:** §16 N-request test; memo reset; Igor.
- **Both host kinds:** §17 fixtures; XML vs attribute schema equality; reference DDL per platform.
- **Architecture:** Deptrac; §4 grep rules; no framework types in Domain.
- **Demo e2e (0.2):** Playwright: download empty, upload with an enum error (row / field shown, nothing written),
  upload valid (diff 5 creates), apply, re-upload (0 changes), denial per profile; WebP screenshots, phone width.

## 20. Docs set

README (worker support banner, both host paths), INSTALLATION (DDL, routes), CONFIGURATION, USAGE (writing a
provider: standard + DDD; schema keywords; secrets; prune; console recipes; AI editing workflow with the schema),
FORMAT (document, JSON Lines, examples), PLAN-HASH (algorithm + vectors), CONCURRENCY, FRANKENPHP-WORKER-AUDIT,
SECURITY (§13, residual risks), PRIVACY, TEMPLATES (pages and scaffolds), ARCHITECTURE (Deptrac section), UPGRADING,
CHANGELOG, Spec Kit baseline.

## 21. Beacon integration sketch (informative)

Providers in Beacon's infrastructure layer calling its application handlers (spec 225): `navigation.menus`,
`navigation.breadcrumbs` (platform), `api_studio.services` (organization; credentials as Vault refs or omitted),
`access.project_roles` (platform permission sets), `crm.companies` ← `crm.contacts` (organization, CrmKit natural
keys of spec 253: company slug / domain, contact e-mail). Access: a `SEED_ACCESS_CHECK` listener (or checker) maps
platform → ROLE_DEVELOPER, organization → organization manage, project → project settings manage, deny by default.
`app:seed-platform` becomes `nowo:seed-kit:apply config/seeds/platform.yaml --yes --actor=system` (multi-document).

## 22. REQ mapping

| REQ | Where |
|---|---|
| ARCH-001 (rules 1–8) | §4, §6 (SPI interfaces + VOs), §11 (DBAL, no EM flush), §12.2 (events after commit, ids), §15 (config tree, provider ports), §17 |
| ARCH-002 Deptrac | §4 |
| RUNTIME-001 | No outbound HTTP; batch / lease timeouts; PCRE limit |
| RUNTIME-002 | §16 |
| RUNTIME-003 | §10.3, §11, §19 concurrency |
| SEC-001 / 003 / 004 | docs/SECURITY.md, `.github/SECURITY.md`, immediate audits per slice (§18) |
| SEC-005 CSRF | §12.1 |
| SEC-006 exact ids | §5.1, §11 collations, §6 key re-check, §19 |
| UI-001 / 002 / 003 | §12.1, §12.5 (scaffolds ListPage / ShowPage, UiKit confirm and dialogs) |
| DEMO-014 / 015, TWIG-003 / 005 | demo on UiKit; FormKit forms; `form_start` / child loop |
| I18N-001 … 003 | 7 locales, domain `NowoSeedKitBundle` |
| ASSETS-001 … 005 | — in 0.x (no own JS); applies if TS is added |
| SF-006 strict config, SF-003 / 004 attributes | §15, commands / controllers by attribute |
| ORM-001 / 002 | §11 (read-only mapping, `table_prefix`) |
| MSG-001 | §10.5 (idempotent handler, failure policy: no retry on `SEED_00x`, retry on transient) |
| PERF-001 | runs list keyset pager; constant queries per batch |
| OBS-001 | log channel `seed_kit`, no values in logs |
| API path rule | No public API in 0.x; internal UI JSON exempt; `<prefix>/seed/v1` reserved (Q-SK-9) |
| Runtime settings env/DB | §15: none |
| CS-001 … 009, TEST-*, CI-*, DOCS-*, SPECKIT-*, MAKE-*, PKG-*, GIT-* | S0 scaffold, house template; 100 % before every tag (owner 2026-11-24) |

## 23. Answers

Recorded 2026-11-24. With these answers the contract moves to v1.0.

### 23.1 Owner (Q-SK)

Q-SK-1 … Q-SK-14 accepted **as recommended (option a)** on 2026-11-24:

| # | Answer |
|---|---|
| Q-SK-1 | 0.1.0 = core + console + contract test kit + demo, immediate security audit; 0.2.0 = UI pages + Messenger async, immediate audit; 1.0.0 after the REQ audit at 100 % and SEC-004 |
| Q-SK-2 | `opis/json-schema` 2.x (draft 2020-12), local `$ref` only, no remote loader |
| Q-SK-3 | Every check that can fail runs before the first write; plans of one batch are atomic, larger plans batch-atomic (`PartiallyApplied`, re-apply converges) |
| Q-SK-4 | YAML / JSON read whole up to 4 MiB; bigger files must be JSON Lines, streamed without a new dependency |
| Q-SK-5 | Run table with a short-lived normalised document while planned (UI, 0.2); metadata kept `runs.history_days` |
| Q-SK-6 | Export omits non-reference secrets; absent secret = keep; only `vault:<ref>` accepted, validated by a port, fail closed; no clearing in 0.x |
| Q-SK-7 | `access_roles` / `export_roles` / `apply_roles` default `[ROLE_ADMIN]`, `prune_roles` `[]`, per-provider overrides; Messenger re-checks the stored actor (0.2) |
| Q-SK-8 | Canonical `nowo:seed-kit:*` with `seed:*` aliases, switchable (`console.short_aliases`) |
| Q-SK-9 | No public HTTP API or MCP tools in 0.x; `SeedOperations` shaped so they can be added in 0.4+ |
| Q-SK-10 | No generic Doctrine entity provider; at most a scaffolding command later |
| Q-SK-11 | Audit every apply and prune and every export of a provider with personal-data or secret fields; other exports only logged at `info` |
| Q-SK-12 | The document's `scope` binds the kind only; the target id comes from the page / `--scope`; a warning when `meta.exportedFrom` differs |
| Q-SK-13 | Separate `SEED_PRUNE` (deny by default), mass-prune and empty-prune guards |
| Q-SK-14 | Repository created (public, MIT); the owner registers the package on Packagist before the first tag |

### 23.2 Symfony Beacon (QB-SK)

| # | Answer |
|---|---|
| QB-SK-1 | The scope resolver returns `null` for unknown or not visible scopes and the bundle answers 404 (`ACCESS_001`). In Beacon the project scope id is the project UUID and the organization scope id is the BillingAccount UUID; `platform` has no id and is gated to `ROLE_DEVELOPER` by the host configuration |
| QB-SK-2 | `vault:<item-uuid>` of an organization-owned Vault item. The optional Vault bridge checks that the item belongs to the target scope and that the actor may use it. On export secret values are always omitted, and a pointer is exported only when it resolves to an item of the scope |
| QB-SK-3 | No escalation by seed: an import never grants permissions the actor does not hold (enforced in Beacon's handler, as in the CRUD). The contract test kit has the case `refusesItemsThatEscalate` in `SeedProviderContractTestCase` |
| QB-SK-4 | Consent and lawful-basis fields of the CRM providers are `x-seed-readonly` |
| QB-SK-5 | `--actor=system` only on the console channel, never over HTTP; audited as `system`; a host listener may still deny |
| QB-SK-6 | 0.1.0 = core + console + contract test kit + demo with two providers; UI and Messenger in 0.2.0 |

## 24. Deviations in 0.1.0

Accepted by the coordinator on 2026-11-24; the code and the docs describe the implemented behaviour.

| # | Contract text | 0.1.0 |
|---|---|---|
| D1 | 7 locales `en, es, ca, eu, gl, fr, pt` (§2, §22) | **10 locales** `en es it fr pt de nl ca eu gl`: REQ-I18N-002 requires `it`, `de`, `nl` |
| D2 | Error codes `SEED_001` … `SEED_010` (§5.5) | Two new codes: `SEED_011` `ProviderContractException` (a provider broke its contract: keys, order, ids, plain secret, name; nothing written) and `SEED_012` `PruneRefusedException` (mass or empty prune without the flag). `SEED_008` / `SEED_009` are reserved for the 0.2 UI; `RowChangedDuringApplyException` and `ProviderApplyException` are one `ApplyInterruptedException` carrying `SEED_006` / `SEED_010` / `CONFLICT_00x` |
| D3 | `apply --batch-size`, `--async` (§12.4) | No `--batch-size` and no `--async` in 0.1: the batch size comes only from `apply.batch_size`; async apply arrives with Messenger in 0.2 |
| D4 | `doctrine.connection` (§15) | **`doctrine.entity_manager`**: the name of the entity manager whose connection holds the bundle tables and runs the batch transactions (the mapping is prepended to it). The bundle never persists, flushes, clears or resets any entity manager: tables are written through DBAL only and the write unit's registry holds no entity manager (`ConnectionOnlyRegistry`). Plus `doctrine.table_prefix` (default `nowo_`) and `doctrine.mapping` (`xml` / `attribute`) |
| D5 | Config sections `ui`, `messenger`, `transaction.runner`, `transaction.conflict_status`, reader HTTP / console caps, `runs.document_ttl`, `audit.export_of_sensitive` (§15) | Not present in 0.1 (no dead configuration); they arrive with the UI and Messenger in 0.2. Reader caps are single values (console defaults); `secrets.vault_bridge` is a section (`enabled`, `scope_format`); `audit.sink` added |
| D6 | `export --format`, `diff --format`, `--output` (§12.4) | As listed, plus `--force` (overwrite an output) and `--allow-any-path` on `schema`, `export`, `diff` and `apply`; `schema` has `--force` / `--allow-any-path` too |
| D7 | Access on every action (§12.1) | `schema` (download of a schema) has no access gate: a schema is not data. `list`, `lint`, `runs:*` and `actor:forget` are operator commands without a gate |
| D8 | AuditKit bridge auto when `ActionLogRecorderInterface` exists (§12.3) | Used only when the AuditKit recorder **service** exists (package installed but bundle not registered: the log sink stays) |
| D9 | Twig namespace `@NowoSeedKit` (§2) | The 0.2 UI will use the namespace `NowoSeedKitBundle` (REQ-TWIG-002); 0.1 ships no Twig |
| D10 | `RunStatus` with 8 values (§5.4) | All 8 cases exist; 0.1 writes `running`, `applied`, `partially_applied`, `failed` (console runs). A run row is written only for an apply that writes; a plan without changes writes nothing |
| D11 | `nowo_seed_run.document` holds the normalised upload (§11) | The column exists and stays empty in 0.1 (no upload flow yet) |
| D12 | Lease held answers `CONFLICT_001` + `Retry-After` (§10.2) | Console exit 5 with the seconds to wait (no HTTP in 0.1); `CONFLICT_001` after the replays prints the same exit 5 |
| D13 | Console exit codes (§12.4) | As listed, plus: `actor:forget` declined exits 1; `runs:purge` exits 2 for a bad date; `list` / `runs:list` exit 2 for an invalid filter |

## 25. Deviations in 0.2.0

Built 2026-11-26 for the UI + Messenger slice (§18 B). Beacon's 0.2 answers (2026-11-26) are in §25.1.

| # | Contract text | 0.2.0 |
|---|---|---|
| E1 | Message `ApplySeedRunMessage(runId, planHash)` (§10.5) | **`Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun`** (stable name agreed with Beacon), same two fields, strings |
| E2 | `reader.max_bytes: {http, console}`, `max_items: {http, console}` (§15) | The console caps stay `reader.*`; the web caps are **`ui.max_upload_bytes`** (default **2 MiB**, Beacon) and `ui.max_items` (10 000); `max_tree_bytes` is capped at the upload size |
| E3 | Mount paths carry `{scopeId}` (§12.5) | A kind that requires an id may be mounted without `{scopeId}` (Beacon `/organization/seeds`): the id comes from the new optional port **`SeedCurrentScopeInterface`** (`ui.current_scope`), then the scope resolver checks it like a route id (unknown / invisible ⇒ 404). Checked at build time |
| E4 | — | **`ui.layout`**: base layout of every page (Beacon: wrap in the host layout with no overrides); pages are UiKit scaffolds with data blocks; overrides in `templates/bundles/NowoSeedKitBundle/` still work |
| E5 | `GET /{provider}/export`, `/{provider}/schema.json` (§12.5) | `GET /providers/{provider}/export`, `/providers/{provider}/schema.json`, so a provider name never collides with `upload` / `runs` |
| E6 | Personal-data export: UiKit confirm dialog first (§12.5, §14) | The current export of a provider with personal-data **or secret** fields is a **POST** with CSRF from the confirm dialog; a GET shows the full-page confirmation (no-JS) |
| E7 | Provider not granted ⇒ 404 (§12.1) | Not listed, export / schema 404. An upload naming a provider the viewer may not apply is refused **on the form** (422, static message); access withdrawn between the dry run and the apply ⇒ 403 |
| E8 | Runs list: own runs; all runs with `SEED_APPLY` on every provider of the run (§12.1) | All runs when the viewer has `SEED_APPLY` on **every provider of the scope kind**, else own runs (keeps the keyset pager exact). The run page of another actor (allowed with apply on every provider of the run) shows metadata only: no diff, no apply, no discard |
| E9 | Stored document = normalised canonical JSON lines (§11) | Gzip JSON lines of the **parsed items** (as the reader produced them, floats keep their fraction): the apply re-normalises and re-validates them, and the plan hash is identical (tested). Plaintext secrets never reach it (refused while planning) |
| E10 | Apply re-plans and compares the plan hash (§10.2) | Only while no batch of the run is committed. A resumed run (after `partially_applied` or a redelivery) skips batches ≤ `batches_done` using the **stored batch layout** (`BatchManifest`), and each remaining batch is checked against its reviewed digest under the row locks. A stale plan ⇒ run `failed` (`SEED_005`, document deleted: upload again). A lock conflict before the first batch ⇒ back to `planned` (retry) instead of `failed` |
| E11 | Claim from `planned` / `queued` / `partially_applied` (§10.3) | Also from `running` when its last progress (heartbeat or batch) is older than `apply.lease_seconds`: a killed web request or a dead / slow worker, on the web (its actor) and on Messenger. Every claim is **fenced** (ZCE-L1): it gets the row's `version` as a fencing token and a lease holder of its own; batch progress, heartbeats, `finish()`, the release of the claim and the lease renew / release all require them, so a taken-over worker can neither record a batch, nor change the final status, nor touch the new claimer's leases (two-connection test `aSlowWorkerTakenOverChangesNothingAnyMore`). An admin of the kind (`SEED_APPLY` on every provider of the run) may discard a stalled run of another actor; the expiry sweep ends a stalled run past its TTL. Above `messenger.async_threshold_changes` the actor's apply queues a stalled run again (same cut-off and fencing; a failed hand-over reverts it). A claim whose final `finish()` lost the run reports nothing: no applied event, no success audit (ZCE-v) |
| E12 | Mass prune: second UI confirmation typed with the count (§10.4) | The diff is shown with a warning; the apply form asks for the number of deletions typed (server-checked), no second dialog. An empty prune is refused in the UI (no override flag) |
| E13 | `apply --async` (§12.4) | Not in 0.2 (console applies stay synchronous); moved to 0.3 |
| E14 | BreadcrumbKit bridge (§3) | Crumbs are UiKit `PageHeader` crumbs, which UiKit renders through BreadcrumbKit when it is installed (`ui.breadcrumbs`, `ui.breadcrumb_collection`); no class under `src/Bridge/BreadcrumbKit` |
| E15 | UiKit polling attribute + refresh link (§12.5) | The `auto-refresh` region markup (UiKit Stimulus peer, for hosts that register it) and a plain refresh link; no `Refresh` header |
| E16 | `SEED_010` page "apply failed, N batches applied" 500 (§5.5) | As listed; `SEED_006` and `CONFLICT_00x` answer 409, `SEED_012` (late prune guard) 422, other SeedKit failures of an apply are server errors (500) |
| E17 | REQ-ASSETS-001…005 (§22) | Still — : no own JavaScript or CSS is shipped (UiKit runtimes only) |
| E18 | Document deleted at `runs.document_ttl` (§11) | Also checked at use (ZCE-L5): a document past its TTL is never shown or applied (`SEED_005`, upload again) and is ended on the spot; the Scheduler bridge stays opt-in (it needs `symfony/scheduler` and a Messenger setup); bounded sweeps run on upload and on the history page |
| E19 | Messenger retry policy (§10.5, REQ-MSG-001) | A lock conflict before the first batch puts a queued run back to `queued` (not `planned`), so the transport's retry applies it (ZCE-L2); no exception thrown to Messenger chains its cause, and `ApplyInterruptedException` keeps only the class and code of its cause (ZCE-L4) |
| E20 | CSRF (§12.1) | `ui.enabled` with form CSRF off or without a CSRF token manager fails the container build (`CsrfProtectionPass`, ZCE-L3) |
| E21 | — | Actions pass UiKit kinds only (`SeedKitController::ACTION_KINDS`), never icon names, so the host's `action_icons` decide (Beacon, `bi:history` did not exist) |

### 25.1 Symfony Beacon, 0.2 answers (2026-11-26)

| # | Answer | Where |
|---|---|---|
| QB-SK2-a | Beacon reuses its scope resolver, access checker and actor resolver unchanged for the web and Messenger; unknown or invisible scope ⇒ 404; the bundle itself refuses `system` on the web and on Messenger | `SeedOperations::resolveScope()` (both channels), guard, `SeedRuns::applyQueued()` |
| QB-SK2-b | The host owns the prefixes, one page per scope kind (`/developer/seeds`, `/organization/seeds`, `/projects/{uuid}/settings/seeds`); pages wrappable without overrides; dialogs load when they open | `ui.mounts`, `ui.current_scope` (E3), `ui.layout` (E4), UiKit remote form dialog |
| QB-SK2-c | `ApplySeedRun` routed to Beacon's existing `async` transport; stable, documented name | E1, docs/CONFIGURATION.md |
| QB-SK2-d | Upload cap 2 MB by default, configurable | E2 |
