<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Document;

use Nowo\SeedKitBundle\Domain\Model\DocumentHash;

/**
 * Every document of one uploaded file (a multi-document YAML stream or a `documents` JSON object is one run with
 * several providers), the format and the hash of the raw bytes.
 */
final readonly class DocumentSet
{
    /**
     * @param list<SeedDocument> $documents in file order (the planner sorts them by dependency)
     */
    public function __construct(public array $documents, public DocumentFormat $format, public DocumentHash $hash)
    {
    }
}
