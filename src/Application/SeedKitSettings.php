<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Model\ScopeKind;

/**
 * The processed settings the application layer needs (contract section 15). Immutable configuration only.
 */
final readonly class SeedKitSettings
{
    /**
     * @param array<string, bool> $scopeKinds kind → requires an id
     * @param int $batchSize rows per batch transaction (1..5000)
     * @param int $leaseSeconds lease TTL, renewed per batch
     * @param int $maxErrors row errors kept in a report
     * @param int $previewRows changes kept per operation in a plan preview
     * @param float $pruneMaxRatio share of current rows a prune may delete without confirmation
     * @param string $generator `meta.generator` of exports
     * @param int $maxDepth nesting depth of an item
     * @param int $maxNodes nodes of one item
     * @param int $maxStringBytes bytes of one string
     * @param int $maxProperties properties of one object
     * @param int $documentTtl seconds a UI run keeps its document (`runs.document_ttl`)
     * @param int $maxSyncChanges rows a web request may apply (`ui.max_sync_changes`)
     * @param int $asyncThreshold rows above which a UI apply is queued when Messenger is enabled
     */
    public function __construct(
        public array $scopeKinds = [ScopeKind::PLATFORM => false],
        public int $batchSize = 500,
        public int $leaseSeconds = 300,
        public int $maxErrors = 200,
        public int $previewRows = 200,
        public float $pruneMaxRatio = 0.5,
        public string $generator = 'seed-kit',
        public int $maxDepth = 16,
        public int $maxNodes = 10000,
        public int $maxStringBytes = 65536,
        public int $maxProperties = 256,
        public int $documentTtl = 3600,
        public int $maxSyncChanges = 2000,
        public int $asyncThreshold = 2000,
    ) {
    }

    /**
     * Whether the kind is configured.
     */
    public function knowsKind(ScopeKind $kind): bool
    {
        return isset($this->scopeKinds[$kind->value]);
    }

    /**
     * Whether scopes of the kind need an id.
     */
    public function requiresId(ScopeKind $kind): bool
    {
        return $this->scopeKinds[$kind->value] ?? true;
    }
}
