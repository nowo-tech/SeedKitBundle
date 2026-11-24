<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The (provider, scope) lease (contract section 10.3) on the real database: take, re-take by the holder, held with the
 * seconds left, expiry takeover, renew after a takeover, release only by the holder.
 */
final class DbalLeaseStoreTest extends DatabaseTestCase
{
    private static function tags(): ProviderName
    {
        return new ProviderName('host.tags');
    }

    #[Test]
    public function oneRunHoldsTheLeaseUntilItExpires(): void
    {
        $scope = SeedScope::platform();

        self::assertSame(0, $this->leases->acquire(self::tags(), $scope, self::runId(1), 300));
        self::assertSame(0, $this->leases->acquire(self::tags(), $scope, self::runId(1), 300), 'the holder takes it again');
        self::assertSame(300, $this->leases->acquire(self::tags(), $scope, self::runId(2), 300));
        self::assertSame(0, $this->leases->acquire(new ProviderName('host.articles'), $scope, self::runId(2), 300), 'another provider');
        self::assertSame(0, $this->leases->acquire(self::tags(), new SeedScope(new ScopeKind('organization'), new ScopeId('acme')), self::runId(2), 300), 'another scope');

        $this->clock->modify('+299 seconds');
        self::assertSame(1, $this->leases->acquire(self::tags(), $scope, self::runId(2), 300));
        $this->clock->modify('+1 second');
        self::assertSame(1, $this->leases->acquire(self::tags(), $scope, self::runId(2), 300), 'at the instant of expiry, still held (at least 1 s)');

        $this->clock->modify('+1 second');
        self::assertSame(0, $this->leases->acquire(self::tags(), $scope, self::runId(2), 300), 'expired: taken over');
        self::assertFalse($this->leases->renew(self::tags(), $scope, self::runId(1), 300), 'the old holder lost it');
        self::assertTrue($this->leases->renew(self::tags(), $scope, self::runId(2), 300));

        $this->leases->release(self::tags(), $scope, self::runId(1));
        self::assertSame(300, $this->leases->acquire(self::tags(), $scope, self::runId(3), 300), 'only the holder releases');
        $this->leases->release(self::tags(), $scope, self::runId(2));
        self::assertSame(0, $this->leases->acquire(self::tags(), $scope, self::runId(3), 60));
        self::assertSame(1, TestDatabase::int($this->connection->fetchOne("SELECT COUNT(*) FROM nowo_seed_lease WHERE provider = 'host.tags' AND scope_kind = 'platform'")), 'one row per provider and scope');
    }

    #[Test]
    public function aRenewalExtendsTheLease(): void
    {
        $scope = SeedScope::platform();
        self::assertSame(0, $this->leases->acquire(self::tags(), $scope, self::runId(1), 60));
        $this->clock->modify('+50 seconds');
        self::assertTrue($this->leases->renew(self::tags(), $scope, self::runId(1), 60));
        $this->clock->modify('+50 seconds');

        self::assertSame(10, $this->leases->acquire(self::tags(), $scope, self::runId(2), 60));
        self::assertFalse($this->leases->renew(self::tags(), $scope, self::runId(2), 60));
    }
}
