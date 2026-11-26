# Concurrency (REQ-RUNTIME-003)

## Table of contents

- [Write paths](#write-paths)
- [How an apply runs](#how-an-apply-runs)
- [Atomicity](#atomicity)
- [Leases](#leases)
- [Batch digests and row locks](#batch-digests-and-row-locks)
- [Conflict codes](#conflict-codes)
- [Rules applied](#rules-applied)
- [Host options](#host-options)
- [Tests and the Databases job](#tests-and-the-databases-job)
- [Residual risk](#residual-risk)

## Write paths

| Write path | Unit | Concurrency control | Conflict answer (console exit) |
| --- | --- | --- | --- |
| Batch apply | One write unit per writing batch (`apply.batch_size`, default 500, 1–5 000) | Lease per (provider, scope); the provider re-reads the batch's rows with its write lock (`planBatch` with `lockForUpdate`, by natural key); the re-planned slice must match the reviewed batch digest; the run's progress (`batches_done`, counts) is updated in the same transaction with `WHERE batches_done = n − 1` | Lock conflict: replayed up to `transaction.max_replays` (3), the provider's `afterBatch()` runs before each replay; then `CONFLICT_001` when no batch of the run committed (5) or `CONFLICT_002` (6). Digest mismatch: `SEED_006`, rollback, run stops (3 before any commit, 6 after) |
| Lease take | One statement each, outside any unit | Insert-or-ignore of the `(provider, scope_kind, scope_id)` row, then `UPDATE … SET run_id = ?, locked_until = ? WHERE … AND (locked_until < now OR run_id = ?)`, then read back | Held by another run: `CONFLICT_001` with the seconds left (5); nothing written |
| Lease renew / release | One statement each | `UPDATE … WHERE … AND run_id = ?` | Renew lost (lease expired and taken): the run stops as a conflict (`CONFLICT_002` after a commit) |
| Run create | One write unit | Insert of a fresh UUID v7 row (`running`) after the leases and the stale-plan check | — |
| Run finish | One write unit | `UPDATE … WHERE id = ?` (`applied`, `partially_applied`, `failed` + error code) | Best effort: a failure is logged, the lease expires anyway |
| UI run create (upload, 0.2) | One write unit | Insert of a `planned` row with the gzip document; the same actor + scope + document + plan + prune reuses the live planned run instead | — |
| **Run claim** (UI apply, Messenger, 0.2) | One write unit | `UPDATE … SET status = 'running', version = version + 1 WHERE id = ? AND status IN (planned, queued, partially_applied[, running]) AND version = ? [AND (status <> 'running' OR updated_at < now − lease)]`: one request or worker wins; a crashed worker's run is taken over after `apply.lease_seconds` | 0 rows: another request has it, or it is over: the page shows the run (idempotent double click / redelivery); a lock on the row: replays, then `CONFLICT_001` 409 + `Retry-After` |
| Queue (0.2) | One write unit | Same compare-and-set `planned / partially_applied → queued` before the message is dispatched; a failed dispatch puts it back | 0 rows: already queued / running |
| Release of a claim (0.2) | One write unit, fenced by the claim's version | `running →` back to `planned` (web) / `queued` (Messenger) / `partially_applied` when nothing was written (lease held, lock conflict before the first batch, prune guard, a provider failure in the re-plan); `running → failed` on a stale plan | Best effort: a stuck claim is taken over after the lease |
| Heartbeat (0.2) | One statement | `UPDATE … SET updated_at = now, version = version + 1 WHERE id = ? AND version = ? AND status = 'running'` | 0 rows: the claim was lost, the attempt stops (`CONFLICT_001`) |
| Discard (0.2) | One write unit | Compare-and-set `planned / partially_applied → discarded`, document deleted | 0 rows: `SEED_008` (404) |
| Document expiry (0.2) | Batches of 500 ids (100 on an upload) | Keyset; `planned / queued → expired`, `partially_applied` keeps its status; document deleted | Best effort on upload; the purge command / schedule retry |
| `runs:purge` | Batches of 500 ids | Keyset `SELECT id … ORDER BY id LIMIT 500`, `DELETE … WHERE id IN (…)` | Lock: exit 1, run it again |
| `actor:forget` | Batches of 500 | `UPDATE … SET actor_ref = NULL` by id batch | Lock: exit 1 |

The dry run (`diff`) and exports write nothing and take no lock.

## How an apply runs

1. **Read, normalise, validate** the whole file (schema, secrets, duplicate keys, provider domain checks through
   `planBatch()`), no lock, nothing written. Any error: `SEED_002` / `SEED_003` / `SEED_004`, exit 2.
2. **Leases**: take the lease of every (provider, scope) of the run, in provider-name byte order.
3. **Re-plan** the whole run without locks and compare with `--expect-hash` (`SEED_005`, nothing written). A plan
   without changes stops here: no run row, no transaction (re-applying a file is a no-op).
4. **Record** the run (`running`) with the reviewed batch digests.
5. For each writing batch, upserts in dependency order, then deletions in reverse dependency order: open a write unit
   → re-plan the slice with `lockForUpdate` → compare the batch digest → `applyBatch()` through the provider →
   `markBatch()` → commit → `afterBatch()` → renew the leases → `SeedBatchCommittedEvent`.
6. **Finish** the run (`applied`), release the leases in `finally`, dispatch `SeedRunAppliedEvent`, audit.

### Stored runs (0.2)

**Fencing (ZCE-L1).** A claim takes the row's `version` as its fencing token and a lease holder of its own (a fresh
id per claim). Every later write of that attempt requires them: the heartbeats (after the leases and after the
re-plan), `markBatch()` (`AND version = ? AND status = 'running'`), the final `finish()`, the release of the claim and
the lease renew / release (by holder). A worker whose run was taken over after the lease therefore stops at its next
write: its batch rolls back, it changes no status and it cannot release the new claimer's leases.

**Stalled runs (ZCE-M1).** A `running` run without progress for longer than `apply.lease_seconds` (killed request,
dead worker) is taken over by its actor's next apply on the web or by the next Messenger delivery; its actor may
discard it, and so may an admin of the kind. The expiry sweep (upload, history page, `runs:purge`, Scheduler) ends a
stalled run past its TTL: `partially_applied` when batches were committed, else `expired`, document deleted.

A UI run is applied by whoever claims it (the web request or the Messenger worker). The batches use the same unit as
above, but their numbering and reviewed digests come from the **stored batch layout** of the reviewed plan, and the
batches up to `batches_done` are skipped: a redelivered message, a second worker or a retry after a partial apply
never applies a batch twice (`markBatch()` is a compare-and-set on `batches_done`; when another worker recorded the
batch first, ours rolls back with a conflict). Only a run with no committed batch is compared with its reviewed plan
hash; after that, each remaining batch must still match its digest under the row locks.

## Atomicity

Every check that can fail runs before the first write. After that:

- a plan of **one batch** (≤ `apply.batch_size` writing rows per provider) is atomic: one transaction;
- a **larger** plan is batch-atomic: each batch commits on its own with its progress. A late failure (a row changed
  meanwhile, a lock conflict after the replays, a provider bug) stops the run as `partially_applied` (exit 6) and
  reports the committed batches. Applying the same file again **converges**: committed rows plan as unchanged and are
  never written twice.

## Leases

One row per (provider, scope) in `<prefix>seed_lease`, primary key `(provider, scope_kind, scope_id)`, binary on
MySQL / MariaDB. A lease lasts `apply.lease_seconds` (300 s) and is renewed after every committed batch; it is
released in `finally`, and a crashed run's lease expires by itself. Two applies of the same provider in the same scope
never run together: the second one gets `CONFLICT_001` with the seconds to wait (exit 5) and writes nothing. Leases
are taken in name order, so two multi-provider runs cannot deadlock on each other's leases.

## Batch digests and row locks

The plan hash protects the window between the dry run and the start of the apply; it does not protect the window
between the re-plan and batch *n*. So each batch is re-planned **inside** its transaction, with the provider's write
lock on the batch's rows (in natural-key order), and its digest must equal the reviewed one
([PLAN-HASH.md](PLAN-HASH.md#batch-digests)). A row edited by someone else meanwhile changes the digest: the batch
rolls back with `SEED_006`, earlier batches stay, and the next apply plans the new state.

## Conflict codes

| Code | Meaning | Console exit | Safe to retry |
| --- | --- | --- | --- |
| `CONFLICT_001` | Lease held by another run, or a lock conflict (deadlock, lock-wait timeout, serialization failure) survived the replays before any batch of the run committed; nothing was written | 5 | Yes, after the printed delay |
| `CONFLICT_002` | The same after at least one batch committed; the run is `partially_applied` | 6 | Re-apply the file (it converges) |
| `SEED_006` | A batch's rows changed after the re-plan | 3 (nothing committed) / 6 | Review the new diff, then apply |

Retry classes (transaction-doctrine): MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03. Unique
violations and lost connections are not conflicts: they propagate. In 0.1 there is no HTTP write path; the 0.2 UI
answers these codes with 409, `Retry-After` and `X-Conflict-Code`.

## Rules applied

- Units: `nowo-tech/transaction-doctrine` `WriteUnit` around `DoctrineTransactionRunner::forConnection()` on the
  connection of `doctrine.entity_manager`. The unit's registry (`ConnectionOnlyRegistry`) holds that connection and
  **no entity manager**: a replay rolls back the connection's leftover levels and never clears, resets or flushes an
  entity manager, the host's included.
- Inside a host transaction the unit runs once, as a nested level (savepoint); a failure rolls back to it and
  propagates ("SAVEPOINT does not exist" is never treated as success).
- No HTTP, file or mail I/O inside a unit: the file is read and validated before the first unit; JSON Lines items are
  read between units.
- Replays hold nothing from an earlier attempt: the slice is re-planned and the provider's `afterBatch()` runs before
  a replayed attempt.
- With transaction-doctrine's bundle installed, its `CommitLedger` is passed to the unit: an attempt that already
  committed is never replayed.
- Events (`SeedBatchCommittedEvent`, `SeedRunAppliedEvent`, `SeedRunFailedEvent`) and the audit entry come after the
  commits, never inside a unit.
- Identifiers (provider, scope kind and id, actor, run id, hashes) are binary NO PAD on MySQL / MariaDB and compared
  with `===` in PHP (REQ-SEC-006).

## Host options

- **Same connection** (default): give `doctrine.entity_manager` the manager your providers write with. Their writes
  (including an entity manager `flush()` inside `applyBatch()`) are then inside the batch transaction.
- **Another connection**: when your handlers write on a different connection, their writes are outside the bundle's
  transaction: a batch is then at-least-once (a failure after the handler wrote and before the progress committed
  replays it). Providers stay correct because they upsert by natural key, but plan for it.
- `applyBatch()` must not commit or open its own transaction on the bundle's connection, and must dispatch its own
  events after the commit.
- Messenger: arrives in 0.2; do not wrap a future seed handler in `doctrine_transaction` (the units would run once,
  nested, without replay).

## Tests and the Databases job

0.2 adds `tests/Integration/Database/StoredRunConcurrencyTest` (two connections): a run claimed on the other
connection is busy and writes nothing; a batch recorded by another worker makes ours roll back; a lock on the run row
makes the claim replay and give up with nothing written, then the run applies; a crashed worker's run is taken over
after the lease; a slow live worker taken over by another claim (with its own lease holder) changes nothing any
more: its batch rolls back, the status and the new lease stay. `DbalStoredRunTest` checks the fencing of heartbeats,
batches and `finish()`, the expiry of stalled runs, the compare-and-set, the binary document and the expiry on each database.

`tests/Integration/Database` runs on SQLite by default and on a server with `NOWO_TEST_DATABASE_URL`
(`make test-with-db`, MySQL 8.4 from the `db` Compose profile). `ConcurrencyTest` uses **two connections**:

- two applies of one provider and scope: one lease, the other `CONFLICT_001` with a delay, nothing written by it;
- an edit committed during the run: the run stops at the changed batch (`SEED_006`), the earlier batch stays,
  `partially_applied`;
- a row lock held by the other connection before any commit: `CONFLICT_001`, nothing written, connection usable;
- a row lock after a committed batch: `CONFLICT_002`.

`ApplyTest` adds the stale plan (`SEED_005`), a partial run that converges on re-apply, the constant query count per
batch, idempotency (a second apply writes nothing to the host tables), the host-transaction savepoint path, prune in
reverse dependency order and a DDD host on its own connection. `DbalLeaseStoreTest`, `DbalRunStoreTest` and
`DbalWriteRunnerTest` cover the stores and the unit (replay with the attempt number, savepoint).

The CI job **"Databases"** runs the database suite on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL
([GITHUB_CI.md](GITHUB_CI.md#jobs-of-this-repository)); it is a required check once branch protection lists checks.

## Residual risk

- Lease statements run outside a unit, in autocommit; a crash between a lease take and the run row leaves only a
  lease that expires after `apply.lease_seconds`.
- A provider writing on another connection gets at-least-once batches (see [Host options](#host-options)).
- DBAL 3 without `setNestTransactionsWithSavepoints(true)`: inside a host transaction a failure marks the whole host
  transaction rollback-only (DBAL's emulated nesting).
- In 0.1 a partially applied run is not resumed in place: re-applying the file starts a new run that converges.
