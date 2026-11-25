# Document format

## Table of contents

- [One document](#one-document)
- [Several documents in one file](#several-documents-in-one-file)
  - [YAML stream](#yaml-stream)
  - [JSON documents](#json-documents)
- [JSON Lines](#json-lines)
- [Items](#items)
- [Exports](#exports)
  - [Empty template](#empty-template)
  - [Current rows](#current-rows)
- [Published schema](#published-schema)
- [Reader caps](#reader-caps)
- [YAML features refused](#yaml-features-refused)
- [Quote dates and look-alike scalars](#quote-dates-and-look-alike-scalars)
- [Format detection](#format-detection)

A seed file holds one or more **documents**; each document belongs to one provider. The bundle reads YAML (default),
JSON and JSON Lines, all UTF-8.

## One document

```yaml
$schema: catalog.categories.v1.schema.json   # informational, never fetched (≤ 255 bytes)
provider: catalog.categories                  # registered provider name
version: 1                                    # the provider's document version
scope: platform                               # scope KIND only; the target scope id comes from --scope
meta:                                         # optional, informational
    exportedAt: '2026-11-24T10:00:00+00:00'
    exportedFrom: '0193a6f0-7c1e-7000-8000-000000000001'
    generator: 'seed-kit 0.1.0'
items:
    - { slug: books, label: Books, description: Printed and digital books }
    - { slug: games, label: Board games }
```

| Key | Rule |
| --- | --- |
| `provider` | Required string, a registered provider (`SEED_001` otherwise) |
| `version` | Required integer, equal to the provider's `version()` (`SEED_001` otherwise) |
| `scope` | Required string, the scope kind; must equal the target scope kind and be one of the provider's kinds (`SEED_007`) |
| `$schema` | Optional string ≤ 255 bytes; never loaded |
| `meta` | Optional map of `exportedAt`, `exportedFrom` (≤ 128 bytes, exact text), `generator` (≤ 64 bytes), all strings. When `exportedFrom` differs from the target scope id the plan carries a warning (a file exported from one tenant applied to another is allowed, and made visible) |
| `items` | Required list of objects |

Any other top-level or `meta` key is refused (`SEED_002`, reasons `unknown_key` / `header_invalid`).

## Several documents in one file

A file with several documents is **one run**: the documents are sorted by the providers' dependency order (whatever
their order in the file), creates and updates go in that order, deletes (prune) in reverse order after all upserts.
A provider may appear only once per file (`duplicate_provider`). At most `reader.max_documents` (20) documents.

### YAML stream

```yaml
provider: catalog.categories
version: 1
scope: platform
items:
    - { slug: books, label: Books }
---
provider: catalog.products
version: 1
scope: platform
items:
    - { sku: BK-0001, name: Symfony in practice, category: books, price: 29.9, active: true }
```

A document separator is a line that is exactly `---` or `...` (optionally followed by spaces or a comment); empty or
comment-only parts are skipped.

### JSON documents

```json
{
    "documents": [
        {"provider": "catalog.categories", "version": 1, "scope": "platform", "items": [{"slug": "books", "label": "Books"}]},
        {"provider": "catalog.products", "version": 1, "scope": "platform", "items": []}
    ]
}
```

`documents` must be the only key. A plain JSON file holds one document object.

## JSON Lines

The format for large files (`.jsonl` or `.ndjson`): **line 1** is the header object (every key except `items`), every
following line is **one item**. One provider per file. Empty lines are skipped.

```text
{"provider":"catalog.products","version":1,"scope":"platform"}
{"sku":"BK-0003","name":"Release it!","category":"books","price":39.99,"active":true}
{"sku":"GM-0002","name":"Tile-laying game","category":"games","price":24.5,"active":true}
```

JSON Lines is the only streamed format: the file is copied once into a temporary stream and items are read line by
line, slice by slice, so memory stays flat whatever the file size (up to `reader.max_bytes`). Each line is at most
`reader.max_line_bytes` (1 MiB). Errors name the line number.

## Items

An item is a JSON object (YAML mapping) matching the provider's item schema:

- the natural key properties (`x-seed-key`) are required, strings or integers, compared byte for byte (`Bob` ≠ `bob`
  ≠ `bob `); a key normaliser (`x-seed-key-normalise: lower`) is applied before validation and shown in the diff;
- a natural key appears once per document (`duplicate_key` row error);
- values are JSON-compatible: null, booleans, integers, strings, lists, maps; floats only in `x-seed-float` properties;
  large integers in JSON are kept as strings (`JSON_BIGINT_AS_STRING`) and then fail a `type: integer` rule;
- an absent optional property keeps the current value unless the schema says `x-seed-omit-means: clear`; an absent
  secret always keeps it;
- `x-seed-readonly` properties are accepted and ignored;
- never a database id.

Errors are reported per item: document index, natural key, JSON Pointer (`/address/city`), rule (`required`,
`maxLength`, `enum`, `secret_plaintext`, `duplicate_key`, …), translated message. Never the value.

## Exports

Exports are round-trip stable: rows in natural-key byte order, properties in schema order with read-only properties
last, no ids, secrets as references of the scope or absent. Exporting the current rows and applying the file plans 0
changes. Exports are generated in the requested format: `--format=yaml|json|jsonl`.

### Empty template

```yaml
# yaml-language-server: $schema=catalog.categories.v1.schema.json
# SeedKit document: Catalog categories (provider catalog.categories, version 1, scope platform)
# Natural key: slug (slug lower-cased). Personal data: none. Secrets: none.
# Fields (* required):
#   slug* (string): Category slug
#   label* (string): Label
#   description (string): Description
$schema: catalog.categories.v1.schema.json
provider: catalog.categories
version: 1
scope: platform
meta: { generator: 'seed-kit 0.1.0' }
items: []
# items:
#   - slug: books
#     label: Books
#     description: 'Printed and digital books'
```

The commented examples come from the provider's `exportEmpty()` (by default the schema's `examples`). JSON and JSON
Lines templates carry the header and no items.

### Current rows

```yaml
# yaml-language-server: $schema=catalog.categories.v1.schema.json
# SeedKit document: Catalog categories (provider catalog.categories, version 1, scope platform)
# Natural key: slug (slug lower-cased). Personal data: none. Secrets: none.
# Fields (* required):
#   slug* (string): Category slug
#   label* (string): Label
#   description (string): Description
$schema: catalog.categories.v1.schema.json
provider: catalog.categories
version: 1
scope: platform
meta: { exportedAt: '2026-11-24T10:00:00+00:00', generator: 'seed-kit 0.1.0' }
items:
  - slug: books
    label: Books
    description: 'Printed and digital books'
  - slug: games
    label: 'Board games'
```

`meta.exportedFrom` is set to the scope id when the scope has one.

## Published schema

`seed:schema <provider>` prints the document schema an editor or an AI validates a whole file against: the item
schema wrapped in the envelope (`$id` `urn:nowo-seed:<provider>:<version>`, `provider` const, `version` const, `scope`
enum of the provider's kinds, `meta`, `items` with `maxItems` = `reader.max_items`). The item's own `$defs` are hoisted
next to `item`, so local references keep resolving. Remote references are never loaded.

## Reader caps

| Cap (configuration) | Default | Reason when exceeded |
| --- | --- | --- |
| Bytes read (`reader.max_bytes`) | 256 MiB | `too_large` |
| YAML / JSON tree (`reader.max_tree_bytes`) | 4 MiB | `too_large_for_tree` (use JSON Lines) |
| Documents per file (`reader.max_documents`) | 20 | `too_many_documents` |
| Items per document (`reader.max_items`) | 1 000 000 | `too_many_items` |
| JSON depth / item nesting (`reader.max_depth`) | 16 | `json_parse` (decoder) / `too_deep` (item walk) |
| JSON Lines line (`reader.max_line_bytes`) | 1 MiB | `line_too_long` |
| One string or key (`reader.max_string_bytes`) | 64 KiB | `string_too_long` |
| Properties of one object (`reader.max_properties`) | 256 | `too_many_properties` |
| Nodes of one item (`reader.max_nodes_per_item`) | 10 000 | `too_many_nodes` |
| Row errors kept (`validation.max_errors`) | 200 | the report is truncated |

Encoding: UTF-8 only; a UTF-8 BOM is stripped; UTF-16 / UTF-32 BOMs, NUL bytes and invalid UTF-8 are refused
(`encoding`). JSON objects with a repeated key are refused (`duplicate_key`; PHP would silently keep the last one);
a YAML mapping with a repeated key fails to parse (`yaml_parse`, with the line). Bytes are counted while reading, never trusted from a header.

## YAML features refused

Before the parser runs, a pre-scanner refuses what a seed document never needs:

| Feature | Example | Reason |
| --- | --- | --- |
| Tags | `!php/object`, `!php/const`, `!!binary`, `!custom` | `yaml_tag` (objects, constants, custom types) |
| Anchors | `&base` | `yaml_anchor` (expansion attacks) |
| Aliases | `*base` | `yaml_alias` |
| Merge keys | `<<: *base` | `yaml_merge_key` |
| Directives | `%YAML 1.2` | `yaml_directive` |
| Explicit keys | `? key` / `: value` | `yaml_explicit_key` (Symfony Yaml does not support them) |

Tokens are checked where a node starts; quoted scalars, block scalars, comments and the inside of plain scalars are
skipped, so `a&b`, `"*"` or `'!important'` are fine. A node starts after any `:` inside a flow collection and after a
`:` that follows a quoted key even without a space (JSON style `{"k":v}` or `'k':v`), so `{"k":*a}` is refused
too; inside a flow collection a plain scalar such as `a:*b` is refused as well (fail closed: quote it). A `:` or `?`
glued to the next character starts a plain scalar (`:|p`, `:"p` are keys). A plain scalar continued on a new line
that starts with one of these characters is refused too, and a `!` after a space or `[ { , : ? -` is refused even
inside plain text (`Hello!` is fine, `a !b` is not).

Where Symfony could read the text differently from the pre-scanner, the reader refuses it (`yaml_ambiguous`)
instead of guessing. Write seed files with spaces and keep these out:

| Refused | Write instead |
| --- | --- |
| A tab outside a quoted or block scalar (indentation, separator, before `#`) | spaces |
| A quoted, flow or block scalar opening on a line deeper than the key or first `-` whose value ended above (`k: x` then a deeper `"y`; `- - a` then `  - "b"`) | quote the whole value, or put it on its own item |
| Text right after a closed `[ ]` / `{ }` other than `,`, `]`, `}` or `:` (`[[]*a]`, `k: [a] b`) | add the separator |
| Text after the closing quote of a quoted scalar that spans lines | end the line after the quote |
| A quoted scalar over several lines inside `[ ]` / `{ }` | keep it on one line, or use block style |
| `[` / `{` inside a plain scalar of a flow collection (`[a[b]]`), or a quote right after `a:` in one (`[a:'b']`) | quote the value |
| A block scalar header other than `\|` / `>` with an optional `+` / `-` and a comment (`\|2`, `>- text`) | the plain header |

The parser itself runs without object, constant, custom-tag or date support and fails on invalid types and on
aliases (`PARSE_EXCEPTION_ON_ALIAS`, hence the `symfony/yaml` floor `^7.4.12 || ^8.0.12`).

The nesting is bounded before the parser recurses: flow brackets, block indentation levels and compact `- `
indicators are counted (an upper estimate) and a text deeper than `reader.max_depth + 4` is refused (`too_deep`), as
for JSON. Strings the parser builds from escapes (`"\0"`) must still be UTF-8 without NUL bytes (`encoding`); the same
holds for JSON `"\u0000"`.

## Quote dates and look-alike scalars

YAML turns some unquoted scalars into other types, and the reader does not parse dates. Unquoted `2026-11-24` or
`2026-11-24T10:00:00+00:00` becomes an **integer** (a Unix timestamp) and then fails a `type: string` or
`format: date` rule; unquoted `1.10` becomes the float `1.1` (a `float_not_allowed` row error outside `x-seed-float`
properties). **Quote** every date, time, version number or code that must stay text:

```yaml
items:
    - { code: '0012', since: '2026-11-24', version: '1.10', enabled: true, label: 'Board games' }
```

JSON and JSON Lines have no such ambiguity. Exports quote these values for you.

## Format detection

The console picks the parser from the file extension (`.yaml` / `.yml`, `.json`, `.jsonl` / `.ndjson`); without one
(stdin, other names) it looks at the content: a first line that is a complete `{…}` object followed by a line starting
with `{` is JSON Lines, a leading `{` is JSON, anything else is YAML. The content decides whether the file is valid:
a parse failure is `SEED_002`.
