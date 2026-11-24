<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

use Countable;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;

use function count;

/**
 * A set of natural keys compared on their canonical bytes. Built per run while planning (the keys a document mentions,
 * the keys an earlier document creates) and never kept in a service.
 */
final class NaturalKeySet implements Countable
{
    /** @var array<string, true> */
    private array $keys = [];

    /**
     * Adds a key; returns false when it was already there.
     */
    public function add(NaturalKey $key): bool
    {
        if (isset($this->keys[$key->canonical])) {
            return false;
        }
        $this->keys[$key->canonical] = true;

        return true;
    }

    /**
     * Whether the set holds the key.
     */
    public function has(NaturalKey $key): bool
    {
        return isset($this->keys[$key->canonical]);
    }

    /**
     * The number of keys.
     */
    public function count(): int
    {
        return count($this->keys);
    }
}
