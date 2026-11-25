<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_002: a value object refused its input (identifier rules of contract section 5.1). The message is a static
 * description of the rule chosen by the caller, never the refused value.
 */
final class InvalidValueException extends SeedKitException
{
    /**
     * @param string $rule static text naming the broken rule (e.g. "Invalid provider name.")
     */
    public function __construct(string $rule)
    {
        parent::__construct($rule);
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return 'SEED_002';
    }

    /**
     * The rule text is specific to the call site: it is shown as is (no generic translation).
     */
    public function messageKey(): string
    {
        return 'seed_kit.invalid_value';
    }
}
