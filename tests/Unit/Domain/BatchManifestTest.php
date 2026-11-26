<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain;

use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\DocumentPlan;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Run\BatchManifest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The stored batch layout of a reviewed plan (contract 9, 10.2 step 5).
 */
final class BatchManifestTest extends TestCase
{
    #[Test]
    public function theLayoutFollowsThePlan(): void
    {
        $plan = new SeedPlan(SeedScope::platform(), true, [
            new DocumentPlan(new ProviderName('a.tags'), 1, 'f', [], [], ['u1', 'u2'], ['d1'], [true, false, true]),
            new DocumentPlan(new ProviderName('a.items'), 1, 'f', [], [], ['u3'], [], [false]),
        ], new PlanHash('sk1:' . str_repeat('a', 64)));
        $manifest = BatchManifest::fromPlan($plan);

        self::assertSame(['u1', 'u2', 'd1'], $manifest->digests('a.tags'));
        self::assertSame([true, false, true], $manifest->writes('a.tags'));
        self::assertSame([], $manifest->digests('a.none'));
        self::assertSame([], $manifest->writes('a.none'));
        self::assertSame(['u1', 'u2', 'd1', 'u3'], $manifest->flat());
        self::assertSame(2, $manifest->writingBatches());
        self::assertSame(4, $manifest->count());
        self::assertEquals($manifest, BatchManifest::fromStored($manifest->toStored()));
        self::assertSame(['p' => 'a.tags', 'd' => 'u1', 'w' => true], $manifest->toStored()[0]);
    }

    #[Test]
    public function aZeroOneRowHasNoLayout(): void
    {
        self::assertNull(BatchManifest::fromStored(null));
        self::assertNull(BatchManifest::fromStored([]));
        self::assertNull(BatchManifest::fromStored(['d1', 'd2']), 'a 0.1 list of digests');
        self::assertNull(BatchManifest::fromStored([['p' => 'a', 'd' => 'x']]));
    }
}
