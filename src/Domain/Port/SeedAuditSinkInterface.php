<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;

/**
 * Where audit entries go (default: PSR-3 log channel `seed_kit`; AuditKit bridge when installed). Called after the
 * run's last commit, outside any bundle write unit.
 */
interface SeedAuditSinkInterface
{
    /**
     * Records one entry.
     */
    public function record(SeedAuditRecord $record): void;
}
