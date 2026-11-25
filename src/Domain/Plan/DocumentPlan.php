<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;

use function count;

/**
 * The plan of one document: counts by operation, a preview of at most `plan.preview_rows` changes per operation, and
 * the batch digests of the upserts (document order) and of the deletes (natural-key order). The full change stream is
 * never held: it is hashed while it is produced.
 */
final readonly class DocumentPlan
{
    /**
     * @param array<string, int> $counts ChangeOp value → rows
     * @param list<RowChange> $preview
     * @param list<string> $upsertDigests one hex digest per upsert batch
     * @param list<string> $deleteDigests one hex digest per delete batch
     * @param list<bool> $writes per batch (upserts then deletes): whether it holds a create, update or delete
     */
    public function __construct(
        public ProviderName $provider,
        public int $version,
        public string $schemaFingerprint,
        public array $counts,
        public array $preview,
        public array $upsertDigests,
        public array $deleteDigests,
        public array $writes = [],
    ) {
    }

    /**
     * Rows of one operation.
     */
    public function count(ChangeOp $op): int
    {
        return $this->counts[$op->value] ?? 0;
    }

    /**
     * Rows that write (create + update + delete).
     */
    public function changes(): int
    {
        return $this->count(ChangeOp::Create) + $this->count(ChangeOp::Update) + $this->count(ChangeOp::Delete);
    }

    /**
     * Batches of this document that write.
     */
    public function writingBatches(): int
    {
        $total = 0;
        foreach ($this->writes as $writes) {
            $total += $writes ? 1 : 0;
        }

        return $total;
    }

    /**
     * Batches of this document.
     */
    public function batches(): int
    {
        return count($this->upsertDigests) + count($this->deleteDigests);
    }
}
