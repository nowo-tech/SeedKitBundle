# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Mitigations](#mitigations)
  - [Access and scopes](#access-and-scopes)
  - [Console access policy](#console-access-policy)
  - [Secrets](#secrets)
  - [Reader hardening](#reader-hardening)
  - [Files and paths](#files-and-paths)
  - [Exact identifiers](#exact-identifiers)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Permissions and exposure](#permissions-and-exposure)
- [Limits, DoS and timeouts](#limits-dos-and-timeouts)
- [CSRF and admin UI](#csrf-and-admin-ui)
- [Dependency and updates](#dependency-and-updates)
- [Residual risks](#residual-risks)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Scope

This document covers SeedKitBundle 0.1.x: the provider SPI, the document reader and writer, schema validation, the
planner and applier, the run and lease tables, the access guard and voter, the console commands, the AuditKit and
Vault bridges and the contract test kit. The 0.2 UI and Messenger apply get their own section and audit. Privacy
(GDPR) is in [PRIVACY.md](PRIVACY.md); the policy for reporting vulnerabilities is in
[`.github/SECURITY.md`](../.github/SECURITY.md).

## Attack surface

| Surface | Input | Notes |
| --- | --- | --- |
| Seed files | YAML, JSON, JSON Lines from files or stdin | Untrusted text: may come from an AI, a ticket, another tenant |
| JSON Schemas | Provider code | Trusted (host code), linted; `$schema` in a document is never fetched |
| Console commands | Arguments, options, file paths | Operator channel (shell access) |
| Providers | Host code | Write through the host's handlers inside the bundle's transaction |
| Database writes | `<prefix>seed_run`, `<prefix>seed_lease` (DBAL, bound parameters) | Host data only through providers |
| Configuration | `nowo_seed_kit` tree, Flex recipe | Strict tree; no secrets; no `.env` setting |
| Bridges | AuditKit (audit sink), VaultBundle (secret references) | Optional, switchable |
| PHP API | `SeedOperations`, voter attributes | Every call re-checks scope and access |

## Threat model

| Threat | Control |
| --- | --- |
| Privilege escalation through an import (roles, permissions, menus to external URLs) | Providers write through the host's handlers (same validation and authorisation as the CRUD); `SEED_APPLY` per provider and scope; row-level filtering by the provider (`$context->actor`); prune needs `SEED_PRUNE` (nobody by default); a provider that grants permissions refuses grants above the actor's own (QB-SK-3), proven by the contract test `refusesItemsThatEscalate` |
| Cross-tenant writes | The target scope comes from `--scope` (or, in 0.2, the route) and is resolved and authorised by the host's scope resolver (`ACCESS_001` when unknown or not visible); the document's `scope` is a kind only; `meta.exportedFrom` never selects a target (a warning when it differs); providers receive the scope value object, never an id from the file |
| Look-alike keys (`Bob` vs `bob`, NBSP, ZWSP) | Exact identifier value objects (no trimming, no folding, control and format characters refused), binary NO PAD columns on MySQL / MariaDB, `===` re-check of every key a provider returns, explicit key normalisers only |
| Stale, replayed or double apply | Plan hash (`--expect-hash`), batch digests re-checked under the write lock, lease per (provider, scope), progress committed with each batch; a plan without changes writes nothing |
| Forged apply | Console only in 0.1; the system actor is refused outside the console; access re-checked on every call (no session mutation exists in 0.1) |
| Secrets leaking into files, logs, AI prompts | [Secrets](#secrets) |
| Resource exhaustion (huge files, billion laughs, deep nesting, regex backtracking) | [Reader hardening](#reader-hardening), batch size bounds, `pcre.backtrack_limit` lowered during validation |
| Deserialization / code execution through YAML | No objects, constants, custom tags or dates; any tag, anchor, alias, merge key or directive refused before parsing |
| Path traversal, symlink escape, stream wrappers, SSRF via file arguments | [Files and paths](#files-and-paths); nothing is ever fetched (`$schema`, `$ref`) |
| Information leak in errors and logs | Errors carry row index, natural key (printed to the operator only, never logged), JSON Pointer, rule and a static translated message; never the value |
| Destructive prune in production | Opt-in flag, separate permission (nobody by default), mass-prune ratio and empty-file guards, dry run with counts, audit entry |
| Provider bugs (wrong keys, ids, plain secrets, wrong order) | Fail closed with `SEED_011` before any write |
| Formula injection | Not applicable (no CSV) |

## Mitigations

### Access and scopes

- Every export, plan and apply runs `SeedOperations`, which resolves the scope through the host port (unknown kind,
  missing or extra id, or not visible: `ACCESS_001`) and asks the guard for each provider: `SEED_EXPORT` for exports,
  `SEED_APPLY` for plans and applies, plus `SEED_PRUNE` with `--prune` (`ACCESS_002`).
- The guard's default decision: role-based checker (`access_roles` + the action's roles, role hierarchy applied; prune
  nobody by default), no user means denied outside the console unless `allow_unauthenticated` (demos only; the
  `allow_all` checker cannot be selected without it), then the `nowo_seed_kit.access_check` event where a deny always sticks.
- A host voter granting `SEED_*` wins under Symfony's `affirmative` strategy: map permissions with the checker or the
  event to keep deny-by-default ([USAGE.md](USAGE.md#voter-attributes)).
- A dry run without `SEED_EXPORT` hides the current ("before") values in the diff.

### Console access policy

The console is the operator's channel: a person with shell access can already read the configuration and the
database. Without a user, the access decision on the console defaults to **granted**, and the
`nowo_seed_kit.access_check` event is dispatched with `Channel::Console` so a host listener can deny (for example
everything except the platform scope). Writing commands require `--actor` (audit) unless `console.require_actor:
false`. `--actor=system` is accepted only on the console (QB-SK-5), is audited as `system`, and is refused on every
other channel and by the voter. `schema`, `list`, `lint`, `runs:list`, `runs:purge` and `actor:forget` have no access
check: they handle no host data (a schema is not data) or only the bundle's own run history.

### Secrets

- **Export**: a secret field (`x-seed-secret: true`) is written as `vault:<ref>` only when the provider returns a
  `SecretReference` and the validator confirms it belongs to the target scope; otherwise it is omitted. A provider
  returning a plain string for a secret field makes the export fail closed (`SEED_011`); the contract test kit greps
  every export for the known secret values (SC-005).
- **Import**: only the `vault:` syntax is accepted; plain text is refused (`SEED_004`, `secret_plaintext`) without
  echoing or logging it; a reference must pass `isUsable($reference, $scope, $actor)`; without a validator every
  reference is refused (fail closed).
- **Absent keeps**: an omitted secret keeps the current value; clearing a secret through a seed is not possible in 0.x.
- **Diff**: shows reference changes (`vault:a` → `vault:b`) and hides every other secret value.
- **Vault bridge**: `vault:<item-uuid>` of an item owned by the target scope, not trashed, viewable by the actor
  (console `system`: ownership only).
- Secret-looking property names (`password`, `token`, `api_key`, `private_key`, `credential`, `iban`, `card`, …) must
  declare `x-seed-secret` explicitly or the schema lint fails.

### Reader hardening

- Caps: bytes read (counted while reading, 256 MiB), YAML / JSON tree 4 MiB (above: JSON Lines only), documents 20,
  items per document 1 000 000, depth 16, string 64 KiB, properties 256, JSON Lines line 1 MiB, nodes per item
  10 000, row errors 200 ([CONFIGURATION.md](CONFIGURATION.md#reader-caps)).
- Encoding: UTF-8 only; BOM stripped; UTF-16/32, NUL bytes and invalid UTF-8 refused.
- YAML: a pre-scanner refuses tags (`!`, `!!`), anchors (`&`), aliases (`*`), merge keys (`<<`) and directives (`%`)
  outside quoted and block scalars and comments; the parser runs with `PARSE_EXCEPTION_ON_INVALID_TYPE` and without
  `PARSE_OBJECT`, `PARSE_OBJECT_FOR_MAP`, `PARSE_CONSTANT`, `PARSE_CUSTOM_TAGS`, `PARSE_DATETIME`; duplicate keys fail.
- JSON: `JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING`, bounded depth, duplicate keys refused by a scanner.
- Schema validation: opis/json-schema without any resolver (only local `#/…` references), no remote loading,
  `pattern` with a reduced backtrack limit; `$dynamicRef`, `$vocabulary`, remote `$ref` and the `regex` format are
  refused by the lint.
- Floats only in `x-seed-float` properties; NaN and infinities refused.
- The test suite carries a hostile corpus: anchors and aliases, `!php/object`, `!php/const`, merge keys, deep nesting,
  long lines, duplicate keys, BOM / UTF-16, NUL bytes, invalid UTF-8, big integers.

### Files and paths

- Console file arguments and `--output` must resolve (`realpath`, symlinks resolved) inside `console.allowed_dirs`
  (default `config/seeds`, `var/seeds`) unless `--allow-any-path`; inputs must be regular files (no directory, FIFO or
  device); stream wrappers and URL-like arguments (`phar:`, `php:`, `data:`, `glob:`, any `scheme:`) are refused;
  outputs never go through a symlink and never overwrite an existing file without `--force`. `-` is stdin / stdout.
- Documents have no include or import directive; `$schema` is informational and never fetched.

### Exact identifiers

REQ-SEC-006: provider names, scope kinds and ids, actor refs, natural keys and run ids are exact text (no trimming,
folding or normalisation; control and format characters refused); bundle identifier columns are binary NO PAD on
MySQL (`utf8mb4_0900_bin`) and MariaDB (`utf8mb4_nopad_bin`), exact by default on PostgreSQL and SQLite; every key a
provider returns is re-checked with `===` on its canonical bytes. Case folding exists only as an explicit, visible
key normaliser in the schema.

## Secrets and cryptography

SeedKit stores and handles no secret values: only opaque `vault:` references, validated by a port. It uses SHA-256
for integrity only, never for authentication: the document hash (`sha256:` of the raw bytes, audit), the plan hash
(`sk1:`) and the batch digests ([PLAN-HASH.md](PLAN-HASH.md)). Run ids are UUID v7 (ordering, not secrecy). No
encryption, signing or key material is involved.

## Logging

Exception messages and log records are static (translation keys `seed_kit.error.*`, `seed_kit.read.*`,
`seed_kit.row.*`) with structured context: provider, scope key, counts, hashes, run id, exception class. Never a value
from the file, a natural key, a file path or name, or a secret. Provider exceptions are logged with their class and the
exception object on the `seed_kit` channel; the console prints only the static message. Audit entries carry no
natural key, value or file name.

## Permissions and exposure

- 0.1 exposes no route, controller or public HTTP API; nothing is reachable over HTTP.
- Defaults: area, export and apply need `ROLE_ADMIN`; prune nobody; console granted by default but deniable by a
  listener; no unauthenticated access outside demos.
- The demo (`demo/symfony8`) binds to 127.0.0.1 and uses demo-only credentials from `.env.example` placeholders.

## Limits, DoS and timeouts

No outbound HTTP, no subprocess, no fetch (REQ-RUNTIME-001). The bounds are the reader caps above, batches of at most
`apply.batch_size` (≤ 5 000) rows with one bounded provider query set each, `pcre.backtrack_limit` lowered to 100 000
during schema validation, leases with a TTL (`apply.lease_seconds`, renewed per batch, expiring on a crash) and the
database's lock-wait timeout with at most `transaction.max_replays` attempts ([CONFIGURATION.md](CONFIGURATION.md#timeouts)).
Large files must be JSON Lines, read line by line.

## CSRF and admin UI

CSRF: N/A — no session mutations in 0.1 (console and PHP API only, REQ-SEC-005). Admin UI: N/A in 0.1; the 0.2 import /
export pages use FormKit forms with CSRF and get their own immediate audit.

## Dependency and updates

`composer audit` runs in every CI matrix cell (Critical / High fail the build) and before each tag. Runtime
dependencies are Symfony components, Doctrine DBAL / ORM / bundle, `opis/json-schema`,
`nowo-tech/transaction-contract` and `nowo-tech/transaction-doctrine`; Dependabot proposes updates weekly. Security
fixes are released as patch versions of the supported minor ([`.github/SECURITY.md`](../.github/SECURITY.md)).

## Residual risks

- **Host providers**: the bundle cannot see what a provider's handler does. A provider that skips the host's
  authorisation, ignores the actor, or grants permissions above the actor's own defeats the model; the contract test
  kit checks the rules it can observe (escalation, row visibility, secrets, idempotency).
- **Host voter**: a host voter that grants `SEED_*` overrides the bundle's deny-by-default (Symfony `affirmative`).
- **Console**: granted by default; restrict it with a `nowo_seed_kit.access_check` listener when shell users are not
  all trusted operators.
- **Natural keys in console output**: row errors print the natural key to the operator's terminal (never to logs);
  CI logs of `diff` / `apply` may therefore contain keys such as e-mail addresses.
- **Denied attempts**: an apply refused by the scope resolver or the guard is audited with its error code
  (`ACCESS_001` / `ACCESS_002`) and the scope as requested; a console `apply` that fails during its own dry run
  (read or validation errors) stops before the apply call and is not audited (nothing was written).
- **Another connection**: providers writing on a connection other than the bundle's are outside the batch
  transaction (at-least-once batches, [CONCURRENCY.md](CONCURRENCY.md#host-options)).
- **Partially applied runs**: a late failure leaves committed batches in place by design (re-apply converges).

## Release security checklist (12.4.1)

Confirm before each 0.x tag and 1.0.0 in the release PR or tag note:

| # | Item | How |
| --- | --- | --- |
| 1 | SECURITY.md current | This file and [`.github/SECURITY.md`](../.github/SECURITY.md) (supported versions) |
| 2 | `.env` ignored | `.gitignore` covers `.env`; demos ship `.env.example` with placeholders only |
| 3 | No secrets in the repository | No credential in code, tests, fixtures, demos or docs (GitGuardian / `gitleaks`) |
| 4 | Safe configuration and recipe | Recipe and defaults: deny by default, prune nobody, no unauthenticated access, no fetch |
| 5 | Input / output | Reader caps and YAML / JSON hardening, schema validation, exact identifiers, paths confined; console output never echoes values |
| 6 | Dependencies | `composer audit` clean (CI and before the tag) |
| 7 | No secrets in logs | Static messages, no values / keys / paths in logs or audit |
| 8 | Cryptography | SHA-256 for integrity only; no secret material handled |
| 9 | Permissions and exposure | Voter / checker / event matrix tests green; no HTTP surface in 0.1 |
| 10 | Limits / DoS | Hostile corpus and cap tests green; batch and lease bounds unchanged or documented |
| 11 | AI security audit (REQ-SEC-004) | Immediate audit of the slice recorded with a passing grade in `BUNDLES_SECURITY_ANALYSIS.md`, every blocking finding fixed |

## Reporting

Report vulnerabilities privately by e-mail to **hectorfranco@nowo.tech** or through GitHub Security Advisories of
`nowo-tech/SeedKitBundle`. Please do not open a public issue. See [`.github/SECURITY.md`](../.github/SECURITY.md).
