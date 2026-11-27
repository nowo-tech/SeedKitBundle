# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- PHP 8.6: `composer.json` allows PHP below 8.7 (was below 8.6). CI adds a forward-compatibility workflow with a
  PHP 8.6 cell and a Symfony 8.2 cell (release candidates, allowed to fail until 8.2.0 is stable).

## [0.2.0] - 2026-11-26

0.2.0: the opt-in import / export pages (UiKit + FormKit) and the asynchronous apply (Messenger), on top of the 0.1
core. No schema change: the 0.1 tables already hold the new columns' data.

### Added

- **Import / export pages** (`ui.enabled`, off by default) per mounted scope kind (`ui.mounts: kind => {path}`, the
  host chooses the prefixes, e.g. `/developer/seeds`, `/organization/seeds`, `/projects/{scopeId}/settings/seeds`),
  loaded with `resource: . type: nowo_seed_kit`; routes `nowo_seed_kit_<kind>_<page>`; prefixes as the container
  parameter `nowo_seed_kit.ui_paths`. Pages on UiKit scaffolds (list, form, show, confirm) wrapped in the host layout
  with `ui.layout` (no template override needed): providers the viewer may use with their downloads (empty template,
  current data, JSON Schema), a confirmation (POST + CSRF) before a current export with personal data or secrets, the
  **upload dialog** (UiKit remote form dialog, loaded when it opens; the same URL is the no-JavaScript page) with row
  errors (row, key, field, rule; never the value), the run page (summary, diff preview before → after, apply through
  the UiKit confirm dialog, discard, typed delete count for a mass prune, progress with a UiKit `auto-refresh` region
  and a refresh link), the runs list (keyset pager) and an internal progress JSON. Twig namespace
  `NowoSeedKitBundle`; FormKit forms (profile `seed_kit`, CSRF `nowo_seed_kit`); 10 locales.
- Guard of every page: no user ⇒ the firewall's entry point; `system` actor ⇒ refused; unknown or invisible scope ⇒
  404; no area access ⇒ 403; a provider the viewer may not use is not listed and its URLs answer 404. Mounts without
  `{scopeId}` take the id from the new optional port `SeedCurrentScopeInterface` (`ui.current_scope`).
- **Stored runs** (`SeedRuns`): an upload is dry-run and stored as a `planned` run holding the parsed document
  (gzip, `GzipRunDocumentCodec`) until it ends or `runs.document_ttl` (default `PT1H`); the same file uploaded again
  by the same actor reuses the run; the batch layout of the reviewed plan (`BatchManifest`) is stored with it. Apply is
  bound to the run's actor and plan hash, re-checks access and claims the run by compare-and-set on `status` +
  `version` (one click wins); a run that stopped after committed batches is resumed after `batches_done`; a lock
  conflict before the first batch puts it back to `planned`; a crashed worker's `running` run is taken over after the
  lease.
- **Asynchronous apply** (`messenger.enabled`): above `messenger.async_threshold_changes` a UI apply is queued as
  `Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun` (run id + plan hash only); the handler applies it as the run's
  actor on the Messenger channel (`canForActor()` or `messenger.trust_run_actor`), retries lock conflicts and held
  leases, and fails for good on any other refusal. Without Messenger a plan above `ui.max_sync_changes` answers
  `SEED_009` (413, "use the console").
- **Scheduler bridge** (`scheduler.enabled`): schedule `nowo_seed_kit` expiring stored documents and purging the
  history (`scheduler.frequency`, default 1 hour). `runs:purge` also expires stored documents.
- Error codes `SEED_008` (run not available, 404) and `SEED_009` (plan too large, 413); conflict pages answer 409 with
  `Retry-After` and `X-Conflict-Code`.
- `RunStoreInterface`: `document()`, `findPlanned()`, `transition()`, `expire(..., $staleBefore)`, `heartbeat()`,
  `create(..., $document)`, `list(..., $actor)`, fenced `markBatch(..., $version)` and `finish(..., $version): bool`; `RunRecord`: `manifest`, `version`, `expiresAt`, `hasDocument`, `updatedAt`, planned counts.

### Fixed (security audit ZCE, before any release)

