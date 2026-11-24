<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Nowo\SeedKitBundle\Application\ErrorBag;
use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\SecretValueRefusedException;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Row errors capped at `validation.max_errors`; a secret rule turns the report into a secret refusal.
 */
final class ErrorBagTest extends TestCase
{
    private static function error(int $index, string $rule = 'type'): RowError
    {
        return new RowError($index, null, '', $rule, 'seed_kit.row.' . $rule);
    }

    #[Test]
    public function anEmptyBagThrowsNothing(): void
    {
        $bag = new ErrorBag(2);
        self::assertTrue($bag->isEmpty());
        $bag->add([]);
        $bag->throwIfAny();
        self::assertTrue($bag->isEmpty());
    }

    #[Test]
    public function capsAndTruncates(): void
    {
        $bag = new ErrorBag(2);
        $bag->add([self::error(0), self::error(1)], 'demo.a');
        $bag->add([self::error(2)]);
        self::assertFalse($bag->isEmpty());
        try {
            $bag->throwIfAny();
            self::fail('expected');
        } catch (DocumentInvalidException $e) {
            self::assertTrue($e->truncated);
            self::assertSame([0, 1], array_map(static fn (RowError $r): int => $r->index, $e->errors));
            self::assertSame('demo.a', $e->errors[0]->provider);
        }
    }

    #[Test]
    public function withoutProviderTheErrorIsKeptAsIs(): void
    {
        $bag   = new ErrorBag(5);
        $error = self::error(0);
        $bag->add([$error]);
        try {
            $bag->throwIfAny();
            self::fail('expected');
        } catch (DocumentInvalidException $e) {
            self::assertSame($error, $e->errors[0]);
            self::assertFalse($e->truncated);
        }
    }

    #[Test]
    public function aSecretErrorEvenOverTheCapRefusesTheSecret(): void
    {
        $bag = new ErrorBag(1);
        $bag->add([self::error(0), self::error(1, 'secret_refused')]);
        try {
            $bag->throwIfAny();
            self::fail('expected');
        } catch (SecretValueRefusedException $e) {
            self::assertTrue($e->truncated);
            self::assertSame(['type'], array_map(static fn (RowError $r): string => $r->rule, $e->errors));
        }
    }
}
