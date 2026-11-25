<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Event\SeedExportedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunPlannedEvent;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeNotFoundException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\PendingKeys;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Port\SeedAccessGateInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedAuditSinkInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedScopeResolverInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * The application entry points (console in 0.1, UI and Messenger in 0.2): every call resolves the scope through the
 * host resolver (unknown or invisible ⇒ `ACCESS_001`), re-checks access for each provider (`SEED_EXPORT`,
 * `SEED_APPLY`, `SEED_PRUNE` with prune; denied ⇒ `ACCESS_002`), refuses the system actor outside the console
 * (QB-SK-5), and audits what Q-SK-11 asks: every apply and prune (all outcomes), every current export of a provider
 * with personal-data or secret fields; other exports are only logged at `info`. Stateless.
 */
final readonly class SeedOperations
{
    /**
     * Builds the operations.
     */
    public function __construct(
        private ProviderRegistry $registry,
        private Planner $planner,
        private Applier $applier,
        private Exporter $exporter,
        private SchemaPublisher $publisher,
        private SeedScopeResolverInterface $scopes,
        private SeedAccessGateInterface $gate,
        private SeedAuditSinkInterface $audit,
        private SeedKitSettings $settings,
        private ?LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * The providers available in a scope kind (all for null), in dependency order.
     *
     * @return list<SeedProviderInterface>
     */
    public function providers(?SeedScope $scope = null): array
    {
        return $this->registry->all($scope?->kind);
    }

    /**
     * The document schema of a provider (contract section 7.1).
     *
     * @throws UnknownProviderException
     *
     * @return array<string, mixed>
     */
    public function schema(ProviderName $provider): array
    {
        return $this->publisher->documentSchema($this->registry->get($provider));
    }

    /**
     * Resolves and checks the target scope for the actor.
     *
     * @throws ScopeNotFoundException when the kind is not configured, the id is missing or extra, or the host resolver does not show the scope
     * @throws AccessDeniedException when the system actor is used outside the console
     */
    public function resolveScope(SeedScope $requested, ActorRef $actor, Channel $channel): SeedScope
    {
        if ($actor->isSystem() && $channel !== Channel::Console) {
            throw new AccessDeniedException();
        }
        if (!$this->settings->knowsKind($requested->kind) || $this->settings->requiresId($requested->kind) !== $requested->id instanceof ScopeId) {
            throw new ScopeNotFoundException();
        }
        $resolved = $this->scopes->resolve($requested, $actor, $channel);
        if (!$resolved instanceof SeedScope || !$resolved->equals($requested)) {
            throw new ScopeNotFoundException();
        }

        return $resolved;
    }

    /**
     * Exports a provider to the stream; returns the number of items.
     *
     * @param resource $stream
     *
     * @throws SeedKitException
     */
    public function export(ProviderName $name, SeedScope $requested, ExportMode $mode, DocumentFormat $format, ActorRef $actor, Channel $channel, $stream): int
    {
        $scope    = $this->resolveScope($requested, $actor, $channel);
        $provider = $this->registry->getForKind($name, $scope->kind);
        if (!$this->gate->isGranted(SeedAction::Export, $name, $scope, $channel, $actor)) {
            throw new AccessDeniedException();
        }
        $rows = $this->exporter->export($provider, new SeedContext($scope, $actor, $channel), $mode, $format, $stream);
        $this->dispatcher?->dispatch(new SeedExportedEvent($name, $scope, $mode, $rows, $actor));
        if ($mode === ExportMode::Current && $provider->schema()->isSensitive()) {
            $this->audit->record(new SeedAuditRecord(SeedAuditRecord::EXPORT, $actor, $scope, [$name->value], ['rows' => $rows], $channel, SeedAuditRecord::OUTCOME_SUCCESS));
        } else {
            $this->logger?->info('Seed export of {provider} in {scope} ({mode}, {rows} rows).', ['provider' => $name->value, 'scope' => $scope->key(), 'mode' => $mode->value, 'rows' => $rows]);
        }

        return $rows;
    }

    /**
     * The dry run: the plan of a document set (nothing stored or written).
     *
     * @throws SeedKitException
     */
    public function diff(DocumentSet $set, SeedScope $requested, ActorRef $actor, Channel $channel, PruneOptions $prune): SeedPlan
    {
        [$documents, $context] = $this->prepare($set, $requested, $actor, $channel, $prune);
        $plan                  = $this->planner->plan($documents, $context, $prune, !$context->canSeeCurrentValues);
        $providers             = [];
        foreach ($plan->documents as $document) {
            $providers[] = $document->provider->value;
        }
        $this->dispatcher?->dispatch(new SeedRunPlannedEvent($providers, $context->scope, $plan->hash, $plan->changes(), $actor));

        return $plan;
    }

    /**
     * Applies a document set (re-planned; `$expected` refuses a stale plan). Audited with every outcome.
     *
     * @throws SeedKitException
     */
    public function apply(DocumentSet $set, SeedScope $requested, ActorRef $actor, Channel $channel, PruneOptions $prune, ?PlanHash $expected = null): ApplyResult
    {
        $action = $prune->prune ? SeedAuditRecord::PRUNE : SeedAuditRecord::APPLY;
        try {
            [$documents, $context] = $this->prepare($set, $requested, $actor, $channel, $prune);
        } catch (SeedKitException $e) {
            // Refused before planning (scope not found, access denied, unknown provider): audited too (every outcome).
            $named = [];
            foreach ($set->documents as $document) {
                $named[] = $document->header->provider->value;
            }
            $this->audit->record(new SeedAuditRecord($action, $actor, $requested, $named, [], $channel, $e->errorCode(), $set->hash->value, $expected?->value));

            throw $e;
        }
        $providers = [];
        foreach ($documents as $document) {
            $providers[] = $document->provider->name()->value;
        }
        try {
            $result = $this->applier->apply($set, $documents, $context, $prune, $expected);
        } catch (SeedKitException $e) {
            $this->audit->record(new SeedAuditRecord($action, $actor, $context->scope, $providers, [], $channel, $e->errorCode(), $set->hash->value, $expected?->value));

            throw $e;
        }
        $this->audit->record(new SeedAuditRecord(
            $action,
            $actor,
            $context->scope,
            $providers,
            [
                ChangeOp::Create->value => $result->plan->count(ChangeOp::Create),
                ChangeOp::Update->value => $result->plan->count(ChangeOp::Update),
                ChangeOp::Delete->value => $result->plan->count(ChangeOp::Delete),
            ],
            $channel,
            SeedAuditRecord::OUTCOME_SUCCESS,
            $set->hash->value,
            $result->plan->hash->value,
            $result->runId?->value,
        ));

        return $result;
    }

    /**
     * Scope, documents, access and the run context.
     *
     * @throws SeedKitException
     *
     * @return array{0: list<PreparedDocument>, 1: SeedContext}
     */
    private function prepare(DocumentSet $set, SeedScope $requested, ActorRef $actor, Channel $channel, PruneOptions $prune): array
    {
        $scope     = $this->resolveScope($requested, $actor, $channel);
        $documents = $this->planner->prepare($set, $scope);
        $seeAll    = true;
        foreach ($documents as $document) {
            $name = $document->provider->name();
            if (!$this->gate->isGranted(SeedAction::Apply, $name, $scope, $channel, $actor)
                || ($prune->prune && !$this->gate->isGranted(SeedAction::Prune, $name, $scope, $channel, $actor))) {
                throw new AccessDeniedException();
            }
            $seeAll = $seeAll && $this->gate->isGranted(SeedAction::Export, $name, $scope, $channel, $actor);
        }

        return [$documents, new SeedContext($scope, $actor, $channel, null, new PendingKeys(), $prune->prune, $seeAll)];
    }
}
