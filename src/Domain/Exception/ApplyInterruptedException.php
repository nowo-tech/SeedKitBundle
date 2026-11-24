<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

use Throwable;

/**
 * An apply stopped after it had started writing (contract sections 10.2 / 10.3, Q-SK-3): a batch digest changed inside
 * its transaction (`SEED_006`), the provider's handler failed (`SEED_010`), or a lock conflict survived the replays
 * (`CONFLICT_001` when no batch of this run was committed yet, `CONFLICT_002` after one was). Committed batches stay;
 * re-applying the same document converges (idempotent). The cause is kept as previous exception for the log only.
 */
final class ApplyInterruptedException extends SeedKitException
{
    /** A batch digest differed inside its transaction. */
    public const ROW_CHANGED = 'SEED_006';

    /** The provider's handler failed. */
    public const PROVIDER_FAILED = 'SEED_010';

    /** A lock conflict survived the replays. */
    public const CONFLICT = 'CONFLICT';

    /**
     * @param string $kind one of the class constants
     * @param int $batchesCommitted batches of this run committed before the failure
     * @param int $batchesTotal batches of the run
     * @param string|null $runId the run row (history), when one was created
     * @param int|null $retryAfterSeconds for a conflict
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $batchesCommitted,
        public readonly int $batchesTotal,
        public readonly ?string $runId = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(match ($kind) {
            self::ROW_CHANGED     => 'A row changed while the plan was applied; ',
            self::PROVIDER_FAILED => 'The provider failed to apply a batch; ',
            default               => 'The database kept refusing the write because of a lock conflict; ',
        } . $batchesCommitted . ' of ' . $batchesTotal . ' batch(es) were applied and stay. Apply the document again.', 0, $previous);
    }

    /**
     * Whether earlier batches were committed (the run is partially applied).
     */
    public function isPartial(): bool
    {
        return $this->batchesCommitted > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        if ($this->kind === self::CONFLICT) {
            return $this->isPartial() ? 'CONFLICT_002' : 'CONFLICT_001';
        }

        return $this->kind;
    }
}
