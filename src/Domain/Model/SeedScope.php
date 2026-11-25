<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function explode;
use function str_contains;

/**
 * The target scope of an export or a run: a kind and, for kinds that require one, an id. Built from the route or
 * `--scope=kind[:id]` and resolved by the host (never from the document).
 */
final readonly class SeedScope implements Stringable
{
    /**
     * Builds a scope.
     */
    public function __construct(public ScopeKind $kind, public ?ScopeId $id = null)
    {
    }

    /**
     * Parses `kind` or `kind:id` (the id may itself contain colons).
     *
     * @throws InvalidValueException
     */
    public static function parse(string $value): self
    {
        if (!str_contains($value, ':')) {
            return new self(new ScopeKind($value));
        }
        [$kind, $id] = explode(':', $value, 2);

        return new self(new ScopeKind($kind), new ScopeId($id));
    }

    /**
     * The platform scope (no id).
     */
    public static function platform(): self
    {
        return new self(new ScopeKind(ScopeKind::PLATFORM));
    }

    /**
     * `kind` or `kind:id`.
     */
    public function key(): string
    {
        return $this->id instanceof ScopeId ? $this->kind->value . ':' . $this->id->value : $this->kind->value;
    }

    /**
     * The id as stored in the bundle tables ('' for no id).
     */
    public function storedId(): string
    {
        return $this->id instanceof ScopeId ? $this->id->value : '';
    }

    /**
     * Whether both scopes are the same kind and id, byte for byte.
     */
    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /**
     * {@see key()}.
     */
    public function __toString(): string
    {
        return $this->key();
    }
}
