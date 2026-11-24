<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_002: the document cannot be read (size and structure caps, parse error, YAML tag / anchor / alias / merge key,
 * duplicate key, unknown top-level key, encoding, multi-document rules). {@see $reason} is a static machine key; the
 * optional line number points at the offending line without quoting it.
 */
final class DocumentReadException extends SeedKitException
{
    /**
     * @param string $reason static reason key (e.g. `too_large`, `yaml_alias`, `duplicate_key`)
     * @param int|null $lineNumber 1-based line of the problem when known
     */
    public function __construct(public readonly string $reason, public readonly ?int $lineNumber = null)
    {
        parent::__construct('The seed document cannot be read (' . $reason . ($lineNumber !== null ? ', line ' . $lineNumber : '') . ').');
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return 'SEED_002';
    }

    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'seed_kit.read.' . $this->reason;
    }
}
