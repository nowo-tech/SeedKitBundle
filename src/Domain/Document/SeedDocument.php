<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Document;

use Closure;

/**
 * One parsed document: its header and a factory of its raw items. The factory may be called more than once (dry run,
 * re-plan, apply); each call reads the items again (from memory for YAML / JSON, from the file for JSON Lines, so a
 * large file never sits in memory).
 */
final readonly class SeedDocument
{
    /**
     * @param Closure(): iterable<int, mixed> $items raw items in document order
     */
    public function __construct(public SeedDocumentHeader $header, private Closure $items)
    {
    }

    /**
     * The raw items, in document order.
     *
     * @return iterable<int, mixed>
     */
    public function items(): iterable
    {
        return ($this->items)();
    }
}
