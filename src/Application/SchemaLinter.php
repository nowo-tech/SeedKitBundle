<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Normalizer;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;

use function array_is_list;
use function array_key_exists;
use function class_exists;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * Lints a provider schema (contract section 7.3): run by the cache warmer, `nowo:seed-kit:lint` and the contract test
 * kit. Fails on: no or bad `x-seed-key` (1–4 required string / integer properties), an object without
 * `additionalProperties: false`, a `$ref` that is not local, `$dynamicRef` / `$vocabulary`, the `regex` format, an
 * `x-seed-ref` to a provider outside `dependsOn`, a bad key normaliser, a secret-looking property name without an
 * explicit `x-seed-secret`, a secret that is also part of the key. Warns on unbounded strings and properties without a
 * description. Stateless.
 */
final readonly class SchemaLinter
{
    /** Property names that must declare `x-seed-secret` explicitly. */
    private const SECRET_NAME = '/(pass(word|phrase)?|secret|token|api[_-]?key|private[_-]?key|credential|iban|card)/i';

    /**
     * The problems of the provider's schema: `errors` fail the lint, `warnings` are printed.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function lint(SeedProviderInterface $provider): array
    {
        $schema   = $provider->schema();
        $errors   = [];
        $warnings = [];
        $item     = $schema->item;
        if (($item['type'] ?? null) !== 'object') {
            $errors[] = 'The item schema must have "type": "object".';
        }
        $this->walk($item, '#', $errors, $warnings);

        $properties = $schema->properties();
        $keys       = $schema->keyProperties();
        if ($keys === [] || count($keys) > 4) {
            $errors[] = 'x-seed-key must list 1 to 4 properties.';
        }
        foreach ($keys as $key) {
            $type = $properties[$key]['type'] ?? null;
            if (!isset($properties[$key]) || !$schema->isRequired($key) || !in_array($type, ['string', 'integer'], true)) {
                $errors[] = sprintf('Key property "%s" must be a required string or integer property.', $key);
            }
            if ($schema->isSecret($key) || $schema->isReadonly($key)) {
                $errors[] = sprintf('Key property "%s" cannot be secret or read-only.', $key);
            }
        }
        foreach ($schema->keyNormalisers() as $property => $mode) {
            if (!in_array($property, $keys, true) || !in_array($mode, ['lower', 'nfc'], true) || ($mode === 'nfc' && !class_exists(Normalizer::class))) {
                $errors[] = sprintf('x-seed-key-normalise of "%s" must be "lower" or "nfc" (ext-intl) on a key property.', $property);
            }
        }
        $depends = [];
        foreach ($provider->dependsOn() as $dependency) {
            $depends[] = $dependency->value;
        }
        foreach ($schema->references() as $property => $target) {
            if (!isset($properties[$property]) || !in_array($target, $depends, true)) {
                $errors[] = sprintf('x-seed-ref of "%s" must point at a provider listed in dependsOn().', $property);
            }
        }
        foreach ($properties as $name => $definition) {
            if (preg_match(self::SECRET_NAME, $name) === 1 && !array_key_exists('x-seed-secret', $definition)) {
                $errors[] = sprintf('Property "%s" looks like a secret: declare "x-seed-secret": true or false.', $name);
            }
            if (($definition['x-seed-omit-means'] ?? 'keep') !== 'keep' && ($definition['x-seed-omit-means'] ?? null) !== 'clear') {
                $errors[] = sprintf('x-seed-omit-means of "%s" must be "keep" or "clear".', $name);
            }
            if (!is_string($definition['description'] ?? null) || $definition['description'] === '') {
                $warnings[] = sprintf('Property "%s" has no description (an AI editing the file reads it).', $name);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Walks every sub-schema.
     *
     * @param array<mixed> $node
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function walk(array $node, string $path, array &$errors, array &$warnings): void
    {
        if (isset($node['$ref']) && (!is_string($node['$ref']) || !str_starts_with($node['$ref'], '#/'))) {
            $errors[] = sprintf('%s: only local "$ref" (#/...) is allowed.', $path);
        }
        foreach (['$dynamicRef', '$vocabulary', '$recursiveRef'] as $keyword) {
            if (isset($node[$keyword])) {
                $errors[] = sprintf('%s: "%s" is not allowed.', $path, $keyword);
            }
        }
        if (($node['format'] ?? null) === 'regex') {
            $errors[] = sprintf('%s: the "regex" format is disabled; use "pattern".', $path);
        }
        $type = $node['type'] ?? null;
        if (($type === 'object' || (is_array($type) && in_array('object', $type, true))) && ($node['additionalProperties'] ?? null) !== false) {
            $errors[] = sprintf('%s: objects need "additionalProperties": false.', $path);
        }
        if (($type === 'string' || (is_array($type) && in_array('string', $type, true))) && !isset($node['maxLength']) && !isset($node['enum']) && !isset($node['const']) && !isset($node['format'])) {
            $warnings[] = sprintf('%s: string without "maxLength" (the reader cap applies).', $path);
        }
        foreach ($node as $key => $child) {
            if (is_array($child) && $key !== 'examples' && $key !== 'enum' && $key !== 'const' && $key !== 'default' && $key !== 'required' && $key !== 'x-seed-key' && $key !== 'x-seed-key-normalise' && $key !== 'x-seed-ref') {
                if (array_is_list($child)) {
                    foreach ($child as $position => $entry) {
                        if (is_array($entry)) {
                            $this->walk($entry, $path . '/' . $key . '/' . $position, $errors, $warnings);
                        }
                    }
                } else {
                    $this->walk($child, $path . '/' . $key, $errors, $warnings);
                }
            }
        }
    }
}
