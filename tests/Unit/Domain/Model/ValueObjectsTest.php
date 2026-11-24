<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contract 5.1: identifiers validated in the constructor.
 */
final class ValueObjectsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providerNames(): iterable
    {
        yield 'simple' => ['tags', true];
        yield 'dotted' => ['crm.contacts', true];
        yield 'four segments' => ['a.b.c.d', true];
        yield 'five segments' => ['a.b.c.d.e', false];
        yield 'upper' => ['Crm', false];
        yield 'digit first' => ['1crm', false];
        yield 'trailing dot' => ['crm.', false];
        yield 'trailing LF' => ["crm\n", false];
        yield 'segment 32' => [str_repeat('a', 32), true];
        yield 'segment 33' => [str_repeat('a', 33), false];
        yield 'over 64 bytes' => [str_repeat('a', 32) . '.' . str_repeat('b', 31) . '.c', false];
        yield 'exactly 64' => [str_repeat('a', 32) . '.' . str_repeat('b', 31), true];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('providerNames')]
    public function validatesProviderNames(string $value, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidValueException::class);
            $this->expectExceptionMessage('Invalid provider name.');
        }
        $name = new ProviderName($value);
        self::assertSame($value, (string) $name);
        self::assertTrue($name->equals(new ProviderName($value)));
    }

    #[Test]
    public function providerNameEqualityIsExact(): void
    {
        self::assertFalse((new ProviderName('a.b'))->equals(new ProviderName('a.c')));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function scopeKinds(): iterable
    {
        yield 'platform' => ['platform', true];
        yield 'with_digit' => ['org2', true];
        yield 'dotted' => ['a.b', false];
        yield 'upper' => ['Org', false];
        yield 'LF' => ["org\n", false];
        yield 'too long' => [str_repeat('a', 33), false];
    }

    #[Test]
    #[DataProvider('scopeKinds')]
    public function validatesScopeKinds(string $value, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidValueException::class);
            $this->expectExceptionMessage('Invalid scope kind.');
        }
        $kind = new ScopeKind($value);
        self::assertSame($value, (string) $kind);
        self::assertTrue($kind->equals(new ScopeKind($value)));
        self::assertFalse($kind->equals(new ScopeKind('zzz')));
    }

    #[Test]
    public function scopeIdsAreExactAndBounded(): void
    {
        $id = new ScopeId('org-1');
        self::assertSame('org-1', (string) $id);
        self::assertTrue($id->equals(new ScopeId('org-1')));
        self::assertFalse($id->equals(new ScopeId('Org-1')));
        new ScopeId(str_repeat('x', 128));

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Invalid scope id.');
        new ScopeId(str_repeat('x', 129));
    }

    #[Test]
    public function actorRefs(): void
    {
        $system = ActorRef::system();
        self::assertSame('system', $system->value);
        self::assertTrue($system->isSystem());
        self::assertFalse((new ActorRef('System'))->isSystem());
        self::assertSame('user-1', (string) new ActorRef('user-1'));
        new ActorRef(str_repeat('a', 190));

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Invalid actor reference.');
        new ActorRef(str_repeat('a', 191));
    }

    #[Test]
    public function actorRefsRefuseInvisibleCharacters(): void
    {
        $this->expectException(InvalidValueException::class);
        new ActorRef("bob\u{200B}");
    }

    #[Test]
    public function documentHashes(): void
    {
        $hash = DocumentHash::ofBytes('abc');
        self::assertSame('sha256:ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', (string) $hash);
        self::assertSame($hash->value, (new DocumentHash($hash->value))->value);

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Invalid document hash.');
        new DocumentHash('sha256:' . str_repeat('A', 64));
    }

    #[Test]
    public function documentHashRefusesTrailingNewline(): void
    {
        $this->expectException(InvalidValueException::class);
        new DocumentHash('sha256:' . str_repeat('a', 64) . "\n");
    }

    #[Test]
    public function planHashes(): void
    {
        $value = 'sk1:' . str_repeat('0', 64);
        $hash  = new PlanHash($value);
        self::assertSame($value, (string) $hash);
        self::assertTrue($hash->equals(new PlanHash($value)));
        self::assertFalse($hash->equals(new PlanHash('sk1:' . str_repeat('1', 64))));

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Invalid plan hash.');
        new PlanHash('sk2:' . str_repeat('0', 64));
    }

    #[Test]
    public function runIds(): void
    {
        $value = '01890a5d-ac96-774b-bcce-b302099a8057';
        self::assertSame($value, (string) new RunId($value));

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Invalid run id.');
        new RunId(strtoupper($value));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function secretReferences(): iterable
    {
        yield 'simple' => ['vault:abc', true];
        yield 'path' => ['vault:org/1#key:v-2.x_y', true];
        yield 'no target' => ['vault:', false];
        yield 'bad first' => ['vault:-abc', false];
        yield 'plain' => ['s3cr3t', false];
        yield 'space' => ['vault:a b', false];
        yield 'LF' => ["vault:abc\n", false];
        yield 'max 200' => ['vault:' . str_repeat('a', 200), true];
        yield 'over 200' => ['vault:' . str_repeat('a', 201), false];
        yield 'upper scheme' => ['VAULT:abc', false];
    }

    #[Test]
    #[DataProvider('secretReferences')]
    public function validatesSecretReferences(string $value, bool $valid): void
    {
        self::assertSame($valid, SecretReference::isReference($value));
        if (!$valid) {
            $this->expectException(InvalidValueException::class);
            $this->expectExceptionMessage('Invalid secret reference.');
        }
        $reference = new SecretReference($value);
        self::assertSame($value, (string) $reference);
        self::assertSame(substr($value, 6), $reference->target());
    }

    #[Test]
    public function seedScopes(): void
    {
        $platform = SeedScope::platform();
        self::assertSame('platform', $platform->key());
        self::assertSame('', $platform->storedId());
        self::assertNull($platform->id);

        $org = SeedScope::parse('organization:a:b');
        self::assertSame('organization', $org->kind->value);
        self::assertSame('a:b', $org->storedId());
        self::assertSame('organization:a:b', (string) $org);
        self::assertTrue($org->equals(new SeedScope(new ScopeKind('organization'), new ScopeId('a:b'))));
        self::assertFalse($org->equals($platform));
        self::assertTrue(SeedScope::parse('platform')->equals($platform));
    }

    #[Test]
    public function seedScopeParseRefusesAnEmptyId(): void
    {
        $this->expectException(InvalidValueException::class);
        SeedScope::parse('organization:');
    }

    #[Test]
    public function naturalKeys(): void
    {
        $key = new NaturalKey(['b', 2]);
        self::assertSame('["b",2]', $key->canonical);
        self::assertSame('["b",2]', (string) $key);
        self::assertSame(['b', 2], $key->components);
        self::assertTrue($key->equals(new NaturalKey(['b', 2])));
        self::assertFalse($key->equals(new NaturalKey(['b', '2'])));
        self::assertSame("[\"Jos\u{00E9}\"]", (new NaturalKey(["Jos\u{00E9}"]))->canonical);
        new NaturalKey([1, 2, 3, 4]);
        new NaturalKey([str_repeat('a', 255)]);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => [[]];
        yield 'five' => [[1, 2, 3, 4, 5]];
        yield 'not list' => [['a' => 'x']];
        yield 'float' => [[1.5]];
        yield 'null' => [[null]];
        yield 'bool' => [[true]];
        yield 'space' => [['bob ']];
        yield 'too long' => [[str_repeat('a', 256)]];
        yield 'ZWSP' => [["b\u{200B}"]];
    }

    /**
     * @param array<mixed> $components
     */
    #[Test]
    #[DataProvider('badKeys')]
    public function refusesBadNaturalKeys(array $components): void
    {
        $this->expectException(InvalidValueException::class);
        // @phpstan-ignore argument.type
        new NaturalKey($components);
    }
}
