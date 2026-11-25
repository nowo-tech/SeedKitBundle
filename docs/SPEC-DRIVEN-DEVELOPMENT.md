# Spec-driven development

This document is the single entry point for **what SeedKitBundle guarantees**, **how it is proven** and **which
`REQ-*` anchors** the Makefile and CI carry. There is no separate executable spec language: **PHPUnit**, **PHPStan**
and **Deptrac** are the normative proof alongside the prose.

## Table of contents

- [Layers kept in sync](#layers-kept-in-sync)
- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers](#requirement-identifiers)
- [Contributor workflow](#contributor-workflow)
- [Relationship to Engram](#relationship-to-engram)
- [GitHub Spec Kit](#github-spec-kit)
- [See also](#see-also)

## Layers kept in sync

| Layer | Role | Where |
| --- | --- | --- |
| Design contract | Provider SPI, format, schema keywords, plan hash, apply semantics, storage, access, security, configuration, worker design, tests, slices; answers and 0.1.0 deviations | [CONTRACT.md](CONTRACT.md) |
| GitHub Spec Kit baseline | Feature spec (user scenarios, `FR-*`, `SC-*`, non-goals) and the 100 % code inventory of `src/` | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md), [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md), manual in [SPEC-KIT.md](SPEC-KIT.md) |
| Product behaviour | What integrating applications may rely on | [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [INSTALLATION.md](INSTALLATION.md), [FORMAT.md](FORMAT.md), [PLAN-HASH.md](PLAN-HASH.md), [SECURITY.md](SECURITY.md), [PRIVACY.md](PRIVACY.md) |
| Traceability anchors | Stable `REQ-*` ids in the Makefile and CI, so PRs and issues can cite tooling behaviour | [Requirement identifiers](#requirement-identifiers) |
| Change management | Consumer-facing deltas | [CHANGELOG.md](CHANGELOG.md), [UPGRADING.md](UPGRADING.md), [RELEASE.md](RELEASE.md) |

Every change that adds, moves or removes a source file updates the baseline spec, the code inventory and the integrator
docs in the same pull request.

## User stories

| ID | Story | Scope | Integrator docs |
| --- | --- | --- | --- |
| US-01 | **As an** integrator, **I want** to expose one kind of data as a seed provider that writes through my own services or handlers **so that** seeds get the same validation, events and audit as my CRUD. | Provider SPI, `AbstractSeedProvider`, registration | [USAGE.md](USAGE.md#writing-a-provider) |
| US-02 | **As an** operator, **I want** to export an empty template or the current rows and a JSON Schema **so that** I (or an AI) can edit a seed file safely. | Exporter, writer, schema publisher | [USAGE.md](USAGE.md#ai-editing-workflow), [FORMAT.md](FORMAT.md) |
| US-03 | **As an** operator, **I want** a dry run that validates the whole file and shows every change with a plan hash **so that** I review before anything is written. | Reader, normaliser, planner, plan hash | [USAGE.md](USAGE.md#diff), [PLAN-HASH.md](PLAN-HASH.md) |
| US-04 | **As an** operator, **I want** to apply exactly the reviewed plan, in batches, idempotently, with an opt-in guarded prune **so that** large files are safe and re-applying converges. | Applier, leases, digests, prune guards | [USAGE.md](USAGE.md#apply), [CONCURRENCY.md](CONCURRENCY.md) |
| US-05 | **As a** security owner, **I want** export / apply / prune permissions per provider and scope, secrets kept out of files and every write audited **so that** a seed cannot escalate or leak. | Guard, voter, checker, event, secrets, audit | [SECURITY.md](SECURITY.md), [USAGE.md](USAGE.md#access-mapping) |
| US-06 | **As a** platform maintainer, **I want** worker-mode safety and safe writes under contention **so that** the bundle runs on a kept-alive kernel next to live traffic. | Stateless services, write units, leases | [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md), [CONCURRENCY.md](CONCURRENCY.md) |
| US-07 | **As a** provider author, **I want** a shipped contract test kit **so that** my provider proves round trip, idempotency, no secrets, no escalation and row visibility. | `SeedProviderContractTestCase` | [USAGE.md](USAGE.md#contract-test-kit) |

## Bundle functional scope

**Goal:** one structure for seeds and bulk data in Symfony: a provider per kind of data, documents validated by a JSON
Schema, a reviewed dry run, and an idempotent batched apply through the host's own handlers.

**In scope (0.1.0):** provider SPI and registration, YAML / JSON / JSON Lines reader and writer, schema lint and
publication, planner, plan hash and batch digests, applier with leases, run history, access guard, voter and event,
audit sink with AuditKit bridge, secret references with Vault bridge, console commands, contract test kit,
translations ([CONTRACT.md](CONTRACT.md) slice A).

**Explicit non-goals:** a replacement for CRUD pages, migrations or Doctrine fixtures; direct SQL or entity writes on
host data; a generic Doctrine entity provider; cross-instance sync, scheduled imports, AI generation inside the bundle;
CSV / XLSX; a web UI, Messenger apply and a public JSON API in 0.1 (UI and Messenger planned for 0.2.0).

**Not in the Packagist API:** `tests/`, `demo/`, `specs/`, `.specify/`, `.cursor/` (excluded from the archive) and the
`@internal` classes (`ExactText`). The demo is illustrative only.

## Validating the functional spec

```bash
make test-coverage-100   # PHPUnit (Unit, Integration, Database on SQLite) + 100 % of src/
make test-with-db        # Database suite on MySQL 8.4 (REQ-TEST-005); CI adds MariaDB 11.4 and PostgreSQL
make phpstan igor        # PHPStan level max + FrankenPHP worker rulesets, Igor worker-state audit
composer deptrac         # layer rules (REQ-ARCH-002)
composer qa              # cs-check + test
make qa                  # the same in the container
make release-check       # the whole pre-release chain
```

Coverage floor: **100 %** of `src/`. Any behaviour change ships with tests under `tests/`.

## Requirement identifiers

| `REQ-*` | Where | Meaning |
| --- | --- | --- |
| REQ-MAKE-001 / 002 | `Makefile` (`help`, `ensure-up`, `release-check`) | Standard targets, sequential release chain |
| REQ-MAKE-007 | `Makefile` `down-dev` | Stop without removing volumes |
| REQ-MAKE-008 | `Makefile` `update-deps` | Bundle then demo dependency updates (optional monorepo include) |
| REQ-MAKE-010 | `Makefile` `COMPOSE_BIN` | Docker Compose V2 first |
| REQ-MAKE-004 / REQ-I18N-002 | `Makefile` `validate-translations` | YAML syntax and key parity of the locales |
| REQ-TEST-003 / 006 | `Makefile` `test-coverage-100`, `coverage-check` | 100 % gate |
| REQ-TEST-005 | `Makefile` `test-with-db`, `test-coverage-with-db` | Database suite on a server |
| REQ-TEST-011 | `Makefile` `demo-smoke`; CI "Demo smoke" | Demo boots, HTTP 200 |
| REQ-DEMO-013 | `Makefile` `test-e2e`, `demo-screenshots`; `e2e.yml` | Playwright e2e and README screenshots |
| REQ-CS-001 | `Makefile` `validate-phpdoc` | PHPDoc on every class and public method |
| REQ-CS-008 | `Makefile` `igor` | Igor worker-state audit |
| REQ-ARCH-002 | `deptrac.yaml`, `composer deptrac` | Layer dependencies |
| REQ-TWIG-004 / 005 | `Makefile` `check-twig-extra`, `check-no-raw-html-form` | Twig Extra in Twig demos; no raw form tags |
| REQ-REL-003 | `Makefile` `check-open-prs` | No open PRs left at release |
| REQ-GIT-001 | `Makefile` `setup-hooks`, `check-no-cursor-coauthor`, `strip-cursor-coauthor-from-history`; CI job "Git history" | No agent co-author trailers |
| REQ-RUNTIME-003 | CI job "Databases" | Real-database concurrency suite |

When scripted behaviour changes, update the matching anchor comment and this table in the same pull request.

## Contributor workflow

1. **Clarify** the behaviour against [CONTRACT.md](CONTRACT.md) and the baseline spec (add or adjust `US-*`, `FR-*`,
   `SC-*` first).
2. **Implement with tests**: a failing test first where practical; keep the 100 % gate, PHPStan, Deptrac, Igor and
   the worker test green.
3. **Anchor scripts**: tooling changes keep or add their `REQ-*` comment.
4. **Ship integrator docs**: USAGE / CONFIGURATION / FORMAT / SECURITY / CHANGELOG / UPGRADING whenever behaviour or
   configuration changes; refresh `specs/001-baseline/code-inventory.md` when `src/` files change.

## Relationship to Engram

[ENGRAM.md](ENGRAM.md) covers the organisation-wide plane (Engram MCP memory, `.cursor/mcp.json`, checklist hygiene).
This document covers the product plane (behaviour and traceability). Neither replaces the other; Engram points here
for local `REQ-*` traceability.

## GitHub Spec Kit

The repository is initialised for GitHub Spec Kit (`specify init --here --force --integration cursor-agent --script
sh`; `.specify/`, Cursor Agent skills in `.cursor/skills/speckit-*`). The full manual (install, initialise, navigate
`.specify/` vs `specs/`, skills) is [SPEC-KIT.md](SPEC-KIT.md). The baseline lives in
[`specs/001-baseline/`](../specs/001-baseline/): `spec.md` (scenarios, requirements, success criteria) and
`code-inventory.md` (every file of `src/` mapped to `FR-*`). Keep the inventory aligned with `src/` in every pull
request that adds, moves or removes a source file.

## See also

- [GitHub Spec Kit](SPEC-KIT.md) · [Baseline spec](../specs/001-baseline/spec.md)
- [Usage](USAGE.md) · [Configuration](CONFIGURATION.md) · [Installation](INSTALLATION.md)
- [Contributing](CONTRIBUTING.md) · [Release](RELEASE.md)
- [Demo with FrankenPHP](DEMO-FRANKENPHP.md) · [FrankenPHP worker audit](FRANKENPHP-WORKER-AUDIT.md)
