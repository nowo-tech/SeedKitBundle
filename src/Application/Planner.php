<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Generator;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Domain\Exception\ProviderContractException;
use Nowo\SeedKitBundle\Domain\Exception\PruneRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeMismatchException;
use Nowo\SeedKitBundle\Domain\Exception\SecretValueRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\DocumentPlan;
use Nowo\SeedKitBundle\Domain\Plan\FieldChange;
use Nowo\SeedKitBundle\Domain\Plan\FieldValueView;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;

use function count;
use function hash;
use function in_array;
use function is_string;
use function str_replace;
use function strcmp;
use function substr;

/**
 * Plans a run (contract section 10.2 steps 1–3): checks the headers, normalises and validates every item, asks the
 * providers for the changes slice by slice (one bounded query set per slice), re-checks what they return (exact keys,
 * same order; deletions outside the document, ascending), applies the prune guards, and produces the plan hash and the
 * batch digests while streaming. Any row error ⇒ `SEED_003` / `SEED_004` before anything is stored or written.
 *
 * The slice helpers are shared with {@see Applier}, which re-plans each batch inside its transaction. Stateless: every
 * per-run collection is created per call.
 */
final readonly class Planner
{
    /**
     * Builds the planner.
     */
    public function __construct(
        private ProviderRegistry $registry,
        private ItemNormaliser $normaliser,
        private SeedKitSettings $settings,
    ) {
    }

    /**
     * Matches the documents to their providers, checks the headers, sorts them by dependency order.
     *
     * @throws DocumentReadException for an empty set or a provider present twice
     * @throws ScopeMismatchException when a document's kind differs from the target or the provider is not available in it
     * @throws UnknownProviderException for an unknown provider or version
     *
     * @return list<PreparedDocument>
     */
    public function prepare(DocumentSet $set, SeedScope $scope): array
    {
        if ($set->documents === []) {
            throw new DocumentReadException('no_document');
        }
        $byName = [];
        foreach ($set->documents as $document) {
            $header = $document->header;
            if (!$header->scope->equals($scope->kind)) {
                throw new ScopeMismatchException();
            }
            $provider = $this->registry->get($header->provider);
            $kinds    = [];
            foreach ($provider->scopes() as $kind) {
                $kinds[] = $kind->value;
            }
            if (!in_array($scope->kind->value, $kinds, true)) {
                throw new ScopeMismatchException();
            }
            if ($provider->version() !== $header->version) {
                throw new UnknownProviderException();
            }
            if (isset($byName[$header->provider->value])) {
                throw new DocumentReadException('duplicate_provider');
            }
            $byName[$header->provider->value] = new PreparedDocument($document, $provider, $provider->schema());
        }
        $prepared = [];
        $names    = [];
        foreach ($byName as $document) {
            $names[] = $document->provider->name();
        }
        foreach ($this->registry->sort($names) as $name) {
            $prepared[] = $byName[$name->value];
        }

        return $prepared;
    }

    /**
     * The warnings of a run (Q-SK-12: the origin scope id differs from the target).
     *
     * @param list<PreparedDocument> $documents
     *
     * @return list<string>
     */
    public function warnings(array $documents, SeedScope $scope): array
    {
        foreach ($documents as $document) {
            $from = $document->document->header->meta->exportedFrom;
            if ($from instanceof ScopeId && (!$scope->id instanceof ScopeId || !$from->equals($scope->id))) {
                return [SeedPlan::WARNING_EXPORTED_FROM];
            }
        }

        return [];
    }

    /**
     * Plans the run (dry run; no lock, nothing written).
     *
     * @param list<PreparedDocument> $documents from {@see prepare()}
     *
     * @throws DocumentInvalidException
     * @throws SecretValueRefusedException
     * @throws PruneRefusedException
     * @throws ProviderContractException
     */
    public function plan(array $documents, SeedContext $context, PruneOptions $prune, bool $maskCurrentValues = false): SeedPlan
    {
        $hasher = new PlanHasher($context->scope, $prune->prune);
        $errors = new ErrorBag($this->settings->maxErrors);
        $plans  = [];
        $noLock = new PlanOptions(false);
        foreach ($documents as $document) {
            $provider = $document->provider->name();
            $hasher->document($provider, $document->provider->version(), $document->schema->fingerprint());
            $counts  = [ChangeOp::Create->value => 0, ChangeOp::Update->value => 0, ChangeOp::Unchanged->value => 0, ChangeOp::Delete->value => 0];
            $preview = [];
            $upserts = [];
            $deletes = [];
            $writes  = [];
            $seen    = new NaturalKeySet();
            $batch   = 0;
            $items   = 0;
            foreach ($this->slices($document, $context, $seen, $errors) as $slice) {
                $items += count($slice);
                $changes = $this->planSlice($document, $context, $slice, $noLock, $errors);
                foreach ($changes as $change) {
                    $hasher->change($change);
                    ++$counts[$change->op->value];
                    $this->preview($preview, $change, $document->schema, $maskCurrentValues);
                    if ($change->op === ChangeOp::Create) {
                        $context->pending->add($provider, $change->key);
                    }
                }
                $upserts[] = PlanHasher::batchDigest($provider, ++$batch, $changes);
                $writes[]  = self::anyWrite($changes);
            }
            if ($prune->prune) {
                if ($items === 0 && !$prune->allowEmptyPrune && $errors->isEmpty()) {
                    throw new PruneRefusedException(PruneRefusedException::EMPTY, 0, $counts[ChangeOp::Update->value] + $counts[ChangeOp::Unchanged->value]);
                }
                foreach ($this->deletionSlices($document, $context, $seen, $noLock) as $slice) {
                    foreach ($slice as $change) {
                        $hasher->change($change);
                        ++$counts[ChangeOp::Delete->value];
                        $this->preview($preview, $change, $document->schema, $maskCurrentValues);
                    }
                    $deletes[] = PlanHasher::batchDigest($provider, ++$batch, $slice);
                    $writes[]  = true;
                }
                $current = $counts[ChangeOp::Update->value] + $counts[ChangeOp::Unchanged->value] + $counts[ChangeOp::Delete->value];
                if ($counts[ChangeOp::Delete->value] > 0 && $counts[ChangeOp::Delete->value] > $current * $this->settings->pruneMaxRatio && !$prune->allowMassPrune && $errors->isEmpty()) {
                    throw new PruneRefusedException(PruneRefusedException::MASS, $counts[ChangeOp::Delete->value], $current);
                }
            }
            $plans[] = new DocumentPlan($provider, $document->provider->version(), $document->schema->fingerprint(), $counts, $preview, $upserts, $deletes, $writes);
        }
        $errors->throwIfAny();

        return new SeedPlan($context->scope, $prune->prune, $plans, $hasher->finish(), $this->warnings($documents, $context->scope));
    }

    /**
     * The valid items of a document in slices of `apply.batch_size` (document order). Invalid items and duplicate
     * keys go to the bag; `$seen` receives every key.
     *
     * @return Generator<int, list<SeedItem>>
     */
    public function slices(PreparedDocument $document, SeedContext $context, NaturalKeySet $seen, ErrorBag $errors): Generator
    {
        $slice    = [];
        $index    = 0;
        $provider = $document->provider->name()->value;
        foreach ($document->document->items() as $raw) {
            $item = $this->normaliser->normalise($document->schema, $index, $raw, $context);
            ++$index;
            if (!$item instanceof SeedItem) {
                $errors->add($item, $provider);

                continue;
            }
            if (!$seen->add($item->key)) {
                $errors->add([new RowError($item->index, $item->key, '', 'duplicate_key', 'seed_kit.row.duplicate_key')], $provider);

                continue;
            }
            $slice[] = $item;
            if (count($slice) >= $this->settings->batchSize) {
                yield $slice;
                $slice = [];
            }
        }
        if ($slice !== []) {
            yield $slice;
        }
    }

    /**
     * Asks the provider for the changes of one slice and re-checks them: one change per item, same key (`===` on the
     * canonical bytes) and index, no delete; sets the desired fingerprints. Provider errors go to the bag.
     *
     * @param list<SeedItem> $items
     *
     * @throws ProviderContractException
     *
     * @return list<RowChange>
     */
    public function planSlice(PreparedDocument $document, SeedContext $context, array $items, PlanOptions $options, ErrorBag $errors): array
    {
        $plan = $document->provider->planBatch($context, $items, $options);
        $errors->add($plan->errors, $document->provider->name()->value);
        if (count($plan->changes) !== count($items)) {
            throw new ProviderContractException();
        }
        $changes = [];
        foreach ($items as $position => $item) {
            $change = $plan->changes[$position] ?? null;
            if (!$change instanceof RowChange || $change->op === ChangeOp::Delete || $change->key->canonical !== $item->key->canonical || $change->index !== $item->index) {
                throw new ProviderContractException();
            }
            $changes[] = $change->withDesiredFingerprint(self::desiredFingerprint($item));
        }

        return $changes;
    }

    /**
     * The deletions of a document in slices (prune only), re-checked: op Delete, key not in the document, strictly
     * ascending canonical keys.
     *
     * @throws ProviderContractException
     *
     * @return Generator<int, list<RowChange>>
     */
    public function deletionSlices(PreparedDocument $document, SeedContext $context, NaturalKeySet $seen, PlanOptions $options): Generator
    {
        $slice    = [];
        $previous = null;
        foreach ($document->provider->planDeletions($context, $seen, $options) as $change) {
            if ($change->op !== ChangeOp::Delete || $seen->has($change->key) || ($previous !== null && strcmp($previous, $change->key->canonical) >= 0)) {
                throw new ProviderContractException();
            }
            $previous = $change->key->canonical;
            $slice[]  = $change->withDesiredFingerprint(null);
            if (count($slice) >= $this->settings->batchSize) {
                yield $slice;
                $slice = [];
            }
        }
        if ($slice !== []) {
            yield $slice;
        }
    }

    /**
     * Whether a slice holds a change that writes.
     *
     * @param list<RowChange> $changes
     */
    public static function anyWrite(array $changes): bool
    {
        foreach ($changes as $change) {
            if ($change->writes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * SHA-256 hex of the canonical normalised item.
     */
    public static function desiredFingerprint(SeedItem $item): string
    {
        return hash('sha256', $item->data === [] ? '{}' : CanonicalJson::encode($item->data));
    }

    /**
     * Adds a change to the preview (at most `plan.preview_rows` per operation), masking values the viewer may not see:
     * current values without `SEED_EXPORT`, and any secret value that is not a reference.
     *
     * @param list<RowChange> $preview
     */
    private function preview(array &$preview, RowChange $change, SeedSchema $schema, bool $maskCurrentValues): void
    {
        $count = 0;
        foreach ($preview as $kept) {
            if ($kept->op === $change->op) {
                ++$count;
            }
        }
        if ($count >= $this->settings->previewRows) {
            return;
        }
        $fields = [];
        foreach ($change->fields as $field) {
            $property = str_replace(['~1', '~0'], ['/', '~'], substr($field->path, 1));
            $secret   = $schema->isSecret($property);
            $fields[] = new FieldChange(
                $field->path,
                $maskCurrentValues || ($secret && !$this->isReferenceView($field->before)) ? $this->hideValue($field->before) : $field->before,
                $secret && !$this->isReferenceView($field->after) ? $this->hideValue($field->after) : $field->after,
            );
        }
        $preview[] = $change->withFields($fields);
    }

    /**
     * Hidden unless absent.
     */
    private function hideValue(FieldValueView $view): FieldValueView
    {
        return $view->kind === FieldValueView::ABSENT ? $view : FieldValueView::hidden();
    }

    /**
     * Whether a view holds a secret reference (or nothing).
     */
    private function isReferenceView(FieldValueView $view): bool
    {
        return $view->kind !== FieldValueView::VALUE || (is_string($view->value) && SecretReference::isReference($view->value));
    }
}
