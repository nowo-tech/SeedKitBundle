<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

use Nowo\SeedKitBundle\Domain\Document\SeedItem;

/**
 * One batch handed to {@see \Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface::applyBatch()} inside the batch
 * transaction: the changes that write (creates, updates or deletes; unchanged rows are left out) and, for creates and
 * updates, the normalised item of each change by canonical key.
 */
final readonly class AppliedBatch
{
    /**
     * @param int $number 1-based batch number in the run
     * @param list<RowChange> $changes
     * @param array<string, SeedItem> $items canonical natural key → item
     */
    public function __construct(public int $number, public array $changes, public array $items = [])
    {
    }

    /**
     * The item of a create / update change, or null for a delete.
     */
    public function item(RowChange $change): ?SeedItem
    {
        return $this->items[$change->key->canonical] ?? null;
    }
}
