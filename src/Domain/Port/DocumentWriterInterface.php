<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportDocument;

/**
 * Writes an export to a stream, item by item (YAML / JSON / JSON Lines, contract section 8).
 */
interface DocumentWriterInterface
{
    /**
     * Writes the document; returns the number of items written.
     *
     * @param resource $stream an open writable stream (closed by the caller)
     */
    public function write(ExportDocument $document, DocumentFormat $format, $stream): int;
}
