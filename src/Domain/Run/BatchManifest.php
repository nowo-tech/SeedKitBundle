<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Run;

use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;

use function count;
use function is_array;
use function is_bool;
use function is_string;

/**
 * The batch layout of a reviewed plan, stored with its run (contract sections 9 and 10.2 step 5): per provider, in
 * dependency order, one entry per batch (upserts in document order, then deletes) with its digest and whether it
 * writes. A resumed run (Messenger redelivery, retry after a partial apply) numbers and checks its batches against
 * this layout, never against a re-plan, so a batch committed earlier is skipped and never applied twice.
 */
final readonly class BatchManifest
{
    /**
     * @param array<string, list<array{0: string, 1: bool}>> $batches provider name → [digest, writes] per batch
     */
    public function __construct(public array $batches = [])
    {
    }

    /**
     * The layout of a plan.
     */
    public static function fromPlan(SeedPlan $plan): self
    {
        $batches = [];
        foreach ($plan->documents as $document) {
            $entries = [];
            foreach ([...$document->upsertDigests, ...$document->deleteDigests] as $i => $digest) {
                $entries[] = [$digest, $document->writes[$i] ?? false];
            }
            $batches[$document->provider->value] = $entries;
        }

        return new self($batches);
    }

    /**
     * The layout read back from its stored list (`[{p, d, w}, …]`); null for a 0.1 row (a list of digests only).
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (!is_array($stored) || $stored === []) {
            return null;
        }
        $batches = [];
        foreach ($stored as $entry) {
            if (!is_array($entry) || !is_string($entry['p'] ?? null) || !is_string($entry['d'] ?? null) || !is_bool($entry['w'] ?? null)) {
                return null;
            }
            $batches[$entry['p']][] = [$entry['d'], $entry['w']];
        }

        return new self($batches);
    }

    /**
     * The stored list, in order.
     *
     * @return list<array{p: string, d: string, w: bool}>
     */
    public function toStored(): array
    {
        $out = [];
        foreach ($this->batches as $provider => $entries) {
            foreach ($entries as [$digest, $writes]) {
                $out[] = ['p' => (string) $provider, 'd' => $digest, 'w' => $writes];
            }
        }

        return $out;
    }

    /**
     * The digests of one provider's batches, in order.
     *
     * @return list<string>
     */
    public function digests(string $provider): array
    {
        $out = [];
        foreach ($this->batches[$provider] ?? [] as [$digest]) {
            $out[] = $digest;
        }

        return $out;
    }

    /**
     * Whether each batch of one provider writes, in order.
     *
     * @return list<bool>
     */
    public function writes(string $provider): array
    {
        $out = [];
        foreach ($this->batches[$provider] ?? [] as [, $writes]) {
            $out[] = $writes;
        }

        return $out;
    }

    /**
     * Every digest, in order (the 0.1 `batch_digests` list).
     *
     * @return list<string>
     */
    public function flat(): array
    {
        $out = [];
        foreach ($this->batches as $entries) {
            foreach ($entries as [$digest]) {
                $out[] = $digest;
            }
        }

        return $out;
    }

    /**
     * The batches that write, over every provider (the run's progress total).
     */
    public function writingBatches(): int
    {
        $total = 0;
        foreach ($this->batches as $entries) {
            foreach ($entries as [, $writes]) {
                $total += $writes ? 1 : 0;
            }
        }

        return $total;
    }

    /**
     * Batches over every provider.
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->batches as $entries) {
            $total += count($entries);
        }

        return $total;
    }
}
