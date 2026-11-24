<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only mapping of `<prefix>seed_lease` (one row per provider and scope). Written through DBAL only
 * ({@see \Nowo\SeedKitBundle\Infrastructure\Dbal\DbalLeaseStore}); XML twin in `Resources/config/doctrine`.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'seed_lease')]
class SeedLease
{
    /**
     * Mapping only; rows come from DBAL.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'provider', type: 'string', length: 64)]
        public readonly string $provider,
        #[ORM\Id]
        #[ORM\Column(name: 'scope_kind', type: 'string', length: 32)]
        public readonly string $scopeKind,
        #[ORM\Id]
        #[ORM\Column(name: 'scope_id', type: 'string', length: 128)]
        public readonly string $scopeId,
        #[ORM\Column(name: 'run_id', type: 'string', length: 36)]
        public readonly string $runId,
        #[ORM\Column(name: 'locked_until', type: 'datetime_immutable')]
        public readonly DateTimeImmutable $lockedUntil,
    ) {
    }
}
