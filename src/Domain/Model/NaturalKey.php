<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Support\CanonicalJson;
use Stringable;

use function array_is_list;
use function count;
use function is_int;
use function is_string;

/**
 * The natural key of a row (contract section 5.1): an ordered tuple of 1–4 components, each an integer or an exact
 * string of at most 255 bytes. Never a database id. Equality is on the canonical bytes (`Bob` ≠ `bob` ≠ `bob `); a
 * provider that wants case folding declares an explicit key normaliser in its schema, applied before validation.
 */
final readonly class NaturalKey implements Stringable
{
    /** @var list<int|string> */
    public array $components;

    /** Canonical JSON array of the components. */
    public string $canonical;

    /**
     * @param list<int|string> $components
     *
     * @throws InvalidValueException
     */
    public function __construct(array $components)
    {
        if ($components === [] || count($components) > 4 || !array_is_list($components)) {
            throw new InvalidValueException('A natural key has 1 to 4 components.');
        }
        foreach ($components as $component) {
            if (is_int($component)) {
                continue;
            }
            if (!is_string($component) || !ExactText::isValid($component, 255)) {
                throw new InvalidValueException('Invalid natural key component.');
            }
        }
        $this->components = $components;
        $this->canonical  = CanonicalJson::encode($components);
    }

    /**
     * Whether both keys are the same canonical bytes.
     */
    public function equals(self $other): bool
    {
        return $this->canonical === $other->canonical;
    }

    /**
     * The canonical form.
     */
    public function __toString(): string
    {
        return $this->canonical;
    }
}
