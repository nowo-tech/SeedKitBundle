<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Exception\RunNotAvailableException;

/**
 * Stores the parsed document of a UI run between the dry run and the apply (contract F5, section 11, Q-SK-5): the
 * normalised JSON form of the parsed items (never the raw upload), compressed. Plaintext secrets never reach it: they
 * are refused while planning, before the run is stored.
 */
interface RunDocumentCodecInterface
{
    /**
     * The stored form of a document set.
     */
    public function encode(DocumentSet $set): string;

    /**
     * The document set back from its stored form (same headers, items, format and raw-bytes hash).
     *
     * @throws RunNotAvailableException when the stored form is unreadable
     */
    public function decode(string $stored): DocumentSet;
}