- A web apply killed mid-run (timeout, OOM) no longer leaves the run `running` forever: a run without progress for
  longer than `apply.lease_seconds` is stalled; its actor takes it over (resume after the last committed batch) or
  discards it, an admin of the kind may discard it, and the expiry sweep ends it past its TTL (ZCE-M1).
- Every claim is fenced by the row version and a lease holder of its own: a taken-over worker cannot record a batch,
  change the final status, release the claim or touch the new claimer's leases; heartbeats during the re-plan
  (ZCE-L1).
- Messenger: a lock conflict before the first batch puts the run back to `queued`, so the retry applies it (ZCE-L2).
- `ui.enabled` without form CSRF fails the container build (`CsrfProtectionPass`, ZCE-L3).
- No exception thrown to Messenger chains the driver or provider exception; `ApplyInterruptedException` keeps only
  `causeClass` / `causeCode` (ZCE-L4).
- A document past `runs.document_ttl` is never shown or applied (`SEED_005`) and is ended on the spot; the history page
  sweeps too (ZCE-L5).
- ZCE-v: a stalled run above `messenger.async_threshold_changes` is queued again by its actor (same lease cut-off and
  version fencing as the synchronous takeover); a failed hand-over puts it back to `partially_applied` or `planned`.
  A claim taken over after its last batch no longer dispatches `SeedRunAppliedEvent` or writes a success audit (the
  new claimer does).
- The *History* action used the explicit icon `history` (rendered as the nonexistent `bi:history`, a 500 for hosts
  with their own icon set): every action now passes a UiKit kind only (`view`, `download`, `import`, `run`, `cancel`,
  `prev`), mapped by the host's `action_icons`.

### Changed

- `RunStoreInterface::finish()` keeps the document of a `partially_applied` run (it may be resumed) and clears it for
  final statuses.
- `SeedOperations::prepare()` is public and `diff()` takes `$announce` (re-plan for display without an event).
- Console runs store their batch layout in `batch_digests` as `[{p, d, w}]` (0.1 rows, a list of digests, still read).

## [0.1.0] - 2026-11-25

First release (0.1.0): core, console and provider contract test kit. The import / export pages and the Messenger apply
follow in 0.2.0.

### Added

- Provider SPI `SeedProviderInterface` (tag `nowo_seed_kit.provider` or `#[AsSeedProvider]`), optional
  `AbstractSeedProvider` base for standard hosts, `RowDiffer`; compiler pass with unique names, known dependencies,
  cycle detection and a compile-time dependency order (Kahn, ties by name).
- Documents in YAML (multi-document), JSON (one object or `{"documents": [...]}`) and JSON Lines (streamed); hardened
  reader: byte, tree, document, item, depth, string, property, line and node caps; UTF-8 only; YAML tags, anchors,
  aliases, merge keys and directives refused; duplicate keys refused.
- Item JSON Schema per provider (draft 2020-12 through `opis/json-schema`, no remote references) with the `x-seed-key`,
  `x-seed-key-normalise`, `x-seed-ref`, `x-seed-secret`, `x-seed-personal-data`, `x-seed-readonly`, `x-seed-float`
  and `x-seed-omit-means` keywords; published document schema; schema lint (command, cache warmer, test kit).
- Exports: empty commented template or current rows, round-trip stable, in YAML, JSON or JSON Lines; secrets only as
  `vault:` references of the scope, else omitted.
- Dry run with a per-row plan, a plan hash (`sk1:`) and batch digests over canonical JSON (RFC 8785 rules); golden
  vectors in `docs/PLAN-HASH.md`.
- Apply: leases per (provider, scope), stale-plan check (`--expect-hash`), batches in transaction-doctrine write units
  on the configured entity manager's connection, per-batch re-plan with the write lock and digest check, progress in
  the same transaction, `afterBatch()` hook, deletes in reverse dependency order, idempotent re-apply; run history in
  `<prefix>seed_run`.
- Prune with its own permission (`SEED_PRUNE`, nobody by default) and mass / empty prune guards.
- Access: `SeedKitGuard`, `SeedKitVoter` (`SEED_EXPORT`, `SEED_APPLY`, `SEED_PRUNE` on `SeedPermissionSubject`), role
  based checker with per-provider overrides, `allow_all` demo checker, `nowo_seed_kit.access_check` event (a deny
  sticks), scope and actor resolver ports; `--actor=system` on the console only.
