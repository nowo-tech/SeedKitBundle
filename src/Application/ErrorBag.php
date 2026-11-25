<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\SecretValueRefusedException;
use Nowo\SeedKitBundle\Domain\Plan\RowError;

use function count;
use function str_starts_with;

/**
 * The row errors of one plan, capped at `validation.max_errors`. Per plan, never shared.
 */
final class ErrorBag
{
    /** @var list<RowError> */
    private array $errors = [];

    private bool $truncated = false;

    private bool $secret = false;

    /**
     * Builds an empty bag.
     */
    public function __construct(private readonly int $max)
    {
    }

    /**
     * Adds errors (over the cap only the truncation is remembered).
     *
     * @param list<RowError> $errors
     */
    public function add(array $errors, ?string $provider = null): void
    {
        foreach ($errors as $error) {
            if (str_starts_with($error->rule, 'secret_')) {
                // @igor-ignore - per-plan error collection created per call, never a shared service.
                $this->secret = true;
            }
            if (count($this->errors) >= $this->max) {
                // @igor-ignore - per-plan error collection created per call, never a shared service.
                $this->truncated = true;

                continue;
            }
            // @igor-ignore - per-plan error collection created per call, never a shared service.
            $this->errors[] = $provider !== null ? $error->forProvider($provider) : $error;
        }
    }

    /**
     * Whether the bag holds errors.
     */
    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * Throws the matching exception when errors were collected.
     *
     * @throws SecretValueRefusedException when a secret rule failed
     * @throws DocumentInvalidException otherwise
     */
    public function throwIfAny(): void
    {
        if ($this->errors === []) {
            return;
        }
        if ($this->secret) {
            throw new SecretValueRefusedException($this->errors, $this->truncated);
        }

        throw new DocumentInvalidException($this->errors, $this->truncated);
    }
}
