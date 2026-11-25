<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Audit;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;

/**
 * One audit entry (FR-009, contract section 12.3): what happened, who, where, which providers, counts, hashes, run id,
 * channel and outcome. No natural key, value or file name.
 */
final readonly class SeedAuditRecord
{
    /** An export of a provider with personal-data or secret fields. */
    public const EXPORT = 'export';

    /** An apply (every outcome). */
    public const APPLY = 'apply';

    /** An apply with prune. */
    public const PRUNE = 'prune';

    /** Success. */
    public const OUTCOME_SUCCESS = 'success';

    /** A failure outside the SeedKit error codes (a host or driver exception); its class and message are not kept. */
    public const OUTCOME_ERROR = 'error';

    /**
     * @param string $action one of the action constants
     * @param list<string> $providers provider names
     * @param array<string, int> $counts ChangeOp value → rows (export: `rows`)
     * @param string $outcome {@see OUTCOME_SUCCESS}, an error code or {@see OUTCOME_ERROR}
     */
    public function __construct(
        public string $action,
        public ActorRef $actor,
        public SeedScope $scope,
        public array $providers,
        public array $counts,
        public Channel $channel,
        public string $outcome,
        public ?string $documentHash = null,
        public ?string $planHash = null,
        public ?string $runId = null,
    ) {
    }

    /**
     * The entry as a flat context array (logs, AuditKit context).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action'        => $this->action,
            'actor'         => $this->actor->value,
            'scope'         => $this->scope->key(),
            'providers'     => $this->providers,
            'counts'        => $this->counts,
            'channel'       => $this->channel->value,
            'outcome'       => $this->outcome,
            'document_hash' => $this->documentHash,
            'plan_hash'     => $this->planHash,
            'run_id'        => $this->runId,
        ];
    }
}