- Audit through `SeedAuditSinkInterface` (PSR-3 sink; AuditKit bridge when its recorder service exists): applies and
  prunes with every outcome after the access check, exports of providers with personal-data or secret fields.
- Domain events after commit: `SeedExportedEvent`, `SeedRunPlannedEvent`, `SeedBatchCommittedEvent`,
  `SeedRunAppliedEvent`, `SeedRunFailedEvent`.
- Optional VaultBundle bridge validating `vault:<item-uuid>` against the target scope and the actor.
- Console commands `nowo:seed-kit:{list,schema,export,diff,apply,runs:list,runs:purge,actor:forget,lint}` with
  `seed:*` aliases, confined file paths and documented exit codes.
- Contract test kit `Nowo\SeedKitBundle\Testing\SeedProviderContractTestCase` (lint, idempotency and round trip,
  stable exports without secrets, no escalation, row visibility) with in-memory support doubles.
- Strict configuration tree `nowo_seed_kit`; read-only XML (default) or attribute mapping of the two tables with a
  table prefix and binary NO PAD identifier collations on MySQL / MariaDB.
- Translations in 10 locales (en, es, it, fr, pt, de, nl, ca, eu, gl), domain `NowoSeedKitBundle`.
- Demo `demo/symfony8` (FrankenPHP worker, MySQL 8.4, UiKit) with two catalog providers, seed files, downloads and
  Playwright e2e.

### Security

Hardening from the pre-release security audit (ZBK); nothing was released before, so no advisory.

- The YAML pre-scanner also checks the node after a `:` that follows a quoted flow key without a space (`{"k":*a}`,
  `{'k':!!binary …}`), after any `:` inside a flow collection and after a run of `:`, so anchors, aliases and tags can
  no longer reach the parser that way. A `:` or `?` glued to the next character (`:|p`, `:"p`) starts a plain key, and
  explicit keys (`? k` / `: v`, unsupported by Symfony Yaml) are refused (`yaml_explicit_key`). Wherever Symfony could
  read the text differently from the scanner, the reader refuses (`yaml_ambiguous`) instead of guessing: tabs outside
  quoted and block scalars, a quoted / flow / block scalar opening on a line that may continue a value, text right after
  a closed flow collection without a separator, a quote / `[` / `{` opening the line after an open plain scalar of a
  flow collection, text after a quoted scalar that spanned lines, quoted scalars over several lines of a flow
  collection, a bracket inside a flow plain scalar, block-scalar headers other than `|` / `>` with an optional `+` /
  `-`. A `!` after a space or a flow indicator (not after `-` inside a word: `e-!` exports and reads back) is refused
  even inside plain text; a seeded export → import property test guards the round trip. A deterministic differential
  test against Symfony Yaml guards the scanner. The parser also refuses aliases (`PARSE_EXCEPTION_ON_ALIAS`;
  `symfony/yaml` floor raised to `^7.4.12 || ^8.0.12`, the first patches with it). Strings the YAML or JSON parser
  builds must be UTF-8 without NUL bytes (`encoding`).
- YAML nesting is bounded before parsing (`reader.max_depth + 4`, `too_deep`), like the JSON decoder.
- A provider failure during an apply is logged with the exception class and code only, never its message (driver
  messages quote row values).
- A batch whose run progress was already recorded (`markBatch()` compare-and-set false: a replayed or skipped batch)
  rolls its write unit back as a conflict (`CONFLICT_001` / `CONFLICT_002`).
- `SeedKitVoter` decides for the user of the token being voted on, not the session's user.
- Applies that fail with a host or driver exception before the run starts are audited (outcome `error`, no message).
- Console outputs without `--force` are created exclusively (`x`), closing the window between the path check and the
  open.
- Canonical JSON writes floats exactly as ECMAScript / RFC 8785 (`0.000001`, `100000000000000000000`, `1e+21`) from
  `sprintf` digits, so the plan hash no longer depends on `serialize_precision` (CLI and FPM hash alike).
