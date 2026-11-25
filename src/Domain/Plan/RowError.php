<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

use Nowo\SeedKitBundle\Domain\Model\NaturalKey;

/**
 * A plan-time error of one row (schema, secret, duplicate key, provider domain rule). Names the row, the JSON Pointer
 * and the rule, never the submitted value. The natural key is shown to the uploader only, never logged.
 */
final readonly class RowError
{
    /**
     * @param int $index item index in the document (-1 for a document-level error)
     * @param string $path JSON Pointer inside the item ('' for the whole item)
     * @param string $rule machine rule (`required`, `type`, `enum`, `duplicate_key`, `secret_plaintext`, …)
     * @param string $messageKey translation key in the `NowoSeedKitBundle` domain
     * @param array<string, scalar> $messageParams never the submitted value
     */
    public function __construct(
        public int $index,
        public ?NaturalKey $key,
        public string $path,
        public string $rule,
        public string $messageKey,
        public array $messageParams = [],
        public ?string $provider = null,
    ) {
    }

    /**
     * The same error attributed to a provider.
     */
    public function forProvider(string $provider): self
    {
        return new self($this->index, $this->key, $this->path, $this->rule, $this->messageKey, $this->messageParams, $provider);
    }
}
