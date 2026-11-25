<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Document;

/**
 * Document formats (contract section 8). YAML is the default; JSON Lines is the only streamed format (Q-SK-4).
 */
enum DocumentFormat: string
{
    case Yaml      = 'yaml';
    case Json      = 'json';
    case JsonLines = 'jsonl';

    /**
     * The file extension after `.seed.`.
     */
    public function extension(): string
    {
        return $this->value;
    }

    /**
     * The media type of a download.
     */
    public function mediaType(): string
    {
        return match ($this) {
            self::Yaml      => 'application/yaml',
            self::Json      => 'application/json',
            self::JsonLines => 'application/jsonl',
        };
    }
}
