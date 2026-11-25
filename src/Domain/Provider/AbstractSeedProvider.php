<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Provider;

use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Plan\BatchPlan;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;

use function array_is_list;
use function array_merge;
use function is_array;

/**
 * Optional convenience base for standard Symfony hosts (contract section 6). Every method is also reachable by
 * implementing {@see SeedProviderInterface} directly (DDD hosts may use {@see RowDiffer} the same way).
 *
 * It plans a slice from {@see currentRows()} (one bounded lookup by natural keys) and {@see validateItem()} (domain
 * rules such as "no grant above the actor's own permissions", QB-SK-3), with the diff of {@see RowDiffer}. Defaults:
 * no dependencies, no prune support (override {@see planDeletions()}), `afterBatch()` does nothing, the empty template
 * lists the schema's item `examples`.
 */
abstract class AbstractSeedProvider implements SeedProviderInterface
{
    /**
     * The current values of the rows of these keys that the actor may read, by canonical key (absent: no row). With
     * `$options->lockForUpdate`, read them with the write lock.
     *
     * @param list<NaturalKey> $keys
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array;

    /**
     * Domain errors of one item (references, permissions, business rules); none by default.
     *
     * @return list<RowError>
     */
    protected function validateItem(SeedContext $context, SeedItem $item): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function dependsOn(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function exportEmpty(SeedContext $context): iterable
    {
        $examples = $this->schema()->item['examples'] ?? [];
        if (!is_array($examples)) {
            return [];
        }
        $items = [];
        foreach ($examples as $example) {
            if (is_array($example) && !array_is_list($example)) {
                $item = [];
                foreach ($example as $key => $value) {
                    $item[(string) $key] = $value;
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * {@inheritDoc}
     */
    public function planBatch(SeedContext $context, array $items, PlanOptions $options): BatchPlan
    {
        $keys = [];
        foreach ($items as $item) {
            $keys[] = $item->key;
        }
        $current = $this->currentRows($context, $keys, $options);
        $schema  = $this->schema();
        $changes = [];
        $errors  = [];
        foreach ($items as $item) {
            $errors    = array_merge($errors, $this->validateItem($context, $item));
            $changes[] = RowDiffer::diff($schema, $item, $current[$item->key->canonical] ?? null);
        }

        return new BatchPlan($changes, $errors);
    }

    /**
     * {@inheritDoc}
     */
    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function afterBatch(SeedContext $context): void
    {
    }

    /**
     * SHA-256 hex of the canonical writable current values (see {@see RowDiffer::fingerprint()}).
     *
     * @param array<string, mixed> $current
     */
    protected function fingerprint(array $current): string
    {
        return RowDiffer::fingerprint($this->schema(), $current);
    }
}
