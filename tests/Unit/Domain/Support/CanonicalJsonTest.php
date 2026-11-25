<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Support;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function ini_get;
use function ini_set;

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
        // ECMAScript Number::toString (RFC 8785), audit ZBK I1.
        yield 'float 1e-6 fixed' => [1e-6, '0.000001'];
        yield 'float -1.5e-6 fixed' => [-1.5e-6, '-0.0000015'];
        yield 'float 1.5e-7 exponent' => [1.5e-7, '1.5e-7'];
        yield 'float 1e20 fixed' => [1e20, '100000000000000000000'];
        yield 'float 1e21 exponent' => [1e21, '1e+21'];
        yield 'float 123.456' => [123.456, '123.456'];
        yield 'float 12345e10' => [12345e10, '123450000000000'];
        yield 'float smallest subnormal' => [5e-324, '5e-324'];
        yield 'float max' => [1.7976931348623157e308, '1.7976931348623157e+308'];
        yield 'float -1.25e30' => [-1.25e30, '-1.25e+30'];
        yield 'float 2^-1017 (nearest digits fail, next up is shortest)' => [2.0 ** -1017, '7.120236347223045e-307'];
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

    #[Test]
    public function floatsDoNotDependOnSerializePrecision(): void
    {
        $serialize = ini_get('serialize_precision');
        $precision = ini_get('precision');
        ini_set('serialize_precision', '17'); // @phpstan-ignore frankenphp.worker.noPersistentIniSet (test only, restored)
        ini_set('precision', '5'); // @phpstan-ignore frankenphp.worker.noPersistentIniSet (test only, restored)
        try {
            self::assertSame('[0.1,0.3,1e-7,123.456789]', CanonicalJson::encode([0.1, 0.3, 1e-7, 123.456789]));
        } finally {
            ini_set('serialize_precision', $serialize === false ? '-1' : $serialize); // @phpstan-ignore frankenphp.worker.noPersistentIniSet (test only, restored)
            ini_set('precision', $precision === false ? '14' : $precision); // @phpstan-ignore frankenphp.worker.noPersistentIniSet (test only, restored)
        }
    }
}
