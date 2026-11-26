<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

use Throwable;

/**
 * An apply stopped after it had started writing (contract sections 10.2 / 10.3, Q-SK-3): a batch digest changed inside
 * its transaction (`SEED_006`), the provider's handler failed (`SEED_010`), or a lock conflict survived the replays
 * (`CONFLICT_001` when no batch of this run was committed yet, `CONFLICT_002` after one was). Committed batches stay;
 * re-applying the same document converges (idempotent). Only the class and code of the cause are kept, never the
 * cause itself (its message may quote row values).
 */
final class ApplyInterruptedException extends SeedKitException
{
    /** A batch digest differed inside its transaction. */
    public const ROW_CHANGED = 'SEED_006';

    /** The provider's handler failed. */
    public const PROVIDER_FAILED = 'SEED_010';

    /** A lock conflict survived the replays. */
    public const CONFLICT = 'CONFLICT';

    /** Class of the cause (no message, no previous exception). */
    public readonly ?string $causeClass;

    /** Code of the cause, as text. */
    public readonly ?string $causeCode;

    /**
     * @param string $kind one of the class constants
     * @param int $batchesCommitted batches of this run committed before the failure
     * @param int $batchesTotal batches of the run
     * @param string|null $runId the run row (history), when one was created
     * @param int|null $retryAfterSeconds for a conflict
     * @param Throwable|null $cause the failure behind it: only its class and code are kept (`$causeClass`,
     *                              `$causeCode`), never the exception itself, whose message may quote row values
     *                              (personal data) and would reach logs and Messenger failure transports (ZCE-L4)
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $batchesCommitted,
        public readonly int $batchesTotal,
        public readonly ?string $runId = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $cause = null,
    ) {
        $this->causeClass = $cause instanceof Throwable ? $cause::class : null;
        $this->causeCode  = $cause instanceof Throwable ? (string) $cause->getCode() : null;
        parent::__construct(match ($kind) {
            self::ROW_CHANGED     => 'A row changed while the plan was applied; ',
            self::PROVIDER_FAILED => 'The provider failed to apply a batch; ',
            default               => 'The database kept refusing the write because of a lock conflict; ',
        } . $batchesCommitted . ' of ' . $batchesTotal . ' batch(es) were applied and stay. Apply the document again.');
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
