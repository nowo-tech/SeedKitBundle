<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Context;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\PendingKeys;
use Nowo\SeedKitBundle\Domain\Run\Channel;

/**
 * What a provider receives on every call (contract section 6): the resolved target scope (never an id from the
 * file), the actor (row-level visibility is the provider's job, F9), the channel, the run id when applying, the keys
 * earlier documents of the run will create, the prune flag and whether the viewer may see current values in a diff.
 * Built per call; providers must not keep it.
 */
final readonly class SeedContext
{
    /**
     * Builds the context.
     */
    public function __construct(
        public SeedScope $scope,
        public ActorRef $actor,
        public Channel $channel,
        public ?RunId $runId = null,
        public PendingKeys $pending = new PendingKeys(),
        public bool $prune = false,
        public bool $canSeeCurrentValues = true,
    ) {
    }

    /**
     * The same context bound to a run.
     */
    public function withRun(RunId $runId): self
    {
        return new self($this->scope, $this->actor, $this->channel, $runId, $this->pending, $this->prune, $this->canSeeCurrentValues);
    }
}
