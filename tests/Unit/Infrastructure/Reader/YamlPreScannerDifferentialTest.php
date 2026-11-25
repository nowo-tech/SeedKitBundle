<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Reader;

use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Infrastructure\Reader\YamlPreScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

use function count;
use function is_array;
use function json_encode;
use function mt_rand;
use function mt_srand;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * Differential check of the pre-scanner against Symfony Yaml (audit ZBK-v): random YAML made of indicator pieces,
 * deterministic seed, bounded size. Whatever the pre-scanner accepts must not reach Symfony as an alias (`*zz`), a
 * custom tag (`!t`, `!x`) or a core tag (`!!binary`, `!!str`, `!!float` detected by the values only they produce).
 */
final class YamlPreScannerDifferentialTest extends TestCase
{
    private const PIECES = [
        'a', 'b', 'k', ':', ': ', '::', '? ', '?', '- ', '-', '"', "'", '\\"', "''", '|', '>', ' ', "\t", "\n", "\n  ",
        "\n    ", '{', '}', '[', ']', ',', ', ', '#', ' #', '!t ', '*zz', '&zz ', '!!binary QUJD', '!!str 8', '<<: ', '%',
        '---', ':|', ':"', ':>', "?'", "\t#", '?x', ':x', '!x', '- - ', "\n      ", '!!float 7',
    ];

    /** The audit's desync probes (ZBK-v, ZBK-v2) and the fuzzer's finds: always refused. */
    private const PROBES = [
        "items:\n  - email: a\n    :|p: !!binary aGVsbG8=\n",
        "items:\n  - email: a\n    n: x\t# y: '\n    m: !!binary aGVsbG8=\n    # '\n",
        "\t# x: '\nm: !!binary aGVsbG8=\n# '\n",
        "    c: x\n      \"y\n    d: !!binary aGVsbG8=\n    e: '\"'\n",
        "[- -   ',!x ]\n",
        // Found by the fuzzer while fixing ZBK-v2.
        "- b:x- %<<: >\n  :\":>,!t :: !!float 7",
        "b: - - \t':>[\n  %%- - :- - ?!!float 7\n, <<: *zz#? ' #!t k",
        "[#:>{'!!str 8- [,  \n\t#: a!t |\n!!float 7:>]",
        "- }\n    [\"- :[: -#?x!!binary QUJD]&zz ,- \\\"- :>\n\n- &zz !xb]::",
        "- - >-\t# :x\n  - !!float 7!t \n    '>,!t \t}? \n      \"",
        "- - |\n  - - ,|?': *zz!x#]? !!str 8%\n",
        "-\n  \n    '::\\\"k #---? ?'  #? !!float 7'''?'!!str 8---,%]\n    '%\n-\t#!t %!!float 7\n    !!float 7?x!!str 8",
        "}?x? : - - \n  - \"\n  !t \n  \n*zz!x<<: ",
        "- - - \n  #:| #\":::\n    k\n    '{:\\\"*zz  #\n\n      :>!t \n  - - !!binary QUJD: ? a- #",
        '[[]*zz!!float 7- - >]',
        "[\n  [- - ',]*zz<<: , \":x>>&zz [':a:\"\n  - - : >\"---]",
        '[[]!t ::]',
        "- - k\n  - *zz",
        "--- > #::\n  !!float 7?a{",
        "a?&zz :><<: \n      '':\":x? ---,::!!str 8\n?- - : !!binary QUJD",
        "- - }\n  - - *zz\\\"]!t  \n      &zz {%]!xb",
        "- - ,k\n  - - |\n  -  #:>\n    *zz&zz  #|::--- #:x['a|:x:}",
        "k?:\n  '!!str 8\n  \n    :xa: ?? \":!t #\n      ?:\"#>?' :\"\n*zzb",
    ];

    private const CASES = 20000;

    private const SEED = 20261125;

    #[Test]
    public function acceptedTextsNeverCarryAnAliasOrATagIntoSymfony(): void
    {
        mt_srand(self::SEED);
        $escapes = [];
        for ($case = 0; $case < self::CASES; ++$case) {
            $text  = '';
            $count = mt_rand(1, 14);
            for ($piece = 0; $piece < $count; ++$piece) {
                $text .= self::PIECES[mt_rand(0, count(self::PIECES) - 1)];
            }
            $escape = $this->escape($text);
            if ($escape !== null) {
                $escapes[] = $escape . ': ' . json_encode($text, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $escapes);
    }

    #[Test]
    public function theAuditProbesAreRefused(): void
    {
        foreach (self::PROBES as $probe) {
            try {
                YamlPreScanner::assertSafe($probe);
                self::fail('Accepted: ' . json_encode($probe, JSON_THROW_ON_ERROR));
            } catch (DocumentReadException $e) {
                self::assertContains($e->reason, ['yaml_tag', 'yaml_alias', 'yaml_ambiguous']);
            }
        }
    }

    /**
     * Why an accepted text is unsafe, or null when it is refused or harmless.
     */
    private function escape(string $text): ?string
    {
        try {
            YamlPreScanner::assertSafe($text);
        } catch (DocumentReadException) {
            return null;
        }
        try {
            $tree = Yaml::parse($text, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            return str_contains($e->getMessage(), 'Reference') ? 'alias' : null;
        }

        return $this->tagged($tree) ? 'tag' : null;
    }

    /**
     * Whether a parsed value holds a custom tag or the decoded `!!binary` marker.
     */
    private function tagged(mixed $value): bool
    {
        // Markers only a core tag can produce: `!!binary QUJD`, `!!str 8` (plain 8 is an int), `!!float 7`.
        if ($value instanceof TaggedValue || $value === 'ABC' || $value === '8' || $value === 7.0) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                if ($key === 'ABC' || $this->tagged($entry)) {
                    return true;
                }
            }
        }

        return false;
    }
}
