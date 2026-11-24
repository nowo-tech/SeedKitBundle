<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only mapping of `<prefix>seed_run` (contract section 11), so the host's schema tool and migrations see the
 * table. The bundle never persists or flushes it: rows are written through DBAL ({@see \Nowo\SeedKitBundle\Infrastructure\Dbal\DbalRunStore}).
 * The XML mapping (`Resources/config/doctrine/SeedRun.orm.xml`) is the default; this attribute twin is used with
 * `doctrine.mapping: attribute`.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'seed_run')]
#[ORM\Index(name: 'seed_run_scope_idx', columns: ['scope_kind', 'scope_id', 'created_at'])]
#[ORM\Index(name: 'seed_run_status_idx', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'seed_run_actor_idx', columns: ['actor_ref'])]
class SeedRun
{
    /**
     * Mapping only; rows come from DBAL.
     *
     * @param array<string, int> $providers
     * @param list<string> $batchDigests
     * @param array<string, int> $counts
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: 'string', length: 36)]
        public readonly string $id,
        #[ORM\Column(name: 'scope_kind', type: 'string', length: 32)]
        public readonly string $scopeKind,
        #[ORM\Column(name: 'scope_id', type: 'string', length: 128, options: ['default' => ''])]
        public readonly string $scopeId,
        #[ORM\Column(name: 'actor_ref', type: 'string', length: 190, nullable: true)]
        public readonly ?string $actorRef,
        #[ORM\Column(name: 'channel', type: 'string', length: 16)]
        public readonly string $channel,
        #[ORM\Column(name: 'status', type: 'string', length: 24)]
        public readonly string $status,
        #[ORM\Column(name: 'providers', type: 'json')]
        public readonly array $providers,
        #[ORM\Column(name: 'prune', type: 'boolean')]
        public readonly bool $prune,
        #[ORM\Column(name: 'document_hash', type: 'string', length: 71, nullable: true)]
        public readonly ?string $documentHash,
        #[ORM\Column(name: 'plan_hash', type: 'string', length: 68)]
        public readonly string $planHash,
        #[ORM\Column(name: 'batch_digests', type: 'json')]
        public readonly array $batchDigests,
        #[ORM\Column(name: 'batches_done', type: 'integer')]
        public readonly int $batchesDone,
        #[ORM\Column(name: 'counts', type: 'json')]
        public readonly array $counts,
        /** @var resource|string|null */
        #[ORM\Column(name: 'document', type: 'blob', nullable: true)]
        public readonly mixed $document,
        #[ORM\Column(name: 'error_code', type: 'string', length: 16, nullable: true)]
        public readonly ?string $errorCode,
        #[ORM\Column(name: 'version', type: 'integer')]
        public readonly int $version,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        public readonly DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
        public readonly ?DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
        public readonly ?DateTimeImmutable $finishedAt,
    ) {
    }
}
