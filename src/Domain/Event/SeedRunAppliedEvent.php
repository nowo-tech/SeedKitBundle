<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Event;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;

/**
 * Dispatched after the last batch of a run committed.
 */
final readonly class SeedRunAppliedEvent
{
    /**
     * @param list<string> $providers
     * @param array<string, int> $counts ChangeOp value → rows
     */
    public function __construct(
        public RunId $runId,
        public array $providers,
        public SeedScope $scope,
        public array $counts,
        public ?DocumentHash $documentHash,
        public PlanHash $planHash,
        public ActorRef $actor,
        public Channel $channel,
    ) {
    }
}
