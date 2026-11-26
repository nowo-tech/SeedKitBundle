<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Run;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;

/**
 * A row of the run history (`<prefix>seed_run`, contract section 11). Console runs keep metadata only; a UI run (0.2)
 * also stores its parsed document until it ends or expires ({@see $hasDocument}, read with
 * `RunStoreInterface::document()`). The actor is null after `actor:forget`. `$version` is the compare-and-set counter
 * of the run claim (contract section 10.3); `$manifest` the batch layout of the reviewed plan (null for 0.1 rows).
 */
final readonly class RunRecord
{
    /**
     * @param array<string, int> $providers provider name → document version
     * @param list<string> $batchDigests
     * @param array<string, int> $counts ChangeOp value → rows
     */
    public function __construct(
        public RunId $id,
        public SeedScope $scope,
        public ?ActorRef $actor,
        public Channel $channel,
        public RunStatus $status,
        public array $providers,
        public bool $prune,
        public ?DocumentHash $documentHash,
        public PlanHash $planHash,
        public array $batchDigests,
        public int $batchesDone,
        public array $counts,
        public ?string $errorCode,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $finishedAt = null,
        public ?BatchManifest $manifest = null,
        public int $version = 1,
        public ?DateTimeImmutable $expiresAt = null,
        public bool $hasDocument = false,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /** Count keys of the reviewed plan of a UI run (next to the rows written so far: `create`, `update`, `delete`). */
    public const PLANNED = 'planned_';

    /** Count key set to 1 when the reviewed plan is a mass prune (needs the typed confirmation, contract 10.4). */
    public const MASS_PRUNE = 'mass_prune';

    /**
     * Rows of one operation in the reviewed plan (`planned_<op>`), else written so far (`<op>`, console runs).
     */
    public function planned(string $op): int
    {
        return $this->counts[self::PLANNED . $op] ?? $this->counts[$op] ?? 0;
    }

    /**
     * Rows the reviewed plan writes (create + update + delete).
     */
    public function changes(): int
    {
        return $this->planned('create') + $this->planned('update') + $this->planned('delete');
    }

    /**
     * Whether the reviewed plan deletes more than `prune.max_ratio` of the current rows.
     */
    public function isMassPrune(): bool
    {
        return ($this->counts[self::MASS_PRUNE] ?? 0) === 1;
    }

    /**
     * Whether the actor is the run's own actor (byte for byte).
     */
    public function isOwnedBy(ActorRef $actor): bool
    {
        return $this->actor instanceof ActorRef && $this->actor->value === $actor->value;
    }
}
