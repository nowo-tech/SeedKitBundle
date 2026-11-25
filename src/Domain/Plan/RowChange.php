<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

use Nowo\SeedKitBundle\Domain\Model\NaturalKey;

/**
 * One row of a plan (contract section 5.3). Built by providers with the named constructors; the bundle sets the
 * desired fingerprint ({@see withDesiredFingerprint()}) and re-checks the key against the requested items.
 */
final readonly class RowChange
{
    /**
     * @param int|null $index item index in the document (null for a delete)
     * @param list<FieldChange> $fields update: changed fields only; create: the set fields; delete / unchanged: none
     * @param string|null $currentFingerprint provider-supplied: a version column or the SHA-256 of the canonical current values (null for a create)
     * @param string|null $desiredFingerprint SHA-256 of the canonical normalised item (null for a delete)
     */
    public function __construct(
        public ChangeOp $op,
        public NaturalKey $key,
        public ?int $index,
        public array $fields = [],
        public ?string $currentFingerprint = null,
        public ?string $desiredFingerprint = null,
    ) {
    }

    /**
     * A row to create.
     *
     * @param list<FieldChange> $fields
     */
    public static function create(NaturalKey $key, int $index, array $fields = []): self
    {
        return new self(ChangeOp::Create, $key, $index, $fields);
    }

    /**
     * A row to update.
     *
     * @param list<FieldChange> $fields the changed fields
     */
    public static function update(NaturalKey $key, int $index, array $fields, string $currentFingerprint): self
    {
        return new self(ChangeOp::Update, $key, $index, $fields, $currentFingerprint);
    }

    /**
     * A row that already matches.
     */
    public static function unchanged(NaturalKey $key, int $index, string $currentFingerprint): self
    {
        return new self(ChangeOp::Unchanged, $key, $index, [], $currentFingerprint);
    }

    /**
     * A row to delete (prune).
     */
    public static function delete(NaturalKey $key, string $currentFingerprint): self
    {
        return new self(ChangeOp::Delete, $key, null, [], $currentFingerprint);
    }

    /**
     * The same change with the desired fingerprint set by the bundle.
     */
    public function withDesiredFingerprint(?string $fingerprint): self
    {
        return new self($this->op, $this->key, $this->index, $this->fields, $this->currentFingerprint, $fingerprint);
    }

    /**
     * The same change with other field views (diff masking).
     *
     * @param list<FieldChange> $fields
     */
    public function withFields(array $fields): self
    {
        return new self($this->op, $this->key, $this->index, $fields, $this->currentFingerprint, $this->desiredFingerprint);
    }

    /**
     * Whether applying it writes something.
     */
    public function writes(): bool
    {
        return $this->op !== ChangeOp::Unchanged;
    }
}
