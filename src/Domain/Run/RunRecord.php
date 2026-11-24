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
 * A row of the run history (`<prefix>seed_run`, contract section 11): metadata only in 0.1 (console runs keep no
 * document). The actor is null after `actor:forget`.
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
    ) {
    }
}
