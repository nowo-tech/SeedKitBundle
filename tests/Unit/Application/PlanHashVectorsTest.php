<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Nowo\SeedKitBundle\Application\PlanHasher;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The golden vectors published in docs/PLAN-HASH.md (contract section 9): a host can recompute them.
 */
final class PlanHashVectorsTest extends TestCase
{
    #[Test]
    public function anEmptyPlatformRunHasThePublishedHash(): void
    {
        self::assertSame('sk1:58ed10a31b00fc9e9306906714b1b600c7e6f5bc04ab7e2315a783d6687015f0', (new PlanHasher(SeedScope::platform(), false))->finish()->value);
    }

    #[Test]
    public function theDocumentedStreamIsHashedAsPublished(): void
    {
        $expected = 'sk1:' . hash('sha256', "nowo-seed-kit/plan/1\x1Eplatform\x1E0\x1E");
        self::assertSame($expected, (new PlanHasher(SeedScope::platform(), false))->finish()->value);
        self::assertSame(hash('sha256', "nowo-seed-kit/batch/1\x1Edemo.tags\x1E1\x1E"), PlanHasher::batchDigest(new ProviderName('demo.tags'), 1, []));
    }

    #[Test]
    public function vectorOneMatchesThePublishedValues(): void
    {
        $key     = new NaturalKey(['books']);
        $desired = hash('sha256', CanonicalJson::encode(['slug' => 'books', 'label' => 'Books']));
        self::assertSame('f38a07563a7be3a84d518d68051a9e81c94a833950dbc5ad05903b7ad79fa8d8', $desired);
        $change = RowChange::create($key, 0)->withDesiredFingerprint($desired);
        $hasher = new PlanHasher(SeedScope::platform(), false);
        $hasher->document(new ProviderName('catalog.categories'), 1, '9f188dd792c75c499640d74052a505e3cc41fd4f3d2e6929743507bf86e3cc69');
        $hasher->change($change);
        self::assertSame('sk1:9ca8e04ae2c47321fc5d536d28b52814c72523cb5e3243595250ccc774d59c5e', $hasher->finish()->value);
        self::assertSame('b3d973f6b1744c3c8e5f22fc7e5a0d0bdfbf61dade6d25d6d6ff9c41d8f4cb67', PlanHasher::batchDigest(new ProviderName('catalog.categories'), 1, [$change]));
    }
}
