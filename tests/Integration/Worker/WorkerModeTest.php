<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Worker;

use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Test;

use function fclose;
use function fopen;
use function fwrite;
use function gc_collect_cycles;
use function memory_get_usage;
use function rewind;
use function sprintf;
use function stream_get_contents;

/**
 * REQ-RUNTIME-002 (FrankenPHP worker, kernel reset off): one booted kernel serves 1 000 mixed exports, diffs and
 * applies of two providers by two actors with different visibility; the kernel is never reset. No answer leaks what
 * the other actor may read, every answer matches the data, and memory stays flat after the warm-up.
 */
final class WorkerModeTest extends KernelTestCase
{
    private const ITERATIONS = 1000;

    private const WARM_UP = 200;

    #[Test]
    public function oneKernelServesManyMixedRequestsWithoutLeaks(): void
    {
        $this->boot([], ['quiet' => true, 'no_debug' => true]);
        $this->insertTag('news', 'News 0');
        $this->insertArticle('A-1', 'Bob 0', 'bob', 1, 'news');
        $this->insertArticle('B-1', 'Alice private', 'alice', 5, 'news');
        $operations = $this->service(SeedOperations::class, SeedOperations::class);
        $reader     = $this->service(DocumentReader::class, DocumentReader::class);
        $limits     = $this->service(ReaderLimits::class, ReaderLimits::class);
        $scope      = SeedScope::platform();
        $actors     = [new ActorRef('alice'), new ActorRef('bob')];
        $baseline   = 0;

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            if ($i === self::WARM_UP) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
            $actor = $actors[$i % 2];
            $bob   = $actor->value === 'bob';
            switch ($i % 5) {
                case 0:
                case 1:
                    $articles = $this->export($operations, 'host.articles', $actor);
                    self::assertStringContainsString('A-1', $articles);
                    if ($bob) {
                        self::assertStringNotContainsString('B-1', $articles, 'iteration ' . $i . ': bob reads alice\'s article');
                        self::assertStringNotContainsString('Alice private', $articles);
                    } else {
                        self::assertStringContainsString('Alice private', $articles);
                    }
                    break;
                case 2:
                    $tags = $this->export($operations, 'host.tags', $actor);
                    self::assertStringContainsString('slug: news', $tags);
                    break;
                case 3:
                    $plan = $operations->diff($this->read($reader, $limits, $this->articles('Bob draft')), $scope, $actor, Channel::Console, new PruneOptions());
                    self::assertSame(1, $plan->changes(), 'iteration ' . $i);
                    break;
                default:
                    $set    = $bob ? $this->read($reader, $limits, $this->articles(sprintf('Bob %d', $i))) : $this->read($reader, $limits, sprintf("provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - {slug: news, label: 'News %d'}\n", $i));
                    $result = $operations->apply($set, $scope, $actor, Channel::Console, new PruneOptions());
                    self::assertSame(1, $result->plan->changes(), 'iteration ' . $i);
                    self::assertNotNull($result->runId);
            }
        }
        gc_collect_cycles();
        $growth = memory_get_usage() - $baseline;

        self::assertLessThan(256 * 1024, $growth, sprintf('memory grew by %d bytes over %d requests', $growth, self::ITERATIONS - self::WARM_UP));
        self::assertSame('Alice private', $this->connection()->fetchOne("SELECT title FROM host_article WHERE code = 'B-1'"));
        self::assertSame('alice', $this->connection()->fetchOne("SELECT owner FROM host_article WHERE code = 'B-1'"));
        self::assertSame(self::ITERATIONS / 5, TestDatabase::int($this->connection()->fetchOne("SELECT COUNT(*) FROM nowo_seed_run WHERE status = 'applied'")));
    }

    private function articles(string $title): string
    {
        return sprintf("provider: host.articles\nversion: 1\nscope: platform\nitems:\n  - {code: A-1, title: '%s', owner: bob, level: 1, tag: news}\n", $title);
    }

    private function read(DocumentReader $reader, ReaderLimits $limits, string $text): DocumentSet
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);
        try {
            return $reader->read($stream, DocumentFormat::Yaml, $limits);
        } finally {
            fclose($stream);
        }
    }

    private function export(SeedOperations $operations, string $provider, ActorRef $actor): string
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        try {
            $operations->export(new ProviderName($provider), SeedScope::platform(), ExportMode::Current, DocumentFormat::Yaml, $actor, Channel::Console, $stream);
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
