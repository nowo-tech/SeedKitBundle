<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Nowo\SeedKitBundle\Application\PlanHasher;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Plan hash and batch digests (contract 9): fixed framing, byte-exact.
 */
final class PlanHasherTest extends TestCase
{
    #[Test]
    public function linesAreFramed(): void
    {
        $key = new NaturalKey(['a', 1]);
        self::assertSame("C\x1F[\"a\",1]\x1F-\x1Fwant\x1E", PlanHasher::line(RowChange::create($key, 0)->withDesiredFingerprint('want')));
        self::assertSame("D\x1F[\"a\",1]\x1Fcur\x1F-\x1E", PlanHasher::line(RowChange::delete($key, 'cur')));
        self::assertSame("N\x1F[\"a\",1]\x1Fcur\x1F-\x1E", PlanHasher::line(RowChange::unchanged($key, 0, 'cur')));
    }

    #[Test]
    public function thePlanHashIsTheSha256OfTheFramedStream(): void
    {
        $provider = new ProviderName('demo.tags');
        $change   = RowChange::update(new NaturalKey(['a']), 0, [], 'cur')->withDesiredFingerprint('want');
        $hasher   = new PlanHasher(SeedScope::parse('organization:o1'), true);
        $hasher->document($provider, 1, 'schema');
        $hasher->change($change);
        $expected = 'sk1:' . hash('sha256', "nowo-seed-kit/plan/1\x1Eorganization:o1\x1E1\x1Edemo.tags\x1F1\x1Fschema\x1E" . PlanHasher::line($change));
        self::assertSame($expected, $hasher->finish()->value);

        $other = new PlanHasher(SeedScope::parse('organization:o1'), false);
        $other->document($provider, 1, 'schema');
        $other->change($change);
        self::assertNotSame($expected, $other->finish()->value);
    }

    #[Test]
    public function batchDigests(): void
    {
        $provider = new ProviderName('demo.tags');
        $change   = RowChange::create(new NaturalKey(['a']), 0);
        self::assertSame(
            hash('sha256', "nowo-seed-kit/batch/1\x1Edemo.tags\x1E2\x1E" . PlanHasher::line($change)),
            PlanHasher::batchDigest($provider, 2, [$change]),
        );
        self::assertNotSame(PlanHasher::batchDigest($provider, 1, [$change]), PlanHasher::batchDigest($provider, 2, [$change]));
        self::assertSame(hash('sha256', "nowo-seed-kit/batch/1\x1Edemo.tags\x1E1\x1E"), PlanHasher::batchDigest($provider, 1, []));
    }
}
