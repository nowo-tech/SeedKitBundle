<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Reader;

use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The reader caps (contract 13.3 defaults).
 */
final class ReaderLimitsTest extends TestCase
{
    #[Test]
    public function hasTheContractDefaults(): void
    {
        $limits = new ReaderLimits();

        self::assertSame(8388608, $limits->maxBytes);
        self::assertSame(4194304, $limits->maxTreeBytes);
        self::assertSame(10000, $limits->maxItems);
        self::assertSame(20, $limits->maxDocuments);
        self::assertSame(16, $limits->maxDepth);
        self::assertSame(1048576, $limits->maxLineBytes);
    }

    #[Test]
    public function keepsTheGivenCaps(): void
    {
        $limits = new ReaderLimits(1, 2, 3, 4, 5, 6);

        self::assertSame([1, 2, 3, 4, 5, 6], [$limits->maxBytes, $limits->maxTreeBytes, $limits->maxItems, $limits->maxDocuments, $limits->maxDepth, $limits->maxLineBytes]);
    }
}
