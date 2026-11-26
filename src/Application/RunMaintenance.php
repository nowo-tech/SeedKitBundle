<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Psr\Clock\ClockInterface;

/**
 * Run history maintenance (contract sections 11 and 14): list, purge of finished runs older than `runs.history_days`
 * (keyset batches of 500), and `actor:forget` (actor refs nulled, history kept). Stateless.
 */
final readonly class RunMaintenance
{
    /** Rows per purge / forget batch. */
    public const BATCH = 500;

    /**
     * Builds the maintenance service.
     */
    public function __construct(private RunStoreInterface $runs, private ClockInterface $clock, private int $historyDays = 365, private int $leaseSeconds = 300)
    {
    }

    /**
     * The newest runs.
     *
     * @return list<RunRecord>
     */
    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null): array
    {
        return $this->runs->list($scope, $status, $limit, $before);
    }

    /**
     * The default purge limit: now minus `runs.history_days`.
     */
    public function defaultBefore(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now())->modify('-' . $this->historyDays . ' days');
    }

    /**
     * Ends the stored documents past `runs.document_ttl` (0.2 UI runs): `planned` / `queued` runs become `expired`, a
     * `partially_applied` run loses its document, a `running` run without progress for longer than the lease (a killed
     * request or worker) ends too. Returns the rows changed.
     */
    public function expire(): int
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        return $this->runs->expire($now, self::BATCH, $now->modify('-' . $this->leaseSeconds . ' seconds'));
    }

    /**
     * Deletes (or counts, on a dry run) the finished runs created before the instant.
     */
    public function purge(DateTimeImmutable $before, bool $dryRun): int
    {
        return $dryRun ? $this->runs->countPurgeable($before) : $this->runs->purge($before, self::BATCH);
    }

    /**
     * Nulls the actor of every run of `$actor`.
     */
    public function forgetActor(ActorRef $actor): int
    {
        return $this->runs->forgetActor($actor, self::BATCH);
    }
}
