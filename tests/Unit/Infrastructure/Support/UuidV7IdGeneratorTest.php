<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Support;

use Nowo\SeedKitBundle\Infrastructure\Support\UuidV7IdGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function sort;

use const SORT_STRING;

/**
 * Run ids: canonical lower-case UUID v7, time-ordered.
 */
final class UuidV7IdGeneratorTest extends TestCase
{
    #[Test]
    public function generatesCanonicalVersion7Ids(): void
    {
        $id = (new UuidV7IdGenerator())->runId();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id->value);
    }

    #[Test]
    public function generatesUniqueMonotonicIds(): void
    {
        $generator = new UuidV7IdGenerator();
        $ids       = [];
        for ($i = 0; $i < 500; ++$i) {
            $ids[] = $generator->runId()->value;
        }
        $sorted = $ids;
        sort($sorted, SORT_STRING);

        self::assertSame($ids, $sorted);
        self::assertCount(500, array_unique($ids));
    }
}
