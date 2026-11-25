# FrankenPHP worker audit (`resetKernel = false`)

## Table of contents

- [Scope](#scope)
- [Findings](#findings)
- [The only memo](#the-only-memo)
- [Worker test](#worker-test)
- [Static analysis](#static-analysis)
- [Host rules](#host-rules)

Audited revision: 0.1.0 (pre-release, `[Unreleased]`).

## Scope

FrankenPHP worker mode with the kernel booted once, kept alive and **not reset** between requests (kernel reset false:
the `services_resetter` is not relied on). Every bundle service must give the same answer to the next request, the next
user and the next tenant. The precondition is the kernel kept alive; `FRANKENPHP_RESET_KERNEL=false` (or an
equivalent runtime option) is only how a host gets there.

## Findings

| Area | Finding | Status |
| --- | --- | --- |
| Services | `final readonly` services hold configuration only (settings, table names, caps, role lists, the provider locator and the compile-time dependency order) | ✅ |
| Per-run state | Plans, hashers, error bags, pending keys, natural-key sets and contexts are created per call and live in local variables | ✅ |
| Documents | The reader copies the input into a private `php://temp` stream owned by the returned document set; console streams are closed in `finally` | ✅ |
| Actor and user | Read from the token storage on every call (`SecurityActorResolver`, `SeedKitGuard`) | ✅ |
| Access events | `SeedAccessCheckEvent` holds its grant / deny flags on the per-decision event object | ✅ |
| Leases | Released in `finally`; a crashed holder's lease expires after `apply.lease_seconds` | ✅ |
| Entity managers | Never persisted, flushed, cleared or reset by the bundle (DBAL only; the write unit's registry holds no entity manager) | ✅ |
| Schema validator | `OpisSchemaValidator`: the only memo (below) | ✅ (documented) |
| PCRE limit | `pcre.backtrack_limit` lowered during one validation and restored in `finally` | ✅ |
| Providers | Contract test kit: two exports in a row return the same bytes; the provider's `afterBatch()` clears its own state after each batch | ✅ (host code) |
| `exit` / `die` | None in `src/` | ✅ |

## The only memo

`Nowo\SeedKitBundle\Infrastructure\Schema\OpisSchemaValidator` keeps compiled schemas in a per-process map keyed by
the schema fingerprint (SHA-256 of the canonical item schema). The data is immutable and the same for every user and
tenant (it is derived from provider code, never from a request), there is at most one entry per provider version, and
the map is bounded (`MAX_SCHEMAS` = 64 entries; when full it is emptied before the next entry). The validator also
sets opis' maximum error count on every call from `validation.max_errors` (configuration, the same for every call). It
implements `ResetInterface` and is tagged `kernel.reset`, and a
unit test calls `reset()`; correctness never depends on the reset.

## Worker test

`tests/Integration/Worker/WorkerModeTest::oneKernelServesManyMixedRequestsWithoutLeaks`:

| Item | Value |
| --- | --- |
| Kernel | One booted kernel, never rebooted or reset |
| Iterations | 1 000 (warm-up 200) |
| Mix | Exports of two providers, dry runs and applies, alternating two actors (`alice`, `bob`) with different row visibility |
| Assertions | Bob never sees Alice's private row in any export; every plan and apply has exactly the expected change count; Alice's row is unchanged at the end; 200 `applied` runs recorded |
| Memory | `memory_get_usage()` growth after the warm-up below 256 KiB |

It runs in `make test` and in CI.

## Static analysis

- PHPStan level max with the `nowo-tech/phpstan-frankenphp` classic and worker rulesets (`make phpstan`, CI job
  "PHPStan").
- Igor (`composer igor`, `make igor`): six writes carry an `@igor-ignore` with their reason: the three grant / deny
  flags of the per-decision `SeedAccessCheckEvent` (not a shared service) and the three writes of the validator memo
  (store a compiled schema, empty the map in `reset()`, replace the opis loader so its own cache goes too).

## Host rules

- Providers are services of your application: keep them stateless. Clear what a batch loaded in `afterBatch()`
  (the demo clears its entity manager there).
- If your application bypasses the services resetter, clearing your own entity managers between requests stays your
  job; SeedKit never touches them.
- Long applies belong on the console (or, from 0.2, Messenger), not in a worker request.
