<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeNotFoundException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingAuditSink;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingLogger;
use Nowo\SeedKitBundle\Tests\Support\QueryLog;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Test;

use function array_column;
use function range;
use function sprintf;

/**
 * Applies through the whole stack on the real database (contract sections 10 and 19): round trip (SC-001), an
 * idempotent second apply that writes nothing to the host tables (SC-002), a constant query count per batch (SC-003),
 * a stale plan, a row changed during the run (partial, then convergence), a host transaction (savepoint path), the
 * DDD host and the audit.
 */
final class ApplyTest extends ApplyTestCase
{
    private const DOCUMENT = <<<'YAML'
        provider: host.tags
        version: 1
        scope: platform
        items:
          - {slug: news, label: News, color: red, apiToken: 'vault:ok-1'}
          - {slug: sport, label: Sport}
        ---
        provider: host.articles
        version: 1
        scope: platform
        items:
          - {code: A-1, title: First, tag: news, level: 2, owner: bob}
          - {code: B-1, title: Second, owner: alice}

        YAML;

    private function queries(): QueryLog
    {
        return $this->service('test.query_log', QueryLog::class);
    }

    #[Test]
    public function anExportAppliesBackWithoutChanges(): void
    {
        $this->boot();
        $first = $this->apply($this->read(self::DOCUMENT));
        self::assertNotNull($first->runId);
        self::assertSame(['create' => 4, 'update' => 0, 'delete' => 0], $first->written);
        self::assertSame(['news' => 'News', 'sport' => 'Sport'], $this->labels());

        foreach ([DocumentFormat::Yaml, DocumentFormat::Json, DocumentFormat::JsonLines] as $format) {
            foreach (['host.tags', 'host.articles'] as $provider) {
                $exported = $this->export($provider, $format);
                $plan     = $this->diff($this->read($exported, $format));
                self::assertSame(0, $plan->changes(), $provider . ' ' . $format->value . "\n" . $exported);
                $result = $this->apply($this->read($exported, $format));
                self::assertNull($result->runId, 'nothing to do: no run');
            }
        }
        self::assertSame(1, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_seed_run')));
        $run = $this->service(RunStoreInterface::class, RunStoreInterface::class)->find($first->runId);
        self::assertSame(RunStatus::Applied, $run?->status);
        self::assertSame(2, $run->batchesDone);
        self::assertSame('ops', $run->actor?->value);
    }

    #[Test]
    public function aSecondApplyWritesNothingToTheHostTables(): void
    {
        $this->boot([], ['query_log' => true]);
        $set = $this->read(self::DOCUMENT);
        $this->apply($set);
        self::assertGreaterThan(0, $this->queries()->hostWrites());

        $this->queries()->reset();
        $result = $this->apply($set);

        self::assertSame(0, $result->plan->changes());
        self::assertSame(0, $this->queries()->hostWrites(), 'SC-002');
        self::assertSame(0, $this->queries()->matching('/^\s*(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+nowo_seed_run/i'), 'no run row either');
    }

    #[Test]
    public function theQueryCountPerBatchIsConstant(): void
    {
        $this->boot(['apply' => ['batch_size' => 2]], ['query_log' => true]);
        $host  = [];
        $total = [];
        foreach ([1, 2, 4] as $batches) {
            TestDatabase::createHostTables($this->connection());
            $labels = [];
            foreach (range(1, 2 * $batches) as $n) {
                $labels[sprintf('t%02d', $n)] = 'Tag ' . $n;
            }
            $set = $this->read(self::tags($labels));
            $this->queries()->reset();
            $result = $this->apply($set);
            self::assertSame($batches, $result->batches);
            $host[$batches]  = $this->queries()->hostStatements();
            $total[$batches] = $this->queries()->total();
        }

        self::assertSame($host[1] * 2, $host[2], 'SC-003: host statements per batch');
        self::assertSame($host[1] * 4, $host[4]);
        self::assertSame($total[2] - $total[1], ($total[4] - $total[2]) / 2, 'SC-003: every statement per batch');
    }

    #[Test]
    public function aChangeBetweenTheDryRunAndTheApplyIsStale(): void
    {
        $this->boot();
        $this->insertTag('news', 'Old');
        $set  = $this->read(self::tags(['news' => 'New']));
        $plan = $this->diff($set);

        $other = TestDatabase::connection();
        $other->executeStatement("UPDATE host_tag SET label = 'Edited' WHERE slug = 'news'");
        $other->close();

        try {
            $this->apply($set, 'ops', $plan->hash);
            self::fail('not stale');
        } catch (StalePlanException $e) {
            self::assertSame('SEED_005', $e->errorCode());
        }
        self::assertSame(['news' => 'Edited'], $this->labels());
        self::assertSame(0, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_seed_run')));
        self::assertSame(1, $this->apply($set)->batches, 'the new plan applies');
    }

    #[Test]
    public function aRowChangedDuringTheRunStopsItPartiallyAndAReapplyConverges(): void
    {
        $this->boot(['apply' => ['batch_size' => 1]]);
        $this->insertTag('a', 'A0');
        $this->insertTag('b', 'B0');
        $set                        = $this->read(self::tags(['a' => 'A1', 'b' => 'B1', 'c' => 'C1']));
        $connection                 = $this->connection();
        $this->hooks()->beforeApply = static function (string $provider, AppliedBatch $batch) use ($connection): void {
            if ($batch->number === 1) {
                $connection->executeStatement("UPDATE host_tag SET label = 'B-edited' WHERE slug = 'b'");
            }
        };

        try {
            $this->apply($set);
            self::fail('not interrupted');
        } catch (ApplyInterruptedException $e) {
            self::assertSame(ApplyInterruptedException::ROW_CHANGED, $e->kind);
            self::assertSame(1, $e->batchesCommitted);
            self::assertSame(3, $e->batchesTotal);
            self::assertTrue($e->isPartial());
            self::assertNotNull($e->runId);
            $run = $this->connection()->fetchAssociative('SELECT status, error_code, batches_done FROM nowo_seed_run WHERE id = ?', [$e->runId]);
            self::assertSame(['status' => 'partially_applied', 'error_code' => 'SEED_006', 'batches_done' => 1], ['status' => $run['status'] ?? null, 'error_code' => $run['error_code'] ?? null, 'batches_done' => TestDatabase::int($run['batches_done'] ?? null)]);
        }
        self::assertSame(['a' => 'A1', 'b' => 'B-edited'], $this->labels(), 'batch 1 stays');

        $this->hooks()->beforeApply = null;
        $again                      = $this->apply($set);
        self::assertSame(['a' => 'A1', 'b' => 'B1', 'c' => 'C1'], $this->labels());
        self::assertSame(['create' => 1, 'update' => 1, 'delete' => 0], $again->written);
        self::assertSame(0, $this->apply($set)->plan->changes(), 'converged');
    }

    #[Test]
    public function insideAHostTransactionTheRunIsASavepointOfIt(): void
    {
        $this->boot(['apply' => ['batch_size' => 1]]);
        $connection = $this->connection();
        $connection->beginTransaction();
        $result = $this->apply($this->read(self::tags(['a' => 'A', 'b' => 'B'])));

        self::assertSame(2, $result->batches);
        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(['a' => 'A', 'b' => 'B'], $this->labels());
        $connection->rollBack();

        self::assertSame([], $this->labels(), 'the host rolled the run back');
        self::assertSame(0, TestDatabase::int($connection->fetchOne('SELECT COUNT(*) FROM nowo_seed_run')));
        self::assertSame(1, TestDatabase::int($connection->fetchOne('SELECT 1')), 'the connection is usable');
    }

    #[Test]
    public function pruneDeletesInReverseDependencyOrder(): void
    {
        $this->boot();
        $this->apply($this->read(self::DOCUMENT));

        $result = $this->apply($this->read("provider: host.tags\nversion: 1\nscope: platform\nitems:\n  - {slug: news, label: News, color: red, apiToken: 'vault:ok-1'}\n---\nprovider: host.articles\nversion: 1\nscope: platform\nitems: []\n"), 'ops', null, null, true);

        self::assertSame(['create' => 0, 'update' => 0, 'delete' => 3], $result->written);
        self::assertSame(['news' => 'News'], $this->labels());
        self::assertSame(0, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM host_article')));
    }

    #[Test]
    public function theDddHostAppliesInItsOwnScopesOnItsOwnConnection(): void
    {
        $this->boot(['audit' => ['sink' => 'test.audit']], [], 'ddd');
        $acme   = new SeedScope(new ScopeKind('organization'), new ScopeId('acme'));
        $globex = new SeedScope(new ScopeKind('organization'), new ScopeId('globex'));
        $set    = $this->read(self::tags(['news' => 'News'], 'organization') . "---\nprovider: host.notes\nversion: 2\nscope: organization\nitems:\n  - {code: welcome, body: Hello}\n");

        $result = $this->apply($set, 'admin', null, $acme);
        self::assertSame(['create' => 2, 'update' => 0, 'delete' => 0], $result->written);
        self::assertSame(['news' => 'News'], $this->labels('organization:acme'));
        self::assertSame('Hello', $this->connection()->fetchOne("SELECT body FROM host_note WHERE scope_key = 'organization:acme' AND code = 'welcome'"));
        self::assertSame(0, $this->diff($this->read($this->export('host.notes', DocumentFormat::Yaml, 'admin', $acme)), 'admin', $acme)->changes());
        self::assertSame(2, $this->apply($set, 'admin', null, $globex)->plan->count(ChangeOp::Create), 'another organization, its own rows');

        try {
            $this->diff($set, 'bob', $globex);
            self::fail('bob sees globex');
        } catch (ScopeNotFoundException) {
        }
        $audit = $this->service('test.audit', RecordingAuditSink::class);
        self::assertCount(2, $audit->records);
        self::assertSame('apply', $audit->records[0]->action);
    }

    #[Test]
    public function everyApplyIsAuditedInTheLog(): void
    {
        $this->boot();
        $this->apply($this->read(self::DOCUMENT));

        $logger = $this->service('logger', RecordingLogger::class);
        self::assertContains('Seed {action} by {actor} in {scope}: {outcome}.', array_column($logger->records, 1));
    }
}
