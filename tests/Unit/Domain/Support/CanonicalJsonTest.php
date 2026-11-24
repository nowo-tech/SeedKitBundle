<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Support;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use const INF;
use const NAN;

/**
 * RFC 8785 (JCS) canonical JSON: the bytes behind natural keys, fingerprints and the plan hash.
 */
final class CanonicalJsonTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function vectors(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [42, '42'];
        yield 'negative int' => [-7, '-7'];
        yield 'float integral' => [1.0, '1'];
        yield 'float fraction' => [1.5, '1.5'];
        yield 'float -0.0' => [-0.0, '0'];
        yield 'float 0.0' => [0.0, '0'];
        yield 'float 1e25' => [1e25, '1e+25'];
        yield 'float 1e-7' => [1e-7, '1e-7'];
        yield 'float 0.1' => [0.1, '0.1'];
        yield 'float -2.5' => [-2.5, '-2.5'];
        yield 'float 100.0' => [100.0, '100'];
        yield 'string' => ['a"b\\c', '"a\\"b\\\\c"'];
        yield 'slash unescaped' => ['a/b', '"a/b"'];
        yield 'unicode unescaped' => ["\u{20AC}", "\"\u{20AC}\""];
        yield 'line sep unescaped' => ["\u{2028}", "\"\u{2028}\""];
        yield 'control escaped' => ["\x01\n", '"\u0001\n"'];
        yield 'empty list' => [[], '[]'];
        yield 'list order kept' => [[3, 1, 2], '[3,1,2]'];
        yield 'map sorted' => [['b' => 1, 'a' => 2], '{"a":2,"b":1}'];
        yield 'nested' => [['z' => ['y' => 1, 'x' => [true, null]], 'a' => 'v'], '{"a":"v","z":{"x":[true,null],"y":1}}'];
        yield 'numeric keys' => [[1 => 'a', 0 => 'b'], '{"0":"b","1":"a"}'];
        yield 'NFC and NFD differ' => [["Jos\u{00E9}", "Jose\u{0301}"], "[\"Jos\u{00E9}\",\"Jose\u{0301}\"]"];
    }

    #[Test]
    #[DataProvider('vectors')]
    public function encodesCanonically(mixed $value, string $expected): void
    {
        self::assertSame($expected, CanonicalJson::encode($value));
    }

    #[Test]
    public function sortsKeysByUtf16CodeUnits(): void
    {
        // RFC 8785 3.2.3 example: U+20AC (0x20AC) < U+1F600 surrogate pair (0xD83D …) < U+FB33 (0xFB33)
        // byte order (UTF-8) would put U+FB33 (EF AC B3) before U+1F600 (F0 9F 98 80)
        $value = [
            "\u{FB33}"  => 'hebrew',
            "\u{1F600}" => 'emoji',
            "\u{20AC}"  => 'euro',
            "\r"        => 'cr',
            '1'         => 'one',
        ];
        self::assertSame(
            "{\"\\r\":\"cr\",\"1\":\"one\",\"\u{20AC}\":\"euro\",\"\u{1F600}\":\"emoji\",\"\u{FB33}\":\"hebrew\"}",
            CanonicalJson::encode($value),
        );
    }

    #[Test]
    public function refusesNan(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Non-finite numbers are not allowed.');
        CanonicalJson::encode(['x' => NAN]);
    }

    #[Test]
    public function refusesInfinity(): void
    {
        $this->expectException(InvalidValueException::class);
        CanonicalJson::encode([-INF]);
    }

    #[Test]
    public function refusesObjects(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('A value is not JSON-compatible.');
        CanonicalJson::encode(['a' => new stdClass()]);
    }
}
