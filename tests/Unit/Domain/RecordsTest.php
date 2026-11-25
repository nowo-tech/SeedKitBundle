<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Domain;

use ArrayObject;
use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\ExportDocument;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Document\SeedDocument;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentHeader;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentMeta;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Event\SeedBatchCommittedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedExportedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunAppliedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunFailedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunPlannedEvent;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\PendingKeys;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Documents, events (VOs and ids only), audit records, run records and the context.
 */
final class RecordsTest extends TestCase
{
    private const RUN = '01890a5d-ac96-774b-bcce-b302099a8057';

    #[Test]
    public function documentHeaderBoundsTheVersion(): void
    {
        $header = new SeedDocumentHeader(new ProviderName('demo.tags'), 999, new ScopeKind('platform'));
        self::assertSame(999, $header->version);
        self::assertNull($header->schemaRef);
        self::assertNull($header->meta->exportedAt);
        self::assertNull($header->meta->exportedFrom);
        self::assertNull($header->meta->generator);
        new SeedDocumentHeader(new ProviderName('demo.tags'), 1, new ScopeKind('platform'));

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('A document version is between 1 and 999.');
        new SeedDocumentHeader(new ProviderName('demo.tags'), 1000, new ScopeKind('platform'));
    }

    #[Test]
    public function documentHeaderRefusesVersionZero(): void
    {
        $this->expectException(InvalidValueException::class);
        new SeedDocumentHeader(new ProviderName('demo.tags'), 0, new ScopeKind('platform'));
    }

    #[Test]
    public function documentsAndItems(): void
    {
        $meta   = new SeedDocumentMeta('2026-01-01T00:00:00Z', new ScopeId('org-1'), 'seed-kit 0.1');
        $header = new SeedDocumentHeader(new ProviderName('demo.tags'), 2, new ScopeKind('organization'), 'ref', $meta);
        self::assertSame('ref', $header->schemaRef);
        self::assertSame('org-1', $header->meta->exportedFrom?->value);
        self::assertSame('seed-kit 0.1', $header->meta->generator);

        $calls    = new ArrayObject();
        $document = new SeedDocument($header, static function () use ($calls): iterable {
            $calls->append(1);

            yield ['slug' => 'a'];
        });
        self::assertCount(0, $calls);
        self::assertSame([['slug' => 'a']], iterator_to_array($document->items()));
        self::assertSame([['slug' => 'a']], iterator_to_array($document->items()));
        self::assertCount(2, $calls);

        $set = new DocumentSet([$document], DocumentFormat::Json, DocumentHash::ofBytes('x'));
        self::assertSame([$document], $set->documents);
        self::assertSame(DocumentFormat::Json, $set->format);

        $item = new SeedItem(3, new NaturalKey(['a']), ['slug' => 'a', 'color' => null]);
        self::assertTrue($item->has('slug'));
        self::assertTrue($item->has('color'));
        self::assertFalse($item->has('label'));
        self::assertSame(3, $item->index);

        $export = new ExportDocument($header, new SeedSchema([]), ExportMode::Current, [['a' => 1]]);
        self::assertSame(ExportMode::Current, $export->mode);
        self::assertSame([['a' => 1]], $export->items);
    }

    #[Test]
    public function auditRecordToArray(): void
    {
        $record = new SeedAuditRecord(
            SeedAuditRecord::APPLY,
            new ActorRef('u1'),
            SeedScope::parse('organization:o1'),
            ['demo.tags'],
            ['create' => 1],
            Channel::Console,
            SeedAuditRecord::OUTCOME_SUCCESS,
            'sha256:x',
            'sk1:y',
            self::RUN,
        );
        self::assertSame([
            'action'        => 'apply',
            'actor'         => 'u1',
            'scope'         => 'organization:o1',
            'providers'     => ['demo.tags'],
            'counts'        => ['create' => 1],
            'channel'       => 'console',
            'outcome'       => 'success',
            'document_hash' => 'sha256:x',
            'plan_hash'     => 'sk1:y',
            'run_id'        => self::RUN,
        ], $record->toArray());

        $export = new SeedAuditRecord(SeedAuditRecord::EXPORT, ActorRef::system(), SeedScope::platform(), [], ['rows' => 3], Channel::Http, 'SEED_001');
        $array  = $export->toArray();
        self::assertNull($array['document_hash']);
        self::assertNull($array['plan_hash']);
        self::assertNull($array['run_id']);
        self::assertSame('export', $array['action']);
    }

    #[Test]
    public function contextWithRunKeepsEverythingElse(): void
    {
        $pending = new PendingKeys();
        $context = new SeedContext(SeedScope::platform(), new ActorRef('u'), Channel::Console, null, $pending, true, false);
        $run     = new RunId(self::RUN);
        $with    = $context->withRun($run);
        self::assertNull($context->runId);
        self::assertSame($run, $with->runId);
        self::assertSame($pending, $with->pending);
        self::assertTrue($with->prune);
        self::assertFalse($with->canSeeCurrentValues);
        self::assertSame($context->scope, $with->scope);
        self::assertSame($context->actor, $with->actor);
        self::assertSame(Channel::Console, $with->channel);

        $defaults = new SeedContext(SeedScope::platform(), new ActorRef('u'), Channel::Http);
        self::assertFalse($defaults->prune);
        self::assertTrue($defaults->canSeeCurrentValues);
    }

    #[Test]
    public function eventsCarryIdsAndValueObjects(): void
    {
        $run      = new RunId(self::RUN);
        $provider = new ProviderName('demo.tags');
        $scope    = SeedScope::platform();
        $actor    = new ActorRef('u');
        $plan     = new PlanHash('sk1:' . str_repeat('b', 64));

        $batch = new SeedBatchCommittedEvent($run, $provider, 2, 1, 3, 4);
        self::assertSame([2, 1, 3, 4], [$batch->batchNumber, $batch->created, $batch->updated, $batch->deleted]);

        $exported = new SeedExportedEvent($provider, $scope, ExportMode::Empty, 0, $actor);
        self::assertSame(ExportMode::Empty, $exported->mode);
        self::assertSame(0, $exported->rows);

        $applied = new SeedRunAppliedEvent($run, ['demo.tags'], $scope, ['create' => 1], null, $plan, $actor, Channel::Http);
        self::assertNull($applied->documentHash);
        self::assertSame(['create' => 1], $applied->counts);
        self::assertSame($plan, $applied->planHash);

        $failed = new SeedRunFailedEvent($run, 'SEED_006', 1);
        self::assertSame('SEED_006', $failed->errorCode);
        self::assertSame(1, $failed->batchesCommitted);

        $planned = new SeedRunPlannedEvent(['demo.tags'], $scope, $plan, 7, $actor);
        self::assertSame(7, $planned->changes);
    }

    #[Test]
    public function runRecord(): void
    {
        $now    = new DateTimeImmutable('2026-01-01');
        $record = new RunRecord(
            new RunId(self::RUN),
            SeedScope::platform(),
            null,
            Channel::Console,
            RunStatus::Planned,
            ['demo.tags' => 1],
            false,
            null,
            new PlanHash('sk1:' . str_repeat('c', 64)),
            ['d1'],
            0,
            ['create' => 1],
            null,
            $now,
        );
        self::assertNull($record->actor);
        self::assertNull($record->finishedAt);
        self::assertSame(['demo.tags' => 1], $record->providers);
        self::assertSame(['d1'], $record->batchDigests);
        self::assertSame($now, $record->createdAt);
        self::assertSame(RunStatus::Planned, $record->status);
    }
}
