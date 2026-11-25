<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ExactText;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SEC-006: identifiers are exact text — no trimming, no folding, no invisible characters.
 */
final class ExactTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function texts(): iterable
    {
        yield 'plain' => ['Bob', true];
        yield 'lower' => ['bob', true];
        yield 'inner space' => ['bob smith', true];
        yield 'trailing space' => ['bob ', false];
        yield 'leading space' => [' bob', false];
        yield 'trailing newline' => ["bob\n", false];
        yield 'tab inside' => ["bo\tb", false];
        yield 'NBSP edge' => ["bob\u{00A0}", false];
        yield 'NBSP leading' => ["\u{00A0}bob", false];
        yield 'ideographic space' => ["bob\u{3000}", false];
        yield 'ZWSP' => ["bo\u{200B}b", false];
        yield 'BOM' => ["\u{FEFF}bob", false];
        yield 'bidi override' => ["bob\u{202E}", false];
        yield 'line separator' => ["bo\u{2028}b", false];
        yield 'paragraph separator' => ["bo\u{2029}b", false];
        yield 'NUL' => ["bo\0b", false];
        yield 'invalid UTF-8' => ["bo\xC3b", false];
        yield 'empty' => ['', false];
        yield 'NFC é' => ["Jos\u{00E9}", true];
        yield 'NFD é (combining)' => ["Jose\u{0301}", true];
        yield 'emoji' => ['🙂', true];
    }

    #[Test]
    #[DataProvider('texts')]
    public function validatesExactText(string $value, bool $valid): void
    {
        self::assertSame($valid, ExactText::isValid($value, 128));
    }

    #[Test]
    public function enforcesTheByteLimit(): void
    {
        self::assertTrue(ExactText::isValid(str_repeat('a', 10), 10));
        self::assertFalse(ExactText::isValid(str_repeat('a', 11), 10));
        // 2 bytes per é: 5 characters = 10 bytes
        self::assertTrue(ExactText::isValid(str_repeat("\u{00E9}", 5), 10));
        self::assertFalse(ExactText::isValid(str_repeat("\u{00E9}", 6), 10));
    }

    #[Test]
    public function assertReturnsTheValueOrThrowsTheRule(): void
    {
        self::assertSame('bob', ExactText::assert('bob', 10, 'rule'));

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('my rule');
        ExactText::assert('bob ', 10, 'my rule');
    }

    #[Test]
    public function nfcAndNfdAreDifferentIdentifiers(): void
    {
        $nfc = new ScopeId("Jos\u{00E9}");
        $nfd = new ScopeId("Jose\u{0301}");
        self::assertFalse($nfc->equals($nfd));
        self::assertFalse((new NaturalKey([$nfc->value]))->equals(new NaturalKey([$nfd->value])));
    }

    #[Test]
    public function caseAndTrailingSpaceAreNeverFolded(): void
    {
        self::assertFalse((new NaturalKey(['Bob']))->equals(new NaturalKey(['bob'])));
        self::assertFalse((new ScopeId('Bob'))->equals(new ScopeId('bob')));
        self::assertSame('Bob', (string) new ActorRef('Bob'));
    }
}
