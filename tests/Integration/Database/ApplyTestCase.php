<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use Nowo\SeedKitBundle\Application\ApplyResult;
use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Tests\Integration\Kernel\KernelTestCase;

use function fclose;
use function fopen;
use function fwrite;
use function implode;
use function is_string;
use function rewind;
use function sprintf;
use function stream_get_contents;

/**
 * Full applies through {@see SeedOperations} of the booted kernel on the real database, with the DB-backed fixture
 * providers.
 */
abstract class ApplyTestCase extends KernelTestCase
{
    protected function operations(): SeedOperations
    {
        return $this->service(SeedOperations::class, SeedOperations::class);
    }

    /**
     * Reads a document text.
     */
    protected function read(string $text, ?DocumentFormat $format = DocumentFormat::Yaml): DocumentSet
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);
        try {
            return $this->service(DocumentReader::class, DocumentReader::class)->read($stream, $format, $this->service(ReaderLimits::class, ReaderLimits::class));
        } finally {
            fclose($stream);
        }
    }

    /**
     * A host.tags document with `slug => label` items.
     *
     * @param array<string, string> $labels
     */
    protected static function tags(array $labels, string $scope = 'platform'): string
    {
        $items = [];
        foreach ($labels as $slug => $label) {
            $items[] = sprintf("  - {slug: '%s', label: '%s'}", $slug, $label);
        }

        return sprintf("provider: host.tags\nversion: 1\nscope: %s\nitems:\n%s\n", $scope, $items === [] ? '  []' : implode("\n", $items));
    }

    protected function diff(DocumentSet $set, string $actor = 'ops', ?SeedScope $scope = null, bool $prune = false): SeedPlan
    {
        return $this->operations()->diff($set, $scope ?? SeedScope::platform(), new ActorRef($actor), Channel::Console, new PruneOptions($prune));
    }

    protected function apply(DocumentSet $set, string $actor = 'ops', ?PlanHash $expected = null, ?SeedScope $scope = null, bool $prune = false): ApplyResult
    {
        return $this->operations()->apply($set, $scope ?? SeedScope::platform(), new ActorRef($actor), Channel::Console, new PruneOptions($prune, true, true), $expected);
    }

    protected function export(string $provider, DocumentFormat $format, string $actor = 'ops', ?SeedScope $scope = null): string
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        try {
            $this->operations()->export(new ProviderName($provider), $scope ?? SeedScope::platform(), ExportMode::Current, $format, new ActorRef($actor), Channel::Console, $stream);
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Labels of the host tags by slug.
     *
     * @return array<string, string>
     */
    protected function labels(string $scope = 'platform'): array
    {
        $labels = [];
        foreach ($this->connection()->fetchAllKeyValue('SELECT slug, label FROM host_tag WHERE scope_key = ? ORDER BY slug', [$scope]) as $slug => $label) {
            $labels[(string) $slug] = is_string($label) ? $label : '';
        }

        return $labels;
    }
}
