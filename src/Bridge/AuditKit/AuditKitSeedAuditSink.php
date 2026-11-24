<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Bridge\AuditKit;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorderInterface;
use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Port\SeedAuditSinkInterface;

/**
 * Records seed audit entries in AuditKit's action log (`audit.auditkit`, contract section 12.3): action
 * `seed_kit.<export|apply|prune>`, scope key as AuditKit scope, the record's flat context (no natural key, value or
 * file name). Called after the run's last commit, outside any bundle write unit; AuditKit's recorder persists and
 * flushes its own row.
 */
final readonly class AuditKitSeedAuditSink implements SeedAuditSinkInterface
{
    /**
     * Builds the sink.
     */
    public function __construct(private ActionLogRecorderInterface $recorder)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function record(SeedAuditRecord $record): void
    {
        $this->recorder->recordAndFlush('seed_kit.' . $record->action, null, null, $record->toArray(), $record->scope->key());
    }
}
