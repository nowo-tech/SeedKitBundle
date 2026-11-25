<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use HashContext;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;

use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function implode;

/**
 * The plan hash and the batch digests (contract section 9, `docs/PLAN-HASH.md`). Incremental and constant-memory:
 * created per plan, fed while the plan is produced, never shared.
 *
 * Change line: `op 0x1F canonical(key) 0x1F (current ?? "-") 0x1F (desired ?? "-") 0x1E`.
 * Plan stream: `"nowo-seed-kit/plan/1" 0x1E scope 0x1E prune(0|1) 0x1E`, then per document in dependency order
 * `provider 0x1F version 0x1F schemaFingerprint 0x1E` and its change lines (items in document order, then deletes).
 * Batch stream: `"nowo-seed-kit/batch/1" 0x1E provider 0x1E batchNo 0x1E` + the batch's change lines.
 */
final class PlanHasher
{
    /** Unit separator. */
    public const US = "\x1F";

    /** Record separator. */
    public const RS = "\x1E";

    private HashContext $context;

    /**
     * Starts a plan.
     */
    public function __construct(SeedScope $scope, bool $prune)
    {
        $this->context = hash_init('sha256');
        hash_update($this->context, 'nowo-seed-kit/plan/1' . self::RS . $scope->key() . self::RS . ($prune ? '1' : '0') . self::RS);
    }

    /**
     * Starts a document.
     */
    public function document(ProviderName $provider, int $version, string $schemaFingerprint): void
    {
        hash_update($this->context, $provider->value . self::US . $version . self::US . $schemaFingerprint . self::RS);
    }

    /**
     * Adds a change.
     */
    public function change(RowChange $change): void
    {
        hash_update($this->context, self::line($change));
    }

    /**
     * The plan hash (the hasher cannot be fed afterwards).
     */
    public function finish(): PlanHash
    {
        return new PlanHash('sk1:' . hash_final($this->context));
    }

    /**
     * The digest of one batch.
     *
     * @param list<RowChange> $changes
     */
    public static function batchDigest(ProviderName $provider, int $batchNumber, array $changes): string
    {
        $lines = [];
        foreach ($changes as $change) {
            $lines[] = self::line($change);
        }

        return hash('sha256', 'nowo-seed-kit/batch/1' . self::RS . $provider->value . self::RS . $batchNumber . self::RS . implode('', $lines));
    }

    /**
     * The change line.
     */
    public static function line(RowChange $change): string
    {
        return $change->op->letter() . self::US . $change->key->canonical . self::US . ($change->currentFingerprint ?? '-') . self::US . ($change->desiredFingerprint ?? '-') . self::RS;
    }
}
