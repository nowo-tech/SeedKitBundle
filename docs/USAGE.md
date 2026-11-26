# Usage

## Table of contents

- [Concepts](#concepts)
- [Writing a provider](#writing-a-provider)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
  - [Registration](#registration)
  - [Provider rules](#provider-rules)
- [Schema keywords](#schema-keywords)
- [Secrets and vault references](#secrets-and-vault-references)
- [Prune](#prune)
- [Console reference](#console-reference)
  - [Exit codes](#exit-codes)
  - [list](#list)
  - [schema](#schema)
  - [export](#export)
  - [diff](#diff)
  - [apply](#apply)
  - [runs:list](#runslist)
  - [runs:purge](#runspurge)
  - [actor:forget](#actorforget)
  - [lint](#lint)
  - [The system actor](#the-system-actor)
- [Console recipes](#console-recipes)
- [AI editing workflow](#ai-editing-workflow)
- [Contract test kit](#contract-test-kit)
- [Access mapping](#access-mapping)
  - [Roles](#roles)
  - [Your own checker](#your-own-checker)
  - [The access_check event](#the-access_check-event)
  - [Voter attributes](#voter-attributes)
- [Audit and events](#audit-and-events)
- [Errors](#errors)
- [Calling the operations from PHP](#calling-the-operations-from-php)
- [Import / export pages](#import--export-pages)

## Concepts

| Term | Meaning |
| --- | --- |
| Provider | A service that knows one kind of data (`catalog.categories`, `crm.contacts`): its schema, its current rows, how to plan and apply changes through your own code |
| Scope | Where data lives: a kind (`platform`, `organization`, `project` or any host kind) and, for kinds that need one, an id. The target scope comes from `--scope`, never from the file |
| Natural key | 1–4 properties that identify a row without a database id (`slug`, `email`, `[company, email]`); compared byte for byte |
| Document | One provider's items in a file (YAML, JSON or JSON Lines); a file may hold several documents (one run) |
| Plan | The dry run: one change per item (create, update, unchanged) plus deletions when pruning, with a plan hash |
| Run | One apply that writes: batches in transactions, recorded in `<prefix>seed_run` |

## Writing a provider

A provider implements `Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface`. The bundle reads the file, validates
it, asks the provider to plan slices of items and to apply batches; the provider owns the data and writes through
your application's services or handlers, inside the transaction the bundle opened.

### Standard Symfony host

Extend `Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider`: you write the schema, the export, the lookup of
current rows by key and the batch write; the base class plans each item with `RowDiffer` (changed fields only,
fingerprints), returns the schema `examples` as the empty template and gives `dependsOn() = []`, no deletions and a
no-op `afterBatch()`.

```php
use App\Catalog\CatalogService;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Attribute\AsSeedProvider;

#[AsSeedProvider(name: 'catalog.categories')]
final class CategoryProvider extends AbstractSeedProvider
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function name(): ProviderName
    {
        return new ProviderName('catalog.categories');
    }

    public function version(): int
    {
        return 1;
    }

    public function scopes(): array
    {
        return [new ScopeKind(ScopeKind::PLATFORM)];
    }

    public function schema(): SeedSchema
    {
        return new SeedSchema([
            'title'                => 'Catalog categories',
            'type'                 => 'object',
            'required'             => ['slug', 'label'],
            'additionalProperties' => false,
            'x-seed-key'           => ['slug'],
            'x-seed-key-normalise' => ['slug' => 'lower'],
            'properties'           => [
                'slug'        => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9-]+$', 'description' => 'Category slug'],
                'label'       => ['type' => 'string', 'maxLength' => 120, 'description' => 'Label'],
                'description' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Description', 'x-seed-omit-means' => 'clear'],
            ],
            'examples' => [['slug' => 'books', 'label' => 'Books', 'description' => 'Printed and digital books']],
        ]);
    }

    /** Rows the actor may read, in natural-key byte order. */
    public function exportCurrent(SeedContext $context): iterable
    {
        foreach ($this->catalog->allCategoriesOrderedBySlug() as $category) {
            yield new SeedItem(0, new NaturalKey([$category->getSlug()]), $this->row($category));
        }
    }

    /** Prune support (optional): rows of the scope missing from the file, ascending by key. */
    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        foreach ($this->catalog->allCategoriesOrderedBySlug() as $category) {
            $key = new NaturalKey([$category->getSlug()]);
            if (!$seen->has($key)) {
                yield RowChange::delete($key, $this->fingerprint($this->row($category)));
            }
        }
    }

    /** Writes one batch through the application service; no commit here. */
    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        $created = $updated = $deleted = 0;
        foreach ($batch->changes as $change) {
            $slug = (string) $change->key->components[0];
            if ($change->op === ChangeOp::Delete) {
                $this->catalog->removeCategory($slug);
                ++$deleted;

                continue;
            }
            $data = $batch->item($change)?->data ?? [];
            $this->catalog->saveCategory($slug, $data['label'], $data['description'] ?? null);
            $change->op === ChangeOp::Create ? ++$created : ++$updated;
        }
        $this->catalog->flush();

        return new BatchResult($created, $updated, $deleted);
    }

    /** Memory stays flat: forget what the batch loaded. */
    public function afterBatch(SeedContext $context): void
    {
        $this->catalog->clear();
    }

    /** Current values by canonical key; with lockForUpdate, read them with the write lock. */
    protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array
    {
        $slugs = array_map(static fn (NaturalKey $key): string => (string) $key->components[0], $keys);
        $rows  = [];
        foreach ($this->catalog->categoriesBySlug($slugs, $options->lockForUpdate) as $category) {
            $rows[(new NaturalKey([$category->getSlug()]))->canonical] = $this->row($category);
        }

        return $rows;
    }

    private function row(Category $category): array
    {
        $row = ['slug' => $category->getSlug(), 'label' => $category->getLabel()];
        if ($category->getDescription() !== null) {
            $row['description'] = $category->getDescription();
        }

        return $row;
    }
}
```

`validateItem(SeedContext, SeedItem): list<RowError>` is the hook for domain checks (a reference that does not exist,
a permission the actor does not hold, a business rule): every error is collected before anything is written. The demo
(`demo/symfony8/src/Seed`) has a second provider, `catalog.products`, with an `x-seed-ref` to the categories and an
`x-seed-float` price.

### DDD / hexagonal host

Implement `SeedProviderInterface` directly in your infrastructure layer: no bundle base class reaches your domain. Plan
with your read model, apply through your command handlers, and reuse `RowDiffer` if you want the same field diff:

```php
final readonly class ProjectRoleSeedProvider implements SeedProviderInterface
{
    public function __construct(private ProjectRoleReadModel $roles, private CommandBus $commands)
    {
    }

    public function name(): ProviderName { return new ProviderName('access.project_roles'); }
    public function version(): int { return 1; }
    public function scopes(): array { return [new ScopeKind('project')]; }
    public function schema(): SeedSchema { return new SeedSchema(ProjectRoleSchema::ITEM); }
    public function dependsOn(): array { return []; }
    public function exportEmpty(SeedContext $context): iterable { return ProjectRoleSchema::ITEM['examples']; }

    public function exportCurrent(SeedContext $context): iterable
    {
        foreach ($this->roles->visibleTo($context->scope->storedId(), $context->actor->value) as $role) {
            yield new SeedItem(0, new NaturalKey([$role->key]), $role->toSeedRow());
        }
    }

    public function planBatch(SeedContext $context, array $items, PlanOptions $options): BatchPlan
    {
        $current = $this->roles->byKeys($context->scope->storedId(), $items, $options->lockForUpdate);
        $schema  = $this->schema();
        $changes = $errors = [];
        foreach ($items as $item) {
            if (!$this->roles->actorHoldsEveryPermission($context->actor->value, $item->data['permissions'] ?? [])) {
                $errors[] = new RowError($item->index, $item->key, '/permissions', 'escalation', 'app.seed.escalation');
            }
            $changes[] = RowDiffer::diff($schema, $item, $current[$item->key->canonical] ?? null);
        }

        return new BatchPlan($changes, $errors);
    }

    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        return [];
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        foreach ($batch->changes as $change) {
            $this->commands->dispatch(UpsertProjectRole::fromSeed($context->scope->storedId(), $batch->item($change)));
        }

        return new BatchResult(...ProjectRoleCounts::of($batch->changes));
    }

    public function afterBatch(SeedContext $context): void
    {
    }
}
```

A DDD host usually also plugs its own ports ([CONFIGURATION.md](CONFIGURATION.md#scopes-and-resolvers)):
`scope_resolver` mapping scopes to tenant value objects, `actor_resolver`, `security.access_checker` over permission
sets, XML mapping (the default) and a separate entity manager / connection in `doctrine.entity_manager`. Both host
kinds are covered by the test suite (a standard host with attribute mapping and the role checker; a DDD host with XML
mapping, a second connection, its own resolvers and checker, and a provider without base class).

### Registration

With autoconfiguration, the attribute is enough:

```php
#[AsSeedProvider(name: 'crm.contacts', dependsOn: ['crm.companies'])]
```

Without autoconfiguration (a DDD host often wires its infrastructure explicitly), tag the service with the attributes
`name` and `depends_on`. In YAML use the "tag name as key" form, because a `name` key in the short form would be read
as the tag name:

```yaml
services:
    App\Infrastructure\Seed\ContactProvider:
        tags:
            - nowo_seed_kit.provider: { name: 'crm.contacts', depends_on: ['crm.companies'] }
```

```php
// PHP configurator or a compiler pass
$definition->addTag('nowo_seed_kit.provider', ['name' => 'crm.contacts', 'depends_on' => ['crm.companies']]);
```

The compiler pass checks that every tag has a valid name (`[a-z][a-z0-9_]{0,31}` segments joined by dots, at most 4
segments and 64 bytes), that names are unique and that every `depends_on` exists, and computes the order once
(Kahn's algorithm, ties by name byte order). A cycle fails the build and names its members. Keep the attribute's
`dependsOn` equal to `dependsOn()`: the tag fixes the apply order, `dependsOn()` is what the schema lint checks
`x-seed-ref` against. At runtime the registry refuses a provider whose `name()` differs from its tag (`SEED_011`).
Providers are lazy (service locator).

### Provider rules

The planner and the exporter re-check what a provider returns and fail closed (`SEED_011`, nothing written) when it
breaks a rule:

- `planBatch()` returns exactly one change per item, in item order, with the item's key (canonical bytes compared with
  `===`) and index, never `Delete`; it reads the current rows with one bounded query set (by natural key), with the
  write lock when `$options->lockForUpdate` is true (the apply re-plans each batch inside its transaction).
- `planDeletions()` returns `Delete` changes only for keys absent from `$seen`, strictly ascending by canonical key.
- `exportCurrent()` returns `SeedItem`s in strictly ascending canonical key order, whose key matches the key
  properties of the data, with only schema properties, and secrets as `SecretReference` objects (or absent).
- `applyBatch()` writes through your handlers inside the bundle's transaction: no commit, no own transaction on the
  same connection, events after commit only. It receives only the changes that write (`Create`, `Update`, `Delete`).
- Filter by the actor (`$context->actor`): export, plan and prune only the rows that actor may read and write.
- Never put database ids in items; deterministic output; no state between calls (worker mode).

## Schema keywords

The provider returns the JSON Schema of **one item** (draft 2020-12 validation keywords); the bundle wraps it in the
document schema it publishes ([FORMAT.md](FORMAT.md#published-schema)).

| Keyword | Where | Meaning |
| --- | --- | --- |
| `x-seed-key` | item | 1–4 required top-level `string` / `integer` properties forming the natural key (required) |
| `x-seed-key-normalise` | item | `{property: lower \| nfc}` on key properties, applied **before** validation (`nfc` needs `ext-intl`); the diff shows the normalised value |
| `x-seed-ref` | item | `{property: provider}`: the property holds another provider's natural key; the provider must be in `dependsOn()` |
| `x-seed-secret` | property | `true`: only `vault:` references, never exported in clear ([below](#secrets-and-vault-references)); a property whose name looks secret (`password`, `token`, `api_key`, `iban`, `card`, …) must declare `true` or `false` |
| `x-seed-personal-data` | property | `true`: exports of the provider are audited; the template header lists the field |
| `x-seed-readonly` | property | Exported for context (last, after the writable properties), ignored on import and in fingerprints (for example `createdAt`, consents) |
| `x-seed-float` | property | Floats allowed in this top-level property (elsewhere a float is a row error `float_not_allowed`) |
| `x-seed-omit-means` | property | `keep` (default: an absent optional property never clears a value) or `clear` (absent clears it); secrets always keep |

The lint (`nowo:seed-kit:lint`, the cache warmer, the contract test kit) fails when: there is no valid `x-seed-key`, a
key property is not a required string / integer or is secret / read-only, an object lacks
`additionalProperties: false`, a `$ref` is not local (`#/...`), `$dynamicRef`, `$recursiveRef` or `$vocabulary`
appear, the `regex` format is used, an `x-seed-ref` points outside `dependsOn()`, a normaliser is invalid, a property
has an integer-like name, a secret-looking name has no explicit `x-seed-secret`, or `x-seed-omit-means` is not
`keep` / `clear`. It warns on strings without `maxLength` / `enum` / `const` / `format` and on properties without a
`description` (an AI editing the file reads them). Expected keywords for people and AI: `title`, `description`,
`examples`, `enum`.

## Secrets and vault references

- **Export**: a secret property is written only when the provider returns a `SecretReference` **and** the secret
  validator confirms it belongs to the target scope (`belongsTo()`); otherwise the property is omitted. A plain string
  in a secret property makes the export fail (`SEED_011`); the template header names the secret fields.
- **Import**: a secret property accepts only `vault:` + `[A-Za-z0-9][A-Za-z0-9._:/#-]{0,199}`. Plain text is a row
  error `secret_plaintext` (`SEED_004`) and the value is never echoed. A reference must pass the validator's
  `isUsable($reference, $scope, $actor)`; without a validator every reference is refused (fail closed).
- **Absent means keep**: an omitted secret keeps the current value (round trips plan 0 changes); clearing a secret
  through a seed is not possible in 0.x. The diff shows reference changes (`vault:a` → `vault:b`) and hides any other
  secret value.
- **Vault bridge** (`secrets.vault_bridge.enabled: true`): `vault:<item-uuid>` of an item owned by the target scope
  (`scope_format`, default `{kind}:{id}`), not trashed; on import the actor (the Vault user id) must be allowed to view
  it; the console `system` actor is checked on ownership only. Or set `secrets.validator` to your own
  `SecretReferenceValidatorInterface`.

## Prune

Prune deletes the rows of the target scope that the file does not mention (and that the provider returns for this
actor). It is off unless you pass `--prune` and needs `SEED_PRUNE` on every provider of the run (nobody by default).

- Deletions are planned after the upserts of each document and applied after **all** upserts, in **reverse
  dependency order** (contacts before companies).
- A prune whose deletions exceed `prune.max_ratio` (default 0.5) of the current rows is refused (`SEED_012`, exit 2)
  unless `--allow-mass-prune`.
- A document with no items and `--prune` is refused unless `--allow-empty-prune`.
- Deletes go through your provider's `applyBatch()` (soft delete if that is what your application does).
- A prune is audited as `prune` (an apply without prune as `apply`).

## Console reference

Every command exists as `nowo:seed-kit:<name>` and, with `console.short_aliases: true` (default), as `seed:<name>`.
Messages go to stderr and data (exports, schemas, `--format=json` plans) to stdout. Messages are static and translated;
they never contain a value from the file.

### Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success (applied, nothing to do, no changes) |
| 1 | Unexpected failure, a provider failure before any commit, `actor:forget` declined, `runs:purge` lock conflict |
| 2 | Invalid input: document (`SEED_001` … `SEED_004`, `SEED_007`, `SEED_011`, `SEED_012`), option, path, value |
| 3 | Stale plan (`SEED_005`, `--expect-hash`), or a row changed before any batch committed (`SEED_006`) |
| 4 | Access denied (`ACCESS_002`) or scope not found / not visible (`ACCESS_001`) |
| 5 | Lock conflict, nothing committed: lease held by another run or `CONFLICT_001` (prints the seconds to wait) |
| 6 | Partially applied: a failure after at least one batch committed (`CONFLICT_002`, `SEED_006`, `SEED_010`); apply the file again |
| 7 | `apply` declined at the prompt, or no TTY and no `--yes` |
| 9 | `diff --exit-code` and the plan has changes |

### list

`nowo:seed-kit:list` — the providers in dependency order with version, scope kinds, dependencies, personal-data and
secret properties.

| Option | Meaning |
| --- | --- |
| `--scope-kind=<kind>` | Only providers available in this kind |
| `--format=table\|json` | Default `table` |

Exit 0 (2 for an invalid kind).

### schema

`nowo:seed-kit:schema <provider>` — the document JSON Schema (draft 2020-12) for editors and AI tools. A schema is not
data: this command has no access check.

| Option | Meaning |
| --- | --- |
| `-o, --output=<file>\|-` | Default `-` (stdout) |
| `--force` | Overwrite an existing output file |
| `--allow-any-path` | Allow an output outside `console.allowed_dirs` |

Exit 0, 2 (unknown provider, bad path).

### export

`nowo:seed-kit:export <provider>` — the current rows the actor may read, or the empty commented template. Needs
`SEED_EXPORT`.

| Option | Meaning |
| --- | --- |
| `--empty` | Write the empty template (header comments, `items: []`, commented examples) |
| `--scope=kind[:id]` | Target scope, default `platform` |
| `--format=yaml\|json\|jsonl` | Default `yaml` |
| `-o, --output=<file>\|-` | Default `-` (stdout) |
| `--force` | Overwrite an existing output file |
| `--allow-any-path` | Allow an output outside `console.allowed_dirs` |
| `--actor=<who>` | Who exports (audit); required unless `console.require_actor: false` |

Exit 0, 2, 4.

### diff

`nowo:seed-kit:diff [<file>|-]` — the dry run: reads and validates the whole file, plans every document and prints
the counts, the changed rows and fields (values hidden where the actor lacks `SEED_EXPORT`, secrets always hidden),
warnings and the plan hash. Nothing is written or stored. Needs `SEED_APPLY` (and `SEED_PRUNE` with `--prune`).

| Option | Meaning |
| --- | --- |
| `--scope=kind[:id]` | Target scope, default `platform` |
| `--prune`, `--allow-mass-prune`, `--allow-empty-prune` | Plan deletions too, and accept the guards ([Prune](#prune)) |
| `--format=text\|json` | Default `text` |
| `--max-rows=N` | Changed rows shown per provider, default 50 |
| `--exit-code` | Exit 9 when the plan has changes |
| `--allow-any-path` | Allow a file outside `console.allowed_dirs` |
| `--actor=<who>` | Who plans |

Exit 0 / 9, 2, 4.

### apply

`nowo:seed-kit:apply [<file>|-]` — plans (same as `diff`), prints the summary and the plan hash, asks for
confirmation, then applies: leases, re-plan against the reviewed hash, batches. Needs `SEED_APPLY` (and `SEED_PRUNE`
with `--prune`).

| Option | Meaning |
| --- | --- |
| `--scope=kind[:id]` | Target scope, default `platform` |
| `--prune`, `--allow-mass-prune`, `--allow-empty-prune` | Delete the rows the file omits, and accept the guards |
| `-y, --yes` | Apply without asking (CI, scripts) |
| `--expect-hash=sk1:…` | Refuse unless the plan hash is this one (bind the apply to a reviewed dry run) |
| `--allow-any-path` | Allow a file outside `console.allowed_dirs` |
| `--actor=<who>` | Who applies (audit); `system` for fresh installs |

Exit 0, 1, 2, 3, 4, 5, 6, 7. The batch size is `apply.batch_size` (no option in 0.1); there is no `--async` in 0.1
(Messenger arrives in 0.2).

### runs:list

`nowo:seed-kit:runs:list` — the newest runs: id, scope, actor, status, providers, counts, batches, error code.

| Option | Meaning |
| --- | --- |
| `--scope=kind[:id]` | Only runs of this scope |
| `--status=<status>` | `running`, `applied`, `partially_applied`, `failed` (other values of the enum are not written in 0.1) |
| `--limit=N` | 1–500, default 20 |

Exit 0, 2.

### runs:purge

`nowo:seed-kit:runs:purge` — deletes finished runs created before `--before` (default: now minus
`runs.history_days`), in batches of 500.

| Option | Meaning |
| --- | --- |
| `--before=<date>` | UTC date, for example `2026-01-01` |
| `--dry-run` | Only count |

Exit 0, 1 (lock conflict), 2 (bad date).

### actor:forget

`nowo:seed-kit:actor:forget <ref>` — nulls the actor of every run of `<ref>` (erasure request; the history stays).

| Option | Meaning |
| --- | --- |
| `-y, --yes` | Do not ask |

Exit 0, 1 (declined), 2 (invalid ref).

### lint

`nowo:seed-kit:lint [<provider>]` — lints every provider schema (or one). Errors fail with exit 2; warnings are only
printed. Run it in CI: the cache warmer only logs.

### The system actor

`--actor=system` is the actor of a fresh install, where no user exists yet (`bin/console seed:apply
config/seeds/platform.yaml --yes --actor=system`). It is accepted **only on the console channel**: any other channel
refuses it (`ACCESS_002`) and the security voter never grants it. It is audited as `system`. On the console the access
decision defaults to granted (an operator with shell access); a `nowo_seed_kit.access_check` listener may still deny it.
With `console.require_actor: false`, commands without `--actor` also act as `system`.

## Console recipes

```bash
# What exists, and the schema an editor or an AI needs
bin/console seed:list
bin/console seed:schema crm.contacts -o config/seeds/crm.contacts.v1.schema.json

# Empty template, current rows (YAML, JSON Lines for big sets)
bin/console seed:export crm.contacts --empty --scope=organization:0193a6f0-7c1e-7000-8000-000000000001 --actor=ana -o config/seeds/contacts.yaml
bin/console seed:export crm.contacts --format=jsonl --scope=organization:0193a6f0-7c1e-7000-8000-000000000001 --actor=ana -o var/seeds/contacts.seed.jsonl

# Review, then apply exactly what was reviewed
bin/console seed:diff config/seeds/contacts.yaml --scope=organization:0193a6f0-7c1e-7000-8000-000000000001 --actor=ana
bin/console seed:apply config/seeds/contacts.yaml --scope=organization:0193a6f0-7c1e-7000-8000-000000000001 --actor=ana --yes --expect-hash=sk1:…

# CI gate: fail when the database drifted from the committed seed
bin/console seed:diff config/seeds/platform.yaml --actor=ci --exit-code

# Fresh install: several providers in one multi-document file, no user yet
bin/console seed:apply config/seeds/platform.yaml --yes --actor=system

# From stdin
cat contacts.json | bin/console seed:diff - --actor=ana

# History
bin/console seed:runs:list --status=partially_applied
bin/console seed:runs:purge --dry-run
bin/console seed:actor:forget ana --yes
```

## AI editing workflow

The bundle does not call any AI service; it makes files easy and safe to edit with one:

1. Export the schema (`seed:schema <provider>`) and a template or the current rows (`seed:export`). The YAML export
   starts with `# yaml-language-server: $schema=<provider>.v<n>.schema.json` and comments listing the natural key,
   the personal-data and secret fields and every field with its type and description.
2. Give both to the AI with the change you want ("add the five product categories of this list, keep the existing
   ones"). Keep the schema next to the file so an editor validates while you review.
3. Dry-run the result (`seed:diff`): schema errors are reported by row index, natural key, JSON Pointer and rule,
   never with the value; the plan shows exactly which rows and fields change.
4. Apply with `--expect-hash` set to the reviewed plan hash, so the apply is refused if anything changed meanwhile.

Secrets never reach the AI: exports carry only vault references of the scope, and an AI cannot invent a usable
reference because imports validate it.

## Contract test kit

`Nowo\SeedKitBundle\Testing\SeedProviderContractTestCase` (shipped in `src/Testing`, needs `phpunit/phpunit` in your
dev dependencies) runs the same checks on your providers that the bundle relies on. Extend it in a test that has a
database (your kernel test or your own connection), keep the data isolated per test, and implement the hooks:

| Hook | Required | Purpose |
| --- | --- | --- |
| `createProvider(): SeedProviderInterface` | yes | A fresh provider wired to the test database |
| `scope(): SeedScope` | yes | The scope the tests run in (already resolved) |
| `actor(): ActorRef` | yes | An actor allowed to read and write every sample row |
| `sampleItems(): array` | yes | Valid raw items whose first apply writes at least one row |
| `dependencies(): array` | no | Providers this one depends on (seed their rows yourself) |
| `secretValidator(): ?SecretReferenceValidatorInterface` | no | Your secret validator (default none: references refused / omitted) |
| `knownSecretValues(): array` | no | Secret values stored in the test data that must never appear in an export |
| `escalatingItem(): ?array` | no | An item that would grant the actor more than it holds (null: skipped) |
| `restrictedActor(): ?ActorRef` | no | An actor that may see only part of the rows (null: skipped) |
| `keysHiddenFromRestrictedActor(): array` | no | Keys that actor must not see after the sample apply |
| `settings(): SeedKitSettings` | no | Planner settings (default: the providers' scope kinds, batch 500) |

The tests it runs:

| Test | Checks |
| --- | --- |
| `schemaPassesTheLint` | The schema passes the lint |
| `applyIsIdempotentAndExportRoundTrips` | Applying the samples writes, re-planning them gives 0 changes, a second apply writes nothing, and `export current` read back plans 0 changes |
| `exportsAreStableAndCarryNoSecretValue` | Two exports return the same bytes (stateless) and contain none of `knownSecretValues()` |
| `refusesItemsThatEscalate` | `escalatingItem()` is refused before any write (no escalation by seed) |
| `restrictedActorSeesOnlyItsRows` | The restricted actor's export never contains the hidden keys |

```php
final class CategoryProviderContractTest extends SeedProviderContractTestCase
{
    protected function createProvider(): SeedProviderInterface { return new CategoryProvider(self::catalog()); }
    protected function scope(): SeedScope { return SeedScope::platform(); }
    protected function actor(): ActorRef { return new ActorRef('admin'); }
    protected function sampleItems(): array { return [['slug' => 'books', 'label' => 'Books']]; }
}
```

Helpers for your own tests: `documentSet()`, `plan()`, `apply()`, `export()`, `read()` and the in-memory doubles in
`Nowo\SeedKitBundle\Testing\Support` (`DirectWriteRunner`, `GrantAllGate`, `InMemoryLeaseStore`, `InMemoryRunStore`,
`SequentialIdGenerator`).

## Access mapping

Every export, plan and apply asks the guard (`SeedKitGuard`, the `SeedAccessGateInterface` port) for one action on
one provider in one scope, for one actor and channel:

1. **Default decision**: on the console without a user, granted. Elsewhere, without a user, denied unless
   `security.allow_unauthenticated`. With a user: the checker's `canUseArea()` **and** `can($action)`.
2. **Event** `nowo_seed_kit.access_check` (`SeedAccessCheckEvent`): listeners may `grant()` or `deny()`; a deny
   sticks, so one listener can never re-open what another closed.

The scope is resolved first by your scope resolver; an unknown or invisible scope is `ACCESS_001` (a 404 in the 0.2
UI), a denied action `ACCESS_002`.

### Roles

```yaml
nowo_seed_kit:
    security:
        access_roles: [ROLE_ADMIN]
        export_roles: [ROLE_ADMIN]
        apply_roles: [ROLE_ADMIN]
        prune_roles: [ROLE_SUPER_ADMIN]
        providers:
            crm.contacts: { export_roles: [ROLE_CRM_ADMIN], apply_roles: [ROLE_CRM_ADMIN], prune_roles: [] }
```

The role hierarchy applies. `canForActor()` (the stored-actor decision for Messenger in 0.2) is always denied by the
role checker.

### Your own checker

```php
final readonly class PermissionSetSeedAccessChecker implements SeedKitAccessCheckerInterface
{
    public function canUseArea(?object $user, SeedScope $scope): bool { /* may use seeds in this scope */ }
    public function can(?object $user, SeedAction $action, ProviderName $provider, SeedScope $scope): bool { /* per action */ }
    public function canForActor(ActorRef $actor, SeedAction $action, ProviderName $provider, SeedScope $scope): bool { return false; }
}
```

```yaml
nowo_seed_kit:
    security:
        access_checker: App\Seed\PermissionSetSeedAccessChecker
```

### The access_check event

```php
#[AsEventListener(event: SeedKitEvents::ACCESS_CHECK)]
final class SeedAccessListener
{
    public function __invoke(SeedAccessCheckEvent $event): void
    {
        // Restrict the console too: only the platform scope from a shell
        if ($event->channel === Channel::Console && $event->scope->kind->value !== 'platform') {
            $event->deny();
        }
    }
}
```

The event carries `action`, `provider`, `scope`, `channel`, `actor`, `user` (null in the console) and
`defaultGranted`.

### Voter attributes

`SeedKitVoter` (registered with `symfony/security-bundle`) supports `SEED_EXPORT`, `SEED_APPLY` and `SEED_PRUNE` on a
`SeedPermissionSubject(provider, scope)` and asks the same guard for the user of the token being voted on, not the
session's (HTTP channel; a token whose identifier is empty or `system` is denied), so
`AccessDecisionManagerInterface::decide($otherUsersToken, ...)` decides for that user. A replacement
`SeedAccessGateInterface` only receives the token's user identifier as actor:

```php
$this->denyAccessUnlessGranted('SEED_EXPORT', new SeedPermissionSubject(new ProviderName('crm.contacts'), $scope));
```

With Symfony's default `affirmative` strategy a host voter that **grants** the same attributes wins over this one: map
permissions with the checker or the event to keep deny-by-default.

## Audit and events

Audited through `SeedAuditSinkInterface` (`SeedAuditRecord`: action, actor, scope, providers, counts, channel,
outcome, document hash, plan hash, run id; never a natural key, a value or a file name):

| Action | When |
| --- | --- |
| `apply` | Every apply without prune: success (counts, hashes, run id), the error code, or `error` for a host or driver exception without a SeedKit code (its class and message are not recorded) |
| `prune` | The same with `--prune` |
| `export` | Every `export current` of a provider with `x-seed-personal-data` or `x-seed-secret` properties |

Other exports (templates, providers without sensitive fields) are logged at `info` only. Default sink: one PSR-3
record on the `seed_kit` channel at `audit.log_level`; with AuditKit installed, `seed_kit.<action>` entries in its
action log, written after the run's last commit.

Events (dispatched by class name after the commit, carrying value objects and ids only):

| Event | When |
| --- | --- |
| `SeedExportedEvent` | After an export (provider, scope, mode, rows, actor) |
| `SeedRunPlannedEvent` | After a dry run (providers, scope, plan hash, changes, actor) |
| `SeedBatchCommittedEvent` | After each committed batch (run, provider, batch number, created / updated / deleted) |
| `SeedRunAppliedEvent` | After the last batch (run, providers, scope, counts, document hash, plan hash, actor, channel) |
| `SeedRunFailedEvent` | When a recorded run stops (run, error code, batches committed) |

## Errors

All bundle exceptions extend `Nowo\SeedKitBundle\Domain\Exception\SeedKitException` with a stable `errorCode()` and a
translation key `seed_kit.error.<code>`; messages are static.

| Code | Exception | When |
| --- | --- | --- |
| `SEED_001` | `UnknownProviderException` | Provider or document version not registered, or not available in the scope kind |
| `SEED_002` | `DocumentReadException`, `InvalidValueException` | Size, encoding, parse, caps, YAML features, unknown keys, header; an invalid identifier |
| `SEED_003` | `DocumentInvalidException` | Schema or plan-time row errors (list of `RowError`: index, key, pointer, rule) |
| `SEED_004` | `SecretValueRefusedException` | Plaintext in a secret field, or a refused reference |
| `SEED_005` | `StalePlanException` | The re-plan differs from `--expect-hash` |
| `SEED_006` | `ApplyInterruptedException` | A batch digest differed inside its transaction |
| `SEED_007` | `ScopeMismatchException` | Document scope kind ≠ target kind, or provider not available in it |
| `SEED_010` | `ApplyInterruptedException` | The provider failed to apply a batch |
| `SEED_011` | `ProviderContractException` | A provider broke its contract (keys, order, ids, plain secret, name) |
| `SEED_012` | `PruneRefusedException` | Mass or empty prune without the explicit flag |
| `ACCESS_001` | `ScopeNotFoundException` | Unknown kind, missing / extra id, or the resolver does not show the scope |
| `ACCESS_002` | `AccessDeniedException` | The guard denied the action, or `system` outside the console |
| `CONFLICT_001` | `LeaseHeldException`, `ApplyInterruptedException` | Lease held by another run, or a lock conflict before any batch committed |
| `CONFLICT_002` | `ApplyInterruptedException` | A lock conflict after a batch committed |

`SEED_008` and `SEED_009` are reserved for the 0.2 UI (runs and synchronous size cap).

## Calling the operations from PHP

`Nowo\SeedKitBundle\Application\SeedOperations` (public service) is what the console uses: `providers()`, `schema()`,
`export()`, `diff()`, `apply()`. Each call resolves the scope, re-checks access and audits:

```php
$set  = $reader->read($stream, null, $limits);                     // DocumentReader + ReaderLimits services
$plan = $operations->diff($set, SeedScope::parse('organization:o1'), new ActorRef('ana'), Channel::Console, new PruneOptions());
$result = $operations->apply($set, SeedScope::parse('organization:o1'), new ActorRef('ana'), Channel::Console, new PruneOptions(), $plan->hash);
```

Long applies belong on the console or, from 0.2, Messenger; do not run them inside a web request.

## Import / export pages

0.2, opt-in (`ui.enabled`; packages and routes in [INSTALLATION.md](INSTALLATION.md#ui-routes-02), options in
[CONFIGURATION.md](CONFIGURATION.md#ui)):

1. *Import / export* lists the kinds of data the viewer may export or apply in the scope of the page.
2. *Empty template* / *Current data* / *JSON Schema* download the files to edit (by hand or with an AI using the schema).
3. *Upload file* opens the upload dialog: the file is read, validated and planned; a refused file lists its row
   errors (nothing stored); a valid one becomes a **planned run** and the run page opens with the diff.
4. *Apply* (confirm dialog) applies the reviewed plan — or queues it with Messenger above
   `messenger.async_threshold_changes`. *Discard* deletes the stored document.
5. *History* lists the runs of the scope.

```php
// The same from PHP (an admin action of your own, a test):
$run     = $seedRuns->upload($documentSet, $scope, $actor, prune: false);
$outcome = $seedRuns->apply($run->id, $run->planHash, $scope, $actor);   // applied, queued or busy
```

### Asynchronous apply

Route the message and give the checker a way to decide for a stored actor:

```yaml
framework:
    messenger:
        routing:
            Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun: async
nowo_seed_kit:
    messenger:
        enabled: true
        async_threshold_changes: 2000
```

```php
public function canForActor(ActorRef $actor, SeedAction $action, ProviderName $provider, SeedScope $scope): bool
{
    return $this->can($this->users->byActorRef($actor), $action, $provider, $scope); // same rules as the web
}
```

The handler applies the run once, as its actor, even when the message is delivered twice; a lock conflict or a held
lease is retried, any other refusal fails the run for good (audited).

### Current scope without an id in the path

For `/organization/seeds` ("the organization I am working in"), implement `SeedCurrentScopeInterface::currentId()` and
set `ui.current_scope`; the id is then resolved and checked by your scope resolver like an id from the URL.

`currentId()` MUST derive the id from server-side state of the authenticated actor (session or user record), never
from request input (query, header, body). It MAY return null; it MUST NOT throw for an unknown actor. The bundle treats
the result as untrusted input: it is resolved by `SeedScopeResolverInterface` (unknown or invisible ⇒ 404) and checked
by `canUseArea()` (⇒ 403), exactly like a `{scopeId}` from the URL. Runs stay bound to the scope they were planned in,
so a change of current scope makes earlier run URLs answer 404. It is called once per request and must be stateless
(worker mode).

### A run that stopped responding

A `running` run without progress for longer than `apply.lease_seconds` (its web request timed out or ran out of
memory, its worker died) is **stalled**: the run page says so and stops polling; its actor applies it again (the apply
takes it over and continues after the last committed batch) or discards it, and a viewer with `SEED_APPLY` on every
provider of the run (an admin of the kind) may discard it. The expiry sweep ends a stalled run past
`runs.document_ttl` (to `partially_applied` when batches were committed, else `expired`) and deletes its document, so
a document never outlives its TTL plus the lease.

### Document expiry

A document past `runs.document_ttl` is never used: viewing the run shows "upload again" (`SEED_005`) and ends it on the
spot, applying it answers `SEED_005`. Storage is cleaned by the sweeps that run on each upload and history page (100
rows at a time), by `nowo:seed-kit:runs:purge` and by the Scheduler bridge when you enable it (`scheduler.enabled`,
off by default because it needs `symfony/scheduler` and a Messenger setup); the checks at use make the TTL hold without
it.

