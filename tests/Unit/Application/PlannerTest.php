<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Closure;
use Nowo\SeedKitBundle\Application\ErrorBag;
use Nowo\SeedKitBundle\Application\PlanHasher;
use Nowo\SeedKitBundle\Application\Planner;
use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Domain\Exception\ProviderContractException;
use Nowo\SeedKitBundle\Domain\Exception\PruneRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeMismatchException;
use Nowo\SeedKitBundle\Domain\Exception\SecretValueRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Plan\BatchPlan;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\FieldChange;
use Nowo\SeedKitBundle\Domain\Plan\FieldValueView;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\Harness;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\ArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * The dry run (contract 9, 10.1, 10.4): documents matched and ordered, changes re-checked, hashed and previewed.
 */
final class PlannerTest extends TestCase
{
    /**
     * @param list<FieldChange> $fields
     *
     * @return array<string, string>
     */
    private static function fields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$field->path] = $field->before->kind . ':' . CanonicalJson::encode($field->before->value) . ' → ' . $field->after->kind . ':' . CanonicalJson::encode($field->after->value);
        }

        return $out;
    }

    /**
     * The key of the first item a provider hook received.
     *
     * @param array<mixed> $items
     */
    private static function keyOf(array $items): NaturalKey
    {
        $item = $items[0] ?? null;
        self::assertInstanceOf(SeedItem::class, $item);

        return $item->key;
    }

    // ---------------------------------------------------------------- prepare

    #[Test]
    public function prepareRefusesAnEmptySet(): void
    {
        try {
            Harness::planner([])->prepare(Harness::set([]), Harness::platform());
            self::fail('expected');
        } catch (DocumentReadException $e) {
            self::assertSame('no_document', $e->reason);
        }
    }

    #[Test]
    public function prepareSortsByDependencyOrder(): void
    {
        $store    = new InMemoryStore();
        $tags     = new TagProvider($store);
        $articles = new ArticleProvider($store);
        $planner  = Harness::planner([$tags, $articles]);
        $prepared = $planner->prepare(Harness::set([
            Harness::document($articles, [], 'organization'),
            Harness::document($tags, [], 'organization'),
        ]), Harness::org());
        self::assertSame(['demo.tags', 'demo.articles'], array_map(static fn ($p): string => $p->provider->name()->value, $prepared));
        self::assertSame($tags, $prepared[0]->provider);
        self::assertSame($tags->schema()->fingerprint(), $prepared[0]->schema->fingerprint());
    }

    #[Test]
    public function prepareRefusesAKindMismatch(): void
    {
        $tags = new TagProvider(new InMemoryStore());
        $this->expectException(ScopeMismatchException::class);
        Harness::planner([$tags])->prepare(Harness::set([Harness::document($tags, [], 'platform')]), Harness::org());
    }

    #[Test]
    public function prepareRefusesAProviderNotAvailableInTheKind(): void
    {
        $articles = new ArticleProvider(new InMemoryStore());
        $this->expectException(ScopeMismatchException::class);
        Harness::planner([$articles])->prepare(Harness::set([Harness::document($articles, [], 'platform')]), Harness::platform());
    }

    #[Test]
    public function prepareRefusesAnotherVersion(): void
    {
        $tags = new TagProvider(new InMemoryStore());
        $this->expectException(UnknownProviderException::class);
        Harness::planner([$tags])->prepare(Harness::set([Harness::document($tags, [], 'platform', null, 2)]), Harness::platform());
    }

    #[Test]
    public function prepareRefusesAnUnknownProvider(): void
    {
        $tags = new TagProvider(new InMemoryStore());
        $this->expectException(UnknownProviderException::class);
        Harness::planner([])->prepare(Harness::set([Harness::document($tags, [])]), Harness::platform());
    }

    #[Test]
    public function prepareRefusesAProviderTwice(): void
    {
        $tags = new TagProvider(new InMemoryStore());
        try {
            Harness::planner([$tags])->prepare(Harness::set([Harness::document($tags, []), Harness::document($tags, [])]), Harness::platform());
            self::fail('expected');
        } catch (DocumentReadException $e) {
            self::assertSame('duplicate_provider', $e->reason);
        }
    }

    // ---------------------------------------------------------------- warnings

    #[Test]
    public function warnsWhenTheExportOriginDiffers(): void
    {
        $tags    = new TagProvider(new InMemoryStore());
        $planner = Harness::planner([$tags]);
        $same    = $planner->prepare(Harness::set([Harness::document($tags, [], 'organization', 'org-1')]), Harness::org('org-1'));
        self::assertSame([], $planner->warnings($same, Harness::org('org-1')));
        self::assertSame([SeedPlan::WARNING_EXPORTED_FROM], $planner->warnings($same, Harness::org('org-2')));
        self::assertSame([SeedPlan::WARNING_EXPORTED_FROM], $planner->warnings($same, Harness::platform()));
        $none = $planner->prepare(Harness::set([Harness::document($tags, [], 'organization')]), Harness::org('org-1'));
        self::assertSame([], $planner->warnings($none, Harness::org('org-1')));

        $plan = $planner->plan($same, Harness::context(Harness::org('org-2')), new PruneOptions());
        self::assertSame([SeedPlan::WARNING_EXPORTED_FROM], $plan->warnings);
    }

    // ---------------------------------------------------------------- plan

    #[Test]
    public function plansCreateUpdateAndUnchangedRows(): void
    {
        $provider = new ScriptedProvider();
        $provider->seed('b', ['label' => 'Old', 'note' => 'n']);
        $provider->seed('c', ['label' => 'Same']);
        $planner = Harness::planner([$provider], Harness::settings(batchSize: 2));
        $items   = [['code' => 'a', 'label' => 'A'], ['code' => 'b', 'label' => 'New'], ['code' => 'c', 'label' => 'Same']];
        $docs    = $planner->prepare(Harness::set([Harness::document($provider, $items)]), Harness::platform());
        $plan    = $planner->plan($docs, Harness::context(), new PruneOptions());

        self::assertCount(1, $plan->documents);
        $document = $plan->documents[0];
        self::assertSame(['create' => 1, 'update' => 1, 'unchanged' => 1, 'delete' => 0], $document->counts);
        self::assertSame(2, $plan->changes());
        self::assertSame(2, $document->batches());
        self::assertSame([true, false], $document->writes, 'the second slice holds only an unchanged row');
        self::assertSame([], $document->deleteDigests);
        self::assertSame(1, $plan->writingBatches());
        self::assertSame($provider->schema()->fingerprint(), $document->schemaFingerprint);
        self::assertFalse($plan->prune);

        self::assertSame([ChangeOp::Create, ChangeOp::Update, ChangeOp::Unchanged], array_map(static fn (RowChange $c): ChangeOp => $c->op, $document->preview));
        self::assertSame(['/code' => 'absent:null → value:"a"', '/label' => 'absent:null → value:"A"'], self::fields($document->preview[0]->fields));
        self::assertSame(['/label' => 'value:"Old" → value:"New"'], self::fields($document->preview[1]->fields), 'note omitted keeps the value');
        self::assertSame(hash('sha256', '{"code":"b","label":"New"}'), $document->preview[1]->desiredFingerprint);
        self::assertSame(Planner::desiredFingerprint(new SeedItem(0, new NaturalKey(['a']), ['code' => 'a', 'label' => 'A'])), $document->preview[0]->desiredFingerprint);

        // digests: one per slice, recomputable from the changes
        self::assertSame(PlanHasher::batchDigest(new ProviderName('demo.items'), 1, [$document->preview[0], $document->preview[1]]), $document->upsertDigests[0]);
        self::assertSame(['plan:2', 'plan:1'], $provider->log);

        // deterministic hash, sensitive to the scope and to prune
        $again = $planner->plan($docs, Harness::context(), new PruneOptions());
        self::assertTrue($plan->hash->equals($again->hash));
        $other = $planner->plan($planner->prepare(Harness::set([Harness::document($provider, $items, 'organization')]), Harness::org()), Harness::context(Harness::org()), new PruneOptions());
        self::assertFalse($plan->hash->equals($other->hash));
    }

    #[Test]
    public function aChangedItemChangesTheHash(): void
    {
        $provider = new ScriptedProvider();
        $planner  = Harness::planner([$provider]);
        $hash     = static fn (string $label): string => $planner->plan($planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a', 'label' => $label]])]), Harness::platform()), Harness::context(), new PruneOptions())->hash->value;
        self::assertSame($hash('x'), $hash('x'));
        self::assertNotSame($hash('x'), $hash('x '));
    }

    #[Test]
    public function emptyDocumentPlansNothing(): void
    {
        $provider = new ScriptedProvider();
        $planner  = Harness::planner([$provider]);
        $plan     = $planner->plan($planner->prepare(Harness::set([Harness::document($provider, [])]), Harness::platform()), Harness::context(), new PruneOptions());
        self::assertSame(0, $plan->changes());
        self::assertSame(0, $plan->batches());
        self::assertSame([], $provider->log);
    }

    #[Test]
    public function duplicateKeysAreRowErrors(): void
    {
        $tags    = new TagProvider(new InMemoryStore());
        $planner = Harness::planner([$tags]);
        $docs    = $planner->prepare(Harness::set([Harness::document($tags, [
            ['slug' => 'news', 'label' => 'A'],
            ['slug' => 'NEWS', 'label' => 'B'],
        ])]), Harness::platform());
        try {
            $planner->plan($docs, Harness::context(), new PruneOptions());
            self::fail('expected');
        } catch (DocumentInvalidException $e) {
            self::assertCount(1, $e->errors);
            self::assertSame([1, '["news"]', 'duplicate_key', 'seed_kit.row.duplicate_key', 'demo.tags'], [$e->errors[0]->index, $e->errors[0]->key?->canonical, $e->errors[0]->rule, $e->errors[0]->messageKey, $e->errors[0]->provider]);
            self::assertFalse($e->truncated);
        }
    }

    #[Test]
    public function rowErrorsAreCappedAndTruncated(): void
    {
        $provider = new ScriptedProvider();
        $planner  = Harness::planner([$provider], Harness::settings(maxErrors: 2));
        $docs     = $planner->prepare(Harness::set([Harness::document($provider, [['label' => 'x'], 'scalar', ['code' => 5], ['code' => 'ok']])]), Harness::platform());
        try {
            $planner->plan($docs, Harness::context(), new PruneOptions());
            self::fail('expected');
        } catch (DocumentInvalidException $e) {
            self::assertCount(2, $e->errors);
            self::assertTrue($e->truncated);
            self::assertSame([0, 1], [$e->errors[0]->index, $e->errors[1]->index]);
            self::assertSame('seed_kit.row.not_an_object', $e->errors[1]->messageKey);
            self::assertStringNotContainsString('scalar', $e->getMessage());
        }
    }

    #[Test]
    public function providerErrorsAreCollected(): void
    {
        $store    = new InMemoryStore();
        $articles = new ArticleProvider($store);
        $planner  = Harness::planner([new TagProvider($store), $articles]);
        $docs     = $planner->prepare(Harness::set([Harness::document($articles, [['code' => 'a1', 'title' => 'T', 'tag' => 'nope']], 'organization')]), Harness::org());
        try {
            $planner->plan($docs, Harness::context(Harness::org()), new PruneOptions());
            self::fail('expected');
        } catch (DocumentInvalidException $e) {
            self::assertSame(['reference', 'demo.articles', '/tag'], [$e->errors[0]->rule, $e->errors[0]->provider, $e->errors[0]->path]);
        }
    }

    #[Test]
    public function secretErrorsRefuseTheDocument(): void
    {
        $tags    = new TagProvider(new InMemoryStore());
        $planner = Harness::planner([$tags]);
        $docs    = $planner->prepare(Harness::set([Harness::document($tags, [
            ['slug' => 'a', 'label' => 'A', 'apiToken' => 'plain-secret'],
            ['slug' => 'b', 'label' => 'B', 'color' => 'pink'],
        ])]), Harness::platform());
        try {
            $planner->plan($docs, Harness::context(), new PruneOptions());
            self::fail('expected');
        } catch (SecretValueRefusedException $e) {
            self::assertSame(['secret_plaintext', 'schema_enum'], [$e->errors[0]->rule, 'schema_' . $e->errors[1]->rule]);
            self::assertStringNotContainsString('plain-secret', $e->getMessage());
            foreach ($e->errors as $error) {
                self::assertNotContains('plain-secret', $error->messageParams);
            }
        }
    }

    #[Test]
    public function pendingKeysLetReferencesResolveAcrossDocuments(): void
    {
        $store    = new InMemoryStore();
        $tags     = new TagProvider($store);
        $articles = new ArticleProvider($store);
        $planner  = Harness::planner([$tags, $articles]);
        $docs     = $planner->prepare(Harness::set([
            Harness::document($articles, [['code' => 'a1', 'title' => 'T', 'tag' => 'news']], 'organization'),
            Harness::document($tags, [['slug' => 'News', 'label' => 'News']], 'organization'),
        ]), Harness::org());
        $context = Harness::context(Harness::org());
        $plan    = $planner->plan($docs, $context, new PruneOptions());
        self::assertSame(['demo.tags', 'demo.articles'], array_map(static fn ($d): string => $d->provider->value, $plan->documents));
        self::assertSame(2, $plan->count(ChangeOp::Create));
        self::assertTrue($context->pending->has(new ProviderName('demo.tags'), new NaturalKey(['news'])));
        self::assertSame(0, $store->applyCalls, 'a dry run writes nothing');
    }

    // ---------------------------------------------------------------- provider contract

    /**
     * @return iterable<string, array{Closure(array<mixed>): BatchPlan}>
     */
    public static function brokenBatches(): iterable
    {
        yield 'too few' => [/** @param list<SeedItem> $items */ static fn (array $items): BatchPlan => new BatchPlan([])];
        yield 'too many' => [/** @param list<SeedItem> $items */ static fn (array $items): BatchPlan => new BatchPlan([RowChange::create(self::keyOf($items), 0), RowChange::create(self::keyOf($items), 0)])];
        yield 'other key' => [/** @param list<SeedItem> $items */ static fn (array $items): BatchPlan => new BatchPlan([RowChange::create(new NaturalKey(['other']), 0)])];
        yield 'folded key' => [/** @param list<SeedItem> $items */ static fn (array $items): BatchPlan => new BatchPlan([RowChange::create(new NaturalKey(['A']), 0)])];
        yield 'other index' => [/** @param list<SeedItem> $items */ static fn (array $items): BatchPlan => new BatchPlan([RowChange::create(self::keyOf($items), 7)])];
        yield 'delete' => [/** @param list<SeedItem> $items */ static fn (array $items): BatchPlan => new BatchPlan([RowChange::delete(self::keyOf($items), 'fp')])];
    }

    /**
     * @param Closure(array<mixed>): BatchPlan $plan
     */
    #[Test]
    #[DataProvider('brokenBatches')]
    public function refusesBrokenBatchPlans(Closure $plan): void
    {
        $provider              = new ScriptedProvider();
        $provider->onPlanBatch = /** @param list<SeedItem> $items */ static fn (SeedContext $context, array $items, PlanOptions $options): BatchPlan => $plan($items);
        $planner               = Harness::planner([$provider]);
        $docs                  = $planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a']])]), Harness::platform());
        $this->expectException(ProviderContractException::class);
        $planner->plan($docs, Harness::context(), new PruneOptions());
    }

    /**
     * @return iterable<string, array{Closure(): iterable<RowChange>}>
     */
    public static function brokenDeletions(): iterable
    {
        yield 'not a delete' => [static fn (): iterable => [RowChange::update(new NaturalKey(['z']), 0, [], 'fp')]];
        yield 'in document' => [static fn (): iterable => [RowChange::delete(new NaturalKey(['a']), 'fp')]];
        yield 'unordered' => [static fn (): iterable => [RowChange::delete(new NaturalKey(['y']), 'fp'), RowChange::delete(new NaturalKey(['x']), 'fp')]];
        yield 'repeated' => [static fn (): iterable => [RowChange::delete(new NaturalKey(['x']), 'fp'), RowChange::delete(new NaturalKey(['x']), 'fp')]];
    }

    /**
     * @param Closure(): iterable<RowChange> $deletions
     */
    #[Test]
    #[DataProvider('brokenDeletions')]
    public function refusesBrokenDeletions(Closure $deletions): void
    {
        $provider                  = new ScriptedProvider();
        $provider->onPlanDeletions = static fn (): iterable => $deletions();
        $planner                   = Harness::planner([$provider]);
        $docs                      = $planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a']])]), Harness::platform());
        $this->expectException(ProviderContractException::class);
        $planner->plan($docs, Harness::context(prune: true), new PruneOptions(true, true));
    }

    // ---------------------------------------------------------------- prune

    #[Test]
    public function prunePlansDeletionsInSlices(): void
    {
        $provider = new ScriptedProvider();
        foreach (['a', 'b', 'c', 'd', 'e'] as $code) {
            $provider->seed($code, ['label' => strtoupper($code)]);
        }
        $planner = Harness::planner([$provider], Harness::settings(batchSize: 2, pruneMaxRatio: 0.9));
        $docs    = $planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a', 'label' => 'A']])]), Harness::platform());
        $plan    = $planner->plan($docs, Harness::context(prune: true), new PruneOptions(true));
        $doc     = $plan->documents[0];
        self::assertTrue($plan->prune);
        self::assertSame(4, $doc->count(ChangeOp::Delete));
        self::assertSame(1, $doc->count(ChangeOp::Unchanged));
        self::assertCount(1, $doc->upsertDigests);
        self::assertCount(2, $doc->deleteDigests);
        self::assertSame([false, true, true], $doc->writes);
        $deletes = array_values(array_filter($doc->preview, static fn (RowChange $c): bool => $c->op === ChangeOp::Delete));
        self::assertSame(['["b"]', '["c"]', '["d"]', '["e"]'], array_map(static fn (RowChange $c): string => $c->key->canonical, $deletes));
        self::assertNull($deletes[0]->desiredFingerprint);
        self::assertNull($deletes[0]->index);

        $noPrune = $planner->plan($docs, Harness::context(), new PruneOptions());
        self::assertFalse($plan->hash->equals($noPrune->hash));
    }

    #[Test]
    public function pruneRefusesAnEmptyDocumentUnlessAllowed(): void
    {
        $provider = new ScriptedProvider();
        $provider->seed('a');
        $planner = Harness::planner([$provider], Harness::settings(pruneMaxRatio: 1.0));
        $docs    = $planner->prepare(Harness::set([Harness::document($provider, [])]), Harness::platform());
        try {
            $planner->plan($docs, Harness::context(prune: true), new PruneOptions(true));
            self::fail('expected');
        } catch (PruneRefusedException $e) {
            self::assertSame(PruneRefusedException::EMPTY, $e->guard);
        }
        $plan = $planner->plan($docs, Harness::context(prune: true), new PruneOptions(true, false, true));
        self::assertSame(1, $plan->count(ChangeOp::Delete));
    }

    #[Test]
    public function pruneRefusesAMassDeletionUnlessAllowed(): void
    {
        $provider = new ScriptedProvider();
        foreach (['a', 'b', 'c', 'd'] as $code) {
            $provider->seed($code);
        }
        $planner = Harness::planner([$provider]);
        $docs    = $planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a']])]), Harness::platform());
        try {
            $planner->plan($docs, Harness::context(prune: true), new PruneOptions(true));
            self::fail('expected');
        } catch (PruneRefusedException $e) {
            self::assertSame([PruneRefusedException::MASS, 3, 4], [$e->guard, $e->deletions, $e->current]);
        }
        self::assertSame(3, $planner->plan($docs, Harness::context(prune: true), new PruneOptions(true, true))->count(ChangeOp::Delete));

        // half of the rows is not a mass prune at ratio 0.5
        $docs = $planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a'], ['code' => 'b']])]), Harness::platform());
        self::assertSame(2, $planner->plan($docs, Harness::context(prune: true), new PruneOptions(true))->count(ChangeOp::Delete));
    }

    #[Test]
    public function pruneGuardsGiveWayToRowErrors(): void
    {
        $provider = new ScriptedProvider();
        foreach (['a', 'b', 'c'] as $code) {
            $provider->seed($code);
        }
        $planner = Harness::planner([$provider]);
        $docs    = $planner->prepare(Harness::set([Harness::document($provider, [['label' => 'no key']])]), Harness::platform());
        $this->expectException(DocumentInvalidException::class);
        $planner->plan($docs, Harness::context(prune: true), new PruneOptions(true));
    }

    // ---------------------------------------------------------------- preview

    #[Test]
    public function previewIsCappedPerOperation(): void
    {
        $provider = new ScriptedProvider();
        $provider->seed('x', ['label' => 'old']);
        $provider->seed('y', ['label' => 'old']);
        $planner = Harness::planner([$provider], Harness::settings(previewRows: 1));
        $docs    = $planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a'], ['code' => 'b'], ['code' => 'x', 'label' => 'new'], ['code' => 'y', 'label' => 'new']])]), Harness::platform());
        $plan    = $planner->plan($docs, Harness::context(), new PruneOptions());
        self::assertSame(['["a"]', '["x"]'], array_map(static fn (RowChange $c): string => $c->key->canonical, $plan->documents[0]->preview));
        self::assertSame(['create' => 2, 'update' => 2, 'unchanged' => 0, 'delete' => 0], $plan->documents[0]->counts);
    }

    #[Test]
    public function previewHidesSecretValuesThatAreNotReferences(): void
    {
        $store                             = new InMemoryStore();
        $store->rows['demo.tags']['["a"]'] = ['slug' => 'a', 'label' => 'Old', 'apiToken' => 'legacy-plain', 'createdAt' => 'x'];
        $store->rows['demo.tags']['["b"]'] = ['slug' => 'b', 'label' => 'B', 'apiToken' => 'vault:ok-old'];
        $tags                              = new TagProvider($store);
        $planner                           = Harness::planner([$tags]);
        $docs                              = $planner->prepare(Harness::set([Harness::document($tags, [
            ['slug' => 'a', 'label' => 'New', 'apiToken' => 'vault:ok-1'],
            ['slug' => 'b', 'label' => 'B', 'apiToken' => 'vault:ok-2'],
        ])]), Harness::platform());
        $plan = $planner->plan($docs, Harness::context(), new PruneOptions());
        self::assertSame([
            '/label'    => 'value:"Old" → value:"New"',
            '/apiToken' => 'hidden:null → value:"vault:ok-1"',
        ], self::fields($plan->documents[0]->preview[0]->fields));
        self::assertSame(['/apiToken' => 'value:"vault:ok-old" → value:"vault:ok-2"'], self::fields($plan->documents[0]->preview[1]->fields));

        $masked = $planner->plan($docs, Harness::context(canSee: false), new PruneOptions(), true);
        self::assertSame([
            '/label'    => 'hidden:null → value:"New"',
            '/apiToken' => 'hidden:null → value:"vault:ok-1"',
        ], self::fields($masked->documents[0]->preview[0]->fields));
        self::assertTrue($plan->hash->equals($masked->hash), 'masking changes the preview only');
    }

    #[Test]
    public function previewHidesAPlainSecretOfACreateAndKeepsAbsent(): void
    {
        $tags = new ScriptedProvider('demo.secrets', [
            'type'       => 'object',
            'x-seed-key' => ['code'],
            'properties' => ['code' => ['type' => 'string'], 'pin' => ['type' => 'string', 'x-seed-secret' => true]],
        ]);
        $tags->onPlanBatch = /** @param list<SeedItem> $items */ static fn (SeedContext $context, array $items): BatchPlan => new BatchPlan([RowChange::create(self::keyOf($items), 0, [
            new FieldChange('/pin', FieldValueView::absent(), FieldValueView::value('1234')),
            new FieldChange('/code', FieldValueView::absent(), FieldValueView::value('a')),
        ])]);
        $planner = Harness::planner([$tags]);
        $docs    = $planner->prepare(Harness::set([Harness::document($tags, [['code' => 'a']])]), Harness::platform());
        $plan    = $planner->plan($docs, Harness::context(), new PruneOptions(), true);
        self::assertSame(['/pin' => 'absent:null → hidden:null', '/code' => 'absent:null → value:"a"'], self::fields($plan->documents[0]->preview[0]->fields));
    }

    // ---------------------------------------------------------------- helpers

    #[Test]
    public function anyWriteAndDesiredFingerprint(): void
    {
        $key = new NaturalKey(['a']);
        self::assertFalse(Planner::anyWrite([]));
        self::assertFalse(Planner::anyWrite([RowChange::unchanged($key, 0, 'fp')]));
        self::assertTrue(Planner::anyWrite([RowChange::unchanged($key, 0, 'fp'), RowChange::delete($key, 'fp')]));
        self::assertSame(hash('sha256', '{}'), Planner::desiredFingerprint(new SeedItem(0, $key, [])));
        self::assertSame(hash('sha256', '{"a":1,"b":2}'), Planner::desiredFingerprint(new SeedItem(0, $key, ['b' => 2, 'a' => 1])));
    }

    #[Test]
    public function slicesRespectTheBatchSize(): void
    {
        $provider = new ScriptedProvider();
        $planner  = Harness::planner([$provider], Harness::settings(batchSize: 2));
        $docs     = $planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a'], ['code' => 'b'], ['code' => 'c'], ['code' => 'd']])]), Harness::platform());
        $seen     = new NaturalKeySet();
        $sizes    = [];
        foreach ($planner->slices($docs[0], Harness::context(), $seen, new ErrorBag(10)) as $slice) {
            $sizes[] = count($slice);
        }
        self::assertSame([2, 2], $sizes);
        self::assertCount(4, $seen);

        $deletions = [];
        $provider->seed('x');
        $provider->seed('y');
        $provider->seed('z');
        foreach ($planner->deletionSlices($docs[0], Harness::context(), $seen, new PlanOptions()) as $slice) {
            $deletions[] = count($slice);
        }
        self::assertSame([2, 1], $deletions);
    }

    #[Test]
    public function rowErrorsOfProviderHaveTheirProvider(): void
    {
        $provider              = new ScriptedProvider();
        $provider->onPlanBatch = /** @param list<SeedItem> $items */ static fn (SeedContext $context, array $items): BatchPlan => new BatchPlan(
            [RowChange::create(self::keyOf($items), 0)],
            [new RowError(0, self::keyOf($items), '/code', 'custom', 'app.custom', ['n' => 1])],
        );
        $planner = Harness::planner([$provider]);
        $bag     = new ErrorBag(5);
        $docs    = $planner->prepare(Harness::set([Harness::document($provider, [['code' => 'a']])]), Harness::platform());
        $changes = $planner->planSlice($docs[0], Harness::context(), [new SeedItem(0, new NaturalKey(['a']), ['code' => 'a'])], new PlanOptions(), $bag);
        self::assertCount(1, $changes);
        self::assertFalse($bag->isEmpty());
        try {
            $bag->throwIfAny();
            self::fail('expected');
        } catch (DocumentInvalidException $e) {
            self::assertSame('demo.items', $e->errors[0]->provider);
            self::assertSame(['n' => 1], $e->errors[0]->messageParams);
        }
    }
}
