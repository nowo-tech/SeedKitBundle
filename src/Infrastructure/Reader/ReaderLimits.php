<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Reader;

/**
 * The reader caps of one channel (contract section 13.3, configuration `reader.*`): bytes of the input, bytes a
 * YAML / JSON tree may have (above: JSON Lines only), items per document, documents per file, JSON depth, bytes of a
 * JSON Lines line.
 */
final readonly class ReaderLimits
{
    /**
     * Builds the caps.
     */
    public function __construct(
        public int $maxBytes = 8388608,
        public int $maxTreeBytes = 4194304,
        public int $maxItems = 10000,
        public int $maxDocuments = 20,
        public int $maxDepth = 16,
        public int $maxLineBytes = 1048576,
    ) {
    }
}
