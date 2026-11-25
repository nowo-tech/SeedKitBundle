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
 * deterministic seed, bounded size. Whatever the pre-scanner accepts must not reach Symfony as an alias (`*zz`), a tag
 * (`!t`, a `!!binary` that decodes to the marker) or an anchor (`&zz` then `*zz`).
 */
final class YamlPreScannerDifferentialTest extends TestCase
{
    private const PIECES = [
        'a', 'b', 'k', ':', ': ', '::', '? ', '?', '- ', '-', '"', "'", '\\"', "''", '|', '>', ' ', "\t", "\n", "\n  ",
        "\n    ", '{', '}', '[', ']', ',', ', ', '#', ' #', '!t ', '*zz', '&zz ', '!!binary QUJD', '!!str ', '<<: ', '%',
        '---', ':|', ':"', ':>', "?'",
    ];

    private const CASES = 20000;

    #[Test]
    public function acceptedTextsNeverCarryAnAliasOrATagIntoSymfony(): void
    {
        mt_srand(20261125);
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
        if ($value instanceof TaggedValue || $value === 'ABC') {
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
