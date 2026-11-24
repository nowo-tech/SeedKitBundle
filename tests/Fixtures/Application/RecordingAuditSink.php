<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Application;

use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Port\SeedAuditSinkInterface;

/**
 * Keeps every audit record.
 */
final class RecordingAuditSink implements SeedAuditSinkInterface
{
    /** @var list<SeedAuditRecord> */
    public array $records = [];

    public function record(SeedAuditRecord $record): void
    {
        $this->records[] = $record;
    }
}
