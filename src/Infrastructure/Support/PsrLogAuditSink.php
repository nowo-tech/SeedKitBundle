<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Support;

use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Port\SeedAuditSinkInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The default audit sink (contract section 12.3): one log record on the `seed_kit` channel at `audit.log_level`
 * (default `notice`), with the record's flat context (no natural key, value or file name).
 */
final readonly class PsrLogAuditSink implements SeedAuditSinkInterface
{
    /**
     * @param string $level a PSR-3 level
     */
    public function __construct(private ?LoggerInterface $logger, private string $level = LogLevel::NOTICE)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function record(SeedAuditRecord $record): void
    {
        $this->logger?->log($this->level, 'Seed {action} by {actor} in {scope}: {outcome}.', $record->toArray());
    }
}
