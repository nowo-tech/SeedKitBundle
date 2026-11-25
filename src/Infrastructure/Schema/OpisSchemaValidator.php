<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Schema;

use Nowo\SeedKitBundle\Domain\Port\SchemaValidatorInterface;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Symfony\Contracts\Service\ResetInterface;

use function count;
use function implode;
use function ini_get;
use function ini_set;
use function is_object;
use function is_scalar;
use function str_replace;

/**
 * The default schema validator (Q-SK-2): opis/json-schema, draft 2020-12, **no resolver** (only local `#/...`
 * references resolve; nothing is ever fetched or read from disk), JSON strings not decoded, `pattern` run under a
 * reduced `pcre.backtrack_limit` restored afterwards. Reports the JSON Pointer and keyword of each leaf error, never
 * the value.
 *
 * The compiled schemas are kept in a per-process map keyed by schema fingerprint (immutable data, one entry per
 * provider version, bounded by `MAX_SCHEMAS`): the only memo of the bundle, emptied by {@see reset()} (`kernel.reset`).
 */
final class OpisSchemaValidator implements SchemaValidatorInterface, ResetInterface
{
    /** Compiled schemas kept at most. */
    public const MAX_SCHEMAS = 64;

    /** Backtrack limit during validation. */
    public const BACKTRACK_LIMIT = 100000;

    private Validator $validator;

    /** @var array<string, Schema> */
    private array $compiled = [];

    /**
     * Builds the validator.
     */
    public function __construct()
    {
        $this->validator = $this->newValidator();
    }

    /**
     * {@inheritDoc}
     */
    public function validate(SeedSchema $schema, array $item, int $maxErrors): array
    {
        $fingerprint = $schema->fingerprint();
        if (!isset($this->compiled[$fingerprint])) {
            if (count($this->compiled) >= self::MAX_SCHEMAS) {
                $this->reset();
            }
            $definition = Helper::toJSON(['$id' => 'urn:nowo-seed-item:' . $fingerprint] + $schema->item);
            // @igor-ignore - bounded per-process cache of immutable compiled schemas, emptied by reset() (kernel.reset).
            $this->compiled[$fingerprint] = $this->validator->loader()->loadObjectSchema(is_object($definition) ? $definition : (object) []);
        }
        $this->validator->setMaxErrors($maxErrors);
        $limit = ini_get('pcre.backtrack_limit');
        // @igor-ignore - pcre.backtrack_limit is lowered for this call only and restored in finally.
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        try {
            $result = $this->validator->validate(Helper::toJSON($item), $this->compiled[$fingerprint]);
        } finally {
            // @igor-ignore - pcre.backtrack_limit is lowered for this call only and restored in finally.
            ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
        }
        $error = $result->error();
        if (!$error instanceof ValidationError) {
            return [];
        }
        $violations = [];
        $this->leaves($error, $violations, $maxErrors);

        return $violations;
    }

    /**
     * Forgets the compiled schemas.
     */
    public function reset(): void
    {
        // @igor-ignore - resets the bounded schema cache.
        $this->compiled = [];
        // @igor-ignore - a new loader drops opis' own schema cache too.
        $this->validator = $this->newValidator();
    }

    /**
     * A validator without a resolver.
     */
    private function newValidator(): Validator
    {
        return new Validator(new SchemaLoader(new SchemaParser(), null, false), 1, false);
    }

    /**
     * Collects the leaf errors.
     *
     * @param list<array{path: string, rule: string}> $violations
     */
    private function leaves(ValidationError $error, array &$violations, int $max): void
    {
        $children = $error->subErrors();
        if ($children === []) {
            if (count($violations) < $max) {
                $segments = [];
                foreach ($error->data()->fullPath() as $segment) {
                    $segments[] = str_replace(['~', '/'], ['~0', '~1'], is_scalar($segment) ? (string) $segment : '');
                }
                $violations[] = ['path' => $segments === [] ? '' : '/' . implode('/', $segments), 'rule' => $error->keyword()];
            }

            return;
        }
        foreach ($children as $child) {
            if ($child instanceof ValidationError) {
                $this->leaves($child, $violations, $max);
            }
        }
    }
}
