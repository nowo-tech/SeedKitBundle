<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Application;

use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportDocument;
use Nowo\SeedKitBundle\Domain\Port\DocumentWriterInterface;

use function count;

/**
 * Keeps the exported document and drains its items (as a real writer would).
 */
final class CapturingWriter implements DocumentWriterInterface
{
    public ?ExportDocument $document = null;

    public ?DocumentFormat $format = null;

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public function write(ExportDocument $document, DocumentFormat $format, $stream): int
    {
        $this->document = $document;
        $this->format   = $format;
        $this->items    = [];
        foreach ($document->items as $item) {
            $this->items[] = $item;
        }

        return count($this->items);
    }
}
