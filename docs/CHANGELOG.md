# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
