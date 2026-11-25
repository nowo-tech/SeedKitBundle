<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchPlan;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

/**
 * A kind of seed data (contract section 6), implemented by the host and tagged `nowo_seed_kit.provider` (or
 * `#[AsSeedProvider]`). The bundle owns format, validation, diff, hash, batching, leases, access and audit; the
 * provider owns the data and writes **only through the host's own handlers** (same validation, events and audit as the
 * CRUD; no generic entity writes, Q-SK-10).
 *
 * Rules (checked by `Nowo\SeedKitBundle\Testing\SeedProviderContractTestCase`):
 * - stateless and worker-safe: nothing kept between calls;
 * - filter every read, plan and prune by the actor in the context (row-level visibility);
 * - never put database ids in items; natural keys only;
 * - return exactly the requested keys, in the requested order (the bundle re-checks with `===`);
 * - export rows in natural-key byte order (canonical key); bounded queries per batch;
 * - refuse items that would grant the actor more than the actor holds (no escalation by seed, QB-SK-3);
 * - never set consent / lawful-basis fields from a seed (mark them `x-seed-readonly`, QB-SK-4).
 */
interface SeedProviderInterface
{
    /**
     * The provider name (`crm.contacts`).
     */
    public function name(): ProviderName;

    /**
     * The document version written and read (one per provider in 0.x).
     */
    public function version(): int;

    /**
     * The scope kinds the provider is available in.
     *
     * @return list<ScopeKind>
     */
    public function scopes(): array;

    /**
     * The JSON Schema of one item with the `x-seed-*` keywords (contract section 7).
     */
    public function schema(): SeedSchema;

    /**
     * Providers applied before this one (roles before members); references (`x-seed-ref`) must point at them.
     *
     * @return list<ProviderName>
     */
    public function dependsOn(): array;

    /**
     * Example items for the commented empty template (plain JSON-compatible arrays, no real data).
     *
     * @return iterable<array<string, mixed>>
     */
    public function exportEmpty(SeedContext $context): iterable;

    /**
     * The rows the actor may read, in natural-key byte order; secret properties as {@see SecretReference}
     * or absent, never plain text.
     *
     * @return iterable<SeedItem>
     */
    public function exportCurrent(SeedContext $context): iterable;

    /**
     * Plans one slice (≤ batch size) with one bounded set of queries (lookup by natural keys). With
     * `$options->lockForUpdate` the current rows are read with the write lock, inside the batch transaction.
     *
     * @param list<SeedItem> $items
     */
    public function planBatch(SeedContext $context, array $items, PlanOptions $options): BatchPlan;

    /**
     * Prune only: rows of the scope the actor may delete whose key is not in `$seen`, in natural-key byte order.
     *
     * @return iterable<RowChange> changes with op Delete
     */
    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable;

    /**
     * Applies one batch through the host's handlers, inside the transaction the bundle opened: no commit, no
     * transaction of its own on the same connection, events after commit only.
     */
    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult;

    /**
     * Called after each batch commit or rollback and before a replayed attempt: clear the provider's own state or its
     * own entity manager (memory stays flat; detached entities are not reused).
     */
    public function afterBatch(SeedContext $context): void;
}
