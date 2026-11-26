# Roadmap

## Table of contents

- [Slices](#slices)
- [0.2.0: import / export pages and Messenger](#020-import--export-pages-and-messenger)
- [0.3.0](#030)
- [0.4.0 and later](#040-and-later)
- [Road to 1.0.0](#road-to-100)

The bundle is built slice by slice from [CONTRACT.md](CONTRACT.md) (section 18). Each slice is one or more pull
requests with tests, docs and a green CI; every 0.x tag waits for its immediate security audit.

## Slices

| Slice | Version | Content | Status |
| --- | --- | --- | --- |
| S0 scaffold | `main`, no tag | Composer, Docker (MySQL 8.4, MariaDB 11.4, PostgreSQL), Makefile + `release-check`, CI, PHPStan, Igor, Rector, CS, Deptrac, coverage gate, Spec Kit baseline, docs set, Flex recipe, strict config tree | Done (with 0.1.0) |
| A: core + console | **0.1.0** | Provider SPI, readers / writer, schemas and lint, planner, plan hash and digests, applier with leases and batches, run history, access guard / voter / event, audit, Vault and AuditKit bridges, console, contract test kit, 10 locales, demo with two providers | Built on `feat/0.1.0`, waiting for the immediate audit |
| B: UI + async | 0.2.0 | Pages below, Messenger apply | Built (PR, awaiting the immediate audit) |
| C | 0.3.0 | Below | Not started |
| Later | 0.4+ | Below | Not started |

## 0.2.0: import / export pages and Messenger

- Import / export pages per scope kind (route mounts from configuration, for example `/settings/seeds`,
  `/organizations/{scopeId}/seeds`, `/projects/{scopeId}/settings/seeds`) on UiKit scaffolds and FormKit forms: list
  of providers, downloads (empty, current, schema; a notice before personal data), upload with a dry-run diff, apply
  with the plan hash, discard, run history and progress. Twig namespace `NowoSeedKitBundle`.
- Planned runs stored with a short-lived normalised document (`runs.document_ttl`, default 1 h), deleted when the run
  ends (Q-SK-5).
- Messenger apply for big plans (`messenger.async_threshold_changes`), access re-checked for the stored actor through
  `canForActor()` or `messenger.trust_run_actor`. (Console `--async`: moved to 0.3.)
- Scheduler bridge for `runs:purge`; BreadcrumbKit crumbs; HTTP reader caps; 409 + `Retry-After` + `X-Conflict-Code`.
- Playwright e2e per access profile and WebP screenshots; immediate security audit (permissions, CSRF).

## 0.3.0

- Document version upgraders (a provider reads files of its previous version).
- `export --where` filters; diff download as JSON.
- Hardening for providers that write on another connection (`transactional: false`).

## 0.4.0 and later

- Public JSON API `<prefix>/seed/v1` and an operation layer for MCP tools (`seed_schema`, `seed_export`, `seed_diff`,
  `seed_apply`) if the owner asks (Q-SK-9); immediate audit.
- A scaffolding command that writes a provider skeleton calling host services (no generic entity provider, Q-SK-10).

## Road to 1.0.0

1.0.0 is tagged after Beacon's pilot blocks run on 0.1 / 0.2, `bundle-req-audit` reaches 100 % of the applicable REQs
and the REQ-SEC-004 audit passes. Owner decisions are recorded in [CONTRACT.md](CONTRACT.md#23-answers); the accepted
0.1.0 deviations in [CONTRACT.md](CONTRACT.md#24-deviations-in-010).
