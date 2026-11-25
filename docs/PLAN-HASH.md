# Plan hash and batch digests

## Table of contents

- [Why](#why)
- [Canonical JSON](#canonical-json)
- [Fingerprints](#fingerprints)
- [Change line](#change-line)
- [Plan hash](#plan-hash)
- [Batch digests](#batch-digests)
- [Golden vectors](#golden-vectors)
  - [Vector 1: one create, platform scope](#vector-1-one-create-platform-scope)
  - [Vector 2: update, unchanged and delete with prune](#vector-2-update-unchanged-and-delete-with-prune)
  - [Vector 3: an empty run](#vector-3-an-empty-run)
  - [Canonical JSON vectors](#canonical-json-vectors)
- [Recompute it yourself](#recompute-it-yourself)

Implementation: `Nowo\SeedKitBundle\Application\PlanHasher` and `Nowo\SeedKitBundle\Domain\Support\CanonicalJson`.
The vectors below were computed with those classes and re-checked with plain `hash('sha256', …)`.

## Why

The dry run prints a **plan hash**. `apply --expect-hash=<hash>` re-plans the whole run and refuses (`SEED_005`) when
the hash differs, so what is applied is exactly what was reviewed. Inside each batch transaction the slice is
re-planned with the write lock and compared with its **batch digest** (`SEED_006` on mismatch), which closes the
window between the re-plan and the writes. Unchanged rows are part of both, so a row edited after the dry run turns
`N` into `U` and changes the hash. The actor is not in the hash; the scope, the prune flag, the provider versions and
schemas are.

## Canonical JSON

RFC 8785 rules:

- object keys sorted by their **UTF-16 code units** (not by UTF-8 bytes), no whitespace;
- strings with minimal escaping (`/` and non-ASCII characters not escaped, U+2028 / U+2029 not escaped);
- integers as they are; floats as ECMAScript `Number::toString` writes them: shortest round-trip digits, fixed
  notation for 1e-7 < |x| < 1e21 and exponent notation otherwise (`1`, `0.5`, `0.000001`, `100000000000000000000`,
  `1e+21`, `1e-7`, `-0` written `0`; NaN and infinities refused); the digits come from `sprintf`, never from the
  `precision` / `serialize_precision` ini, so CLI and FPM hash alike whatever their php.ini;
- `null`, `true`, `false` kept;
- a PHP list is a JSON array, any other array a JSON object; an empty array is `[]`.

## Fingerprints

| Fingerprint | Value |
| --- | --- |
| Natural key | `canonical(components)`, for example `["books"]` or `["acme",42]` |
| `schemaFingerprint` | `hex(SHA-256(canonical(item schema)))`: the provider's item schema as returned by `schema()` |
| `desiredFingerprint` | `hex(SHA-256(canonical(normalised item)))`: the item after key normalisers, without read-only properties; `{}` for an empty item; `null` for a delete |
| `currentFingerprint` | Supplied by the provider: `AbstractSeedProvider::fingerprint()` / `RowDiffer::fingerprint()` hash the canonical writable current values (read-only properties and nulls left out); a version column also works. `null` for a create |

## Change line

For each change:

```text
op 0x1F canonical(key) 0x1F (currentFingerprint ?? "-") 0x1F (desiredFingerprint ?? "-") 0x1E
```

`op` is `C` (create), `U` (update), `N` (unchanged) or `D` (delete). `0x1F` is the unit separator (US), `0x1E` the
record separator (RS).

## Plan hash

```text
"sk1:" . hex(SHA-256(stream))

stream = "nowo-seed-kit/plan/1" RS scope.key() RS prune ("1" | "0") RS
         for each document, in dependency order:
             provider US version US schemaFingerprint RS
             change lines of its items, in document order
             change lines of its deletions (prune only), ascending canonical key
```

`scope.key()` is `kind` or `kind:id` (for example `platform`, `organization:acme`). The hash is computed
incrementally while the plan is produced, in constant memory. Invalid items never reach the hash: any row error stops
the plan.

## Batch digests

Items are planned in slices of `apply.batch_size`; each slice, then each slice of deletions, is one batch numbered
from 1 **per provider** (the deletion batches continue the numbering after the upsert batches):

```text
batchDigest = hex(SHA-256("nowo-seed-kit/batch/1" RS provider RS batchNumber RS change lines of the batch))
```

The digests of every batch are stored with the run (`batch_digests`). A batch whose changes are all `N` is not
applied (no transaction), but it is digested and hashed.

## Golden vectors

All vectors use this item schema (provider `catalog.categories`, version 1):

```json
{"additionalProperties":false,"properties":{"label":{"description":"Label","maxLength":120,"type":"string"},"slug":{"description":"Slug","maxLength":64,"type":"string"}},"required":["slug","label"],"type":"object","x-seed-key":["slug"]}
```

`schemaFingerprint` = `9f188dd792c75c499640d74052a505e3cc41fd4f3d2e6929743507bf86e3cc69`

### Vector 1: one create, platform scope

Scope `platform`, no prune, one document with one item `{slug: books, label: Books}` that does not exist yet.

| Value | Result |
| --- | --- |
| Canonical item | `{"label":"Books","slug":"books"}` |
| `desiredFingerprint` | `f38a07563a7be3a84d518d68051a9e81c94a833950dbc5ad05903b7ad79fa8d8` |
| Change line | `C` US `["books"]` US `-` US `f38a0756…a8d8` RS |
| Plan hash | `sk1:9ca8e04ae2c47321fc5d536d28b52814c72523cb5e3243595250ccc774d59c5e` |
| Batch 1 digest | `b3d973f6b1744c3c8e5f22fc7e5a0d0bdfbf61dade6d25d6d6ff9c41d8f4cb67` |

### Vector 2: update, unchanged and delete with prune

Scope `organization:acme`, prune on. Current rows: `books` = `{slug: books, label: Books}`, `games` =
`{slug: games, label: Games}`, `toys` = `{slug: toys, label: Toys}`. Document items: `books` (same label), `games`
with label `Board games`. `toys` is missing from the file.

| Row | Op | `currentFingerprint` | `desiredFingerprint` |
| --- | --- | --- | --- |
| `["books"]` | `N` | `f38a07563a7be3a84d518d68051a9e81c94a833950dbc5ad05903b7ad79fa8d8` | `f38a07563a7be3a84d518d68051a9e81c94a833950dbc5ad05903b7ad79fa8d8` |
| `["games"]` | `U` | `8c90e6a301692bfcec5ca0d753a846cae1430a1ac6c8c90688d8e06cc1a55fd2` | `7a0702d18f808287cde3e4ab98505d1cbf71e40d2fc26dd1f3c2d784a248767a` |
| `["toys"]` | `D` | `01cba4c58edf42bc0ca443895135a7aa1aac3de80cd21d669bfe63dd92ec6403` | `-` |

| Value | Result |
| --- | --- |
| Plan hash | `sk1:7e31d580f8648a492a441d6c0b5824028a5d3a7c57651bec460d81ef6ae9b9d0` |
| Batch 1 digest (upserts `books`, `games`) | `6a521754b9b64f1f6c0bb885a36a9985ed4b30536cd6e6739a9205719f123e6a` |
| Batch 2 digest (deletion `toys`) | `0203fa5b303991dd8b3b5e8b63c1ff932fce96f8e969a5b3a9a6159e95240910` |

### Vector 3: an empty run

No document changes at all (the stream holds only the prefix): scope `platform`, no prune.

`sk1:58ed10a31b00fc9e9306906714b1b600c7e6f5bc04ab7e2315a783d6687015f0` =
`"sk1:" . hash('sha256', "nowo-seed-kit/plan/1\x1Eplatform\x1E0\x1E")`.

### Canonical JSON vectors

| Input (PHP) | Canonical JSON |
| --- | --- |
| `['b' => 1, 'a' => [true, null, 'x/y']]` | `{"a":[true,null,"x/y"],"b":1}` |
| `['é' => 'ü', 'a' => 1.0, 'z' => 0.5, 'm' => 1e25, 'n' => -0.0]` | `{"a":1,"m":1e+25,"n":0,"z":0.5,"é":"ü"}` |
| `["\u{1F600}" => 1, "\u{FF5E}" => 2]` | `{"😀":1,"～":2}` (UTF-16 order: U+D83D < U+FF5E; UTF-8 byte order would invert them) |
| `['acme', 42]` (a natural key) | `["acme",42]` |

## Recompute it yourself

Vector 1 in plain PHP, without the bundle:

```php
$US = "\x1F";
$RS = "\x1E";
$schema  = '9f188dd792c75c499640d74052a505e3cc41fd4f3d2e6929743507bf86e3cc69';
$desired = hash('sha256', '{"label":"Books","slug":"books"}');
$stream  = "nowo-seed-kit/plan/1{$RS}platform{$RS}0{$RS}"
    . "catalog.categories{$US}1{$US}{$schema}{$RS}"
    . "C{$US}[\"books\"]{$US}-{$US}{$desired}{$RS}";

echo 'sk1:' . hash('sha256', $stream);
// sk1:9ca8e04ae2c47321fc5d536d28b52814c72523cb5e3243595250ccc774d59c5e
```
