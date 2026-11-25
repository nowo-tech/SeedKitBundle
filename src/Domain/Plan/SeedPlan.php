<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;

/**
 * The dry-run result of a run (contract section 5.3): one plan per document in dependency order, the plan hash, the
 * prune flag and the warnings (static keys, e.g. `exported_from_differs`).
 */
final readonly class SeedPlan
{
    /** Warning: `meta.exportedFrom` is not the target scope id (Q-SK-12). */
    public const WARNING_EXPORTED_FROM = 'exported_from_differs';

    /**
     * @param list<DocumentPlan> $documents
     * @param list<string> $warnings
     */
    public function __construct(
        public SeedScope $scope,
        public bool $prune,
        public array $documents,
        public PlanHash $hash,
        public array $warnings = [],
    ) {
    }

    /**
     * Rows that write, over every document.
     */
    public function changes(): int
    {
        $total = 0;
        foreach ($this->documents as $document) {
            $total += $document->changes();
        }

        return $total;
    }

    /**
     * Batches over every document.
     */
    public function batches(): int
    {
        $total = 0;
        foreach ($this->documents as $document) {
            $total += $document->batches();
        }

        return $total;
    }

    /**
     * Batches that write, over every document.
     */
    public function writingBatches(): int
    {
        $total = 0;
        foreach ($this->documents as $document) {
            $total += $document->writingBatches();
        }

        return $total;
    }

    /**
     * Rows of one operation over every document.
     */
    public function count(ChangeOp $op): int
    {
        $total = 0;
        foreach ($this->documents as $document) {
            $total += $document->count($op);
        }

        return $total;
    }
}
