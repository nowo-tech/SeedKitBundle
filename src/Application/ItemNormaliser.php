<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Normalizer;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Port\SchemaValidatorInterface;
use Nowo\SeedKitBundle\Domain\Port\SecretReferenceValidatorInterface;
use Nowo\SeedKitBundle\Domain\Provider\RowDiffer;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

use function array_is_list;
use function class_exists;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_string;
use function mb_strtolower;
use function strlen;

/**
 * Turns one raw item into a {@see SeedItem} (contract section 10.2 step 1): structure budget (depth, nodes, string
 * bytes, properties), floats only where the schema says `x-seed-float`, key normalisers, schema validation, secret
 * references (syntax, then the validator port: fail closed without one), read-only properties removed, natural key
 * built. Errors name the row, the pointer and the rule, never the value. Stateless.
 */
final readonly class ItemNormaliser
{
    /**
     * Builds the normaliser.
     */
    public function __construct(
        private SchemaValidatorInterface $validator,
        private SeedKitSettings $settings,
        private ?SecretReferenceValidatorInterface $secrets = null,
    ) {
    }

    /**
     * The item, or its errors.
     *
     * @return SeedItem|list<RowError>
     *
     * @throws DocumentReadException when the item breaks the structure budget
     */
    public function normalise(SeedSchema $schema, int $index, mixed $raw, SeedContext $context): SeedItem|array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return [new RowError($index, null, '', 'type', 'seed_kit.row.not_an_object')];
        }
        $nodes = 0;
        $this->budget($raw, 1, $nodes);
        $data   = [];
        $errors = [];
        foreach ($raw as $property => $value) {
            $property = (string) $property;
            if (!$this->numbersAllowed($value, $schema->allowsFloat($property))) {
                $errors[] = new RowError($index, null, RowDiffer::pointer($property), 'float_not_allowed', 'seed_kit.row.float_not_allowed');
            }
            $data[$property] = $value;
        }
        foreach ($schema->keyNormalisers() as $property => $mode) {
            if (is_string($data[$property] ?? null)) {
                $data[$property] = $mode === 'nfc' && class_exists(Normalizer::class) ? (string) Normalizer::normalize($data[$property], Normalizer::FORM_C) : ($mode === 'lower' ? mb_strtolower($data[$property], 'UTF-8') : $data[$property]);
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        $key = $this->key($schema, $data);
        foreach ($this->validator->validate($schema, $data, $this->settings->maxErrors) as $violation) {
            $errors[] = new RowError($index, $key, $violation['path'], $violation['rule'], 'seed_kit.row.schema_' . $violation['rule']);
        }
        if (!$key instanceof NaturalKey && $errors === []) {
            $errors[] = new RowError($index, null, '', 'key_invalid', 'seed_kit.row.key_invalid');
        }
        foreach ($schema->secretProperties() as $property) {
            if (!isset($data[$property])) {
                unset($data[$property]);

                continue;
            }
            $value = $data[$property];
            if (!is_string($value) || !SecretReference::isReference($value)) {
                $errors[] = new RowError($index, $key, RowDiffer::pointer($property), 'secret_plaintext', 'seed_kit.row.secret_plaintext');
            } elseif (!$this->secrets instanceof SecretReferenceValidatorInterface || !$this->secrets->isUsable(new SecretReference($value), $context->scope, $context->actor)) {
                $errors[] = new RowError($index, $key, RowDiffer::pointer($property), 'secret_refused', 'seed_kit.row.secret_refused');
            }
        }
        if ($errors !== [] || !$key instanceof NaturalKey) {
            return $errors;
        }
        foreach ($schema->readonlyProperties() as $property) {
            unset($data[$property]);
        }

        return new SeedItem($index, $key, $data);
    }

    /**
     * The natural key, or null when a key property is missing or not exact.
     *
     * @param array<string, mixed> $data
     */
    private function key(SeedSchema $schema, array $data): ?NaturalKey
    {
        $components = [];
        foreach ($schema->keyProperties() as $property) {
            $value = $data[$property] ?? null;
            if (!is_string($value) && !is_int($value)) {
                return null;
            }
            $components[] = $value;
        }
        try {
            return new NaturalKey($components);
        } catch (InvalidValueException) {
            return null;
        }
    }

    /**
     * Whether the value holds floats only where allowed (top-level `x-seed-float` property).
     */
    private function numbersAllowed(mixed $value, bool $floatAllowed): bool
    {
        if (is_float($value)) {
            return $floatAllowed && !is_nan($value) && !is_infinite($value);
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (!$this->numbersAllowed($entry, false)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Walks the item within the structure budget.
     *
     * @throws DocumentReadException
     */
    private function budget(mixed $value, int $depth, int &$nodes): void
    {
        ++$nodes;
        if ($nodes > $this->settings->maxNodes) {
            throw new DocumentReadException('too_many_nodes');
        }
        if (is_string($value) && strlen($value) > $this->settings->maxStringBytes) {
            throw new DocumentReadException('string_too_long');
        }
        if (!is_array($value)) {
            if ($value !== null && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
                throw new DocumentReadException('unsupported_value');
            }

            return;
        }
        if ($depth > $this->settings->maxDepth) {
            throw new DocumentReadException('too_deep');
        }
        if (count($value) > $this->settings->maxProperties && !array_is_list($value)) {
            throw new DocumentReadException('too_many_properties');
        }
        foreach ($value as $key => $entry) {
            if (is_string($key) && strlen($key) > $this->settings->maxStringBytes) {
                throw new DocumentReadException('string_too_long');
            }
            $this->budget($entry, $depth + 1, $nodes);
        }
    }
}
