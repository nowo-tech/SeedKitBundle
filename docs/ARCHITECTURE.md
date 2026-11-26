# Architecture

## Table of contents

- [Layers](#layers)
- [Ports](#ports)
- [Layer rules](#layer-rules)
- [Architecture checks](#architecture-checks)
- [Flow of an apply](#flow-of-an-apply)
- [Persistence](#persistence)
- [Host kinds](#host-kinds)

Design: [CONTRACT.md](CONTRACT.md) sections 3, 4, 6 and 17.

## Layers

```
src/Domain/Model/           value objects: ProviderName, ScopeKind, ScopeId, SeedScope, NaturalKey, ActorRef, RunId,
                            SecretReference, DocumentHash, PlanHash, ExactText (identifier rules)
src/Domain/Document/        SeedDocument, DocumentSet, SeedDocumentHeader / Meta, SeedItem, ExportDocument, enums
src/Domain/Plan/            SeedPlan, DocumentPlan, RowChange, FieldChange, FieldValueView, RowError, BatchPlan,
                            AppliedBatch, BatchResult, PlanOptions, NaturalKeySet, PendingKeys, ChangeOp
src/Domain/Schema/          SeedSchema (item schema + x-seed-* keywords)
src/Domain/Provider/        AbstractSeedProvider (optional base for standard hosts), RowDiffer
src/Domain/Context/         SeedContext (scope, actor, channel, run, pending keys, prune, visibility)
src/Domain/Run/             RunRecord, RunStatus, Channel, SeedAction, BatchManifest (stored batch layout, 0.2)
src/Domain/Port/            SPI and ports (below)
src/Domain/Event/           after-commit events (value objects and ids only)
src/Domain/Exception/       SeedKitException and the coded exceptions
src/Domain/Audit/           SeedAuditRecord
src/Domain/Support/         CanonicalJson (RFC 8785 rules)
src/Application/            ProviderRegistry, ItemNormaliser, Planner, PlanHasher, Applier, Exporter, SchemaPublisher,
                            SchemaLinter, SeedOperations (entry points), SeedRuns (stored UI / Messenger runs, 0.2),
                            RunMaintenance, SeedKitSettings, value types (RunView, ApplyOutcome, …)
src/Infrastructure/Reader/  DocumentReader (YAML / JSON / JSON Lines), YamlPreScanner, JsonDuplicateKeyScanner,
                            ReaderLimits
src/Infrastructure/Writer/  DocumentWriter (YAML with comments, JSON, JSON Lines)
src/Infrastructure/Schema/  OpisSchemaValidator
src/Infrastructure/Dbal/    DbalRunStore, DbalLeaseStore, DbalWriteRunner, ConnectionOnlyRegistry, TableNames, DbalSql
src/Infrastructure/Doctrine/ read-only entities SeedRun / SeedLease, SchemaMetadataListener (prefix, collations)
src/Infrastructure/Support/ PsrLogAuditSink, UuidV7IdGenerator
src/Infrastructure/Run/     GzipRunDocumentCodec (stored document of a UI run, 0.2)
src/Infrastructure/Symfony/ Command (console), Security (guard, voter, checkers, default resolvers), Event,
                            CacheWarmer, Attribute, Controller + Form + Routing (0.2 UI: SeedKitController, FormKit
                            forms, route loader), DependencyInjection (config tree, services, compiler pass)
src/Bridge/AuditKit/        AuditKitSeedAuditSink
src/Bridge/Vault/           VaultSecretReferenceValidator
src/Bridge/Messenger/       ApplySeedRun, ApplySeedRunHandler, MessengerRunQueue (0.2)
src/Bridge/Scheduler/       SeedKitPurgeSchedule, PurgeSeedRuns, PurgeSeedRunsHandler (0.2)
src/Testing/                SeedProviderContractTestCase + in-memory support doubles (shipped for hosts)
src/Resources/config/doctrine/  XML mapping of the two tables
src/Resources/translations/     NowoSeedKitBundle.{en,es,it,fr,pt,de,nl,ca,eu,gl}.yaml
src/Resources/views/            Twig pages on UiKit scaffolds (namespace NowoSeedKitBundle, 0.2)
```

## Ports

| Port | Implemented by | Default |
| --- | --- | --- |
| `SeedProviderInterface` | Host (one per kind of data), tag `nowo_seed_kit.provider` | — |
| `SeedScopeResolverInterface` | Host (`scope_resolver`) | `PlatformScopeResolver` |
| `SeedActorResolverInterface` | Host (`actor_resolver`) | `SecurityActorResolver` |
| `SeedKitAccessCheckerInterface` | Host (`security.access_checker`) | `ConfigurableSeedKitAccessChecker` (`AllowAllSeedKitAccessChecker` for demos) |
| `SecretReferenceValidatorInterface` | Host (`secrets.validator`) or the Vault bridge | none (fail closed) |
| `SeedAuditSinkInterface` | Host (`audit.sink`) or the AuditKit bridge | `PsrLogAuditSink` |
| `SeedAccessGateInterface` | `SeedKitGuard` | — |
| `SchemaValidatorInterface` | `OpisSchemaValidator` | — |
| `DocumentWriterInterface` | `DocumentWriter` | — |
| `RunStoreInterface`, `LeaseStoreInterface` | `DbalRunStore`, `DbalLeaseStore` | in-memory doubles in `src/Testing/Support` |
| `WriteRunnerInterface` | `DbalWriteRunner` | `DirectWriteRunner` in tests |
| `IdGeneratorInterface` | `UuidV7IdGenerator` | `SequentialIdGenerator` in tests |
| `RunDocumentCodecInterface` (0.2) | `GzipRunDocumentCodec` | — |
| `RunQueueInterface` (0.2) | `MessengerRunQueue` (`messenger.enabled`) | none: no asynchronous apply |
| `SeedCurrentScopeInterface` (0.2) | Host (`ui.current_scope`) | none: mounts need `{scopeId}` for kinds with an id |

Public surface: the ports, the value objects, the events, the enums, the exceptions, `SeedOperations`,
`AbstractSeedProvider` (the only class meant to be extended, optional) and the contract test kit. Concrete services are
`final`.

## Layer rules

- **Domain** is plain PHP: no Symfony, Doctrine, HTTP or console type in any port, value object, event or exception
  signature (REQ-ARCH-001 rule 3); it may use PSR interfaces and `nowo-tech/transaction-contract`.
- **Application** depends on Domain (and PSR clock / log / event-dispatcher / container, transaction-contract's
  `ConcurrencyConflict`); it never names a Symfony or Doctrine type and never uses transaction-doctrine.
- **Infrastructure** adapts the ports: DBAL stores and write runner, Doctrine mapping, reader and writer (Symfony
  YAML), opis adapter.
- **UI** (`Infrastructure\Symfony\{Command, Security, Event, CacheWarmer, Attribute}`) is the framework edge: console
  commands, guard and voter, the access event, the lint warmer and the provider attribute.
- **Bridge** adapts optional packages (AuditKit, VaultBundle) to Domain ports.
- **DependencyInjection** (`Infrastructure\Symfony\DependencyInjection` and the bundle class) is the composition root
  and may see every layer.
- **Testing** (`src/Testing`) is shipped for hosts; nothing else in `src/` depends on it at runtime.
- The bundle never calls `persist()`, `flush()`, `clear()` or `resetManager()` on any entity manager.

## Architecture checks

[Deptrac](https://github.com/deptrac/deptrac) checks the dependencies between the layers (REQ-ARCH-002; `deptrac.yaml`,
`composer deptrac`, run in `make release-check` and in the CI PHPStan job). The run is strict: `deptrac analyse
--no-progress --fail-on-uncovered --report-uncovered`, then `deptrac debug:unassigned`. A violation, a dependency on
an unlisted vendor namespace, or a class under `src/` that belongs to no layer fails the build. The expected result is
"Violations 0, Uncovered 0" and "There are no unassigned tokens".

| Deptrac layer | Namespaces | May depend on |
| --- | --- | --- |
| `Domain` | `Domain` | itself, `psr/*` contracts, `nowo-tech/transaction-contract` |
| `Application` | `Application` | `Domain`, the same contract packages |
| `Infrastructure` | `Infrastructure` except the UI edge and DI below | `Domain`, `Application`, Symfony, Doctrine, opis/json-schema, transaction-doctrine |
| `UI` | `Infrastructure\Symfony\{Command, Security, Event, CacheWarmer, Attribute}` | `Domain`, `Application`, `Infrastructure`, Symfony |
| `Bridge` | `Bridge` | `Domain`, `Application`, the bridged packages (AuditKit, VaultBundle) |
| `DependencyInjection` | `Infrastructure\Symfony\DependencyInjection`, `NowoSeedKitBundle` | every layer (composition root) |
| `Testing` | `Testing` | `Domain`, `Application`, PHPUnit |

`skip_violations` stays empty: transaction-contract's `ConcurrencyConflict` is a framework-free contract type, allowed
in Application by REQ-ARCH-002 (owner 2026-11-24). The list may only shrink.

## Flow of an apply

```
console ApplyCommand ─▶ CommandSupport (actor, scope, confined path) ─▶ DocumentReader ─▶ DocumentSet
        ─▶ SeedOperations::diff()   resolve scope (host port) · guard SEED_APPLY / SEED_PRUNE · Planner (dry run)
        ─▶ prompt or --yes / --expect-hash
        ─▶ SeedOperations::apply()  resolve scope · guard · Applier
               leases ─▶ re-plan + stale check ─▶ run row ─▶ per batch: write unit { re-plan with lock ·
               digest check · provider applyBatch() · markBatch() } ─▶ afterBatch() ─▶ renew leases
        ─▶ finish run ─▶ events ─▶ audit sink
```

## Persistence

Two bundle tables on the connection of `doctrine.entity_manager`, written only through DBAL in transaction-doctrine
write units (`DbalWriteRunner` + `ConnectionOnlyRegistry`), and mapped read-only (XML by default, attributes on request)
so schema tools, migrations and a host's drift gate see them. `SchemaMetadataListener` adds the table prefix and, on
MySQL / MariaDB, binary NO PAD collations on identifier columns. Host data is written only by providers, through the
host's own handlers ([CONCURRENCY.md](CONCURRENCY.md)).

## Host kinds

| Topic | Standard Symfony host | DDD / hexagonal host |
| --- | --- | --- |
| Provider | Extend `AbstractSeedProvider`, call the CRUD's services / repositories | Implement `SeedProviderInterface` in the infrastructure layer, call application command handlers |
| Registration | `#[AsSeedProvider]` with autoconfiguration | Explicit `nowo_seed_kit.provider` tag |
| Scope / actor | Default resolvers (platform scope; user identifier) | Own resolvers mapping to tenant value objects |
| Storage | Default entity manager; XML or attribute mapping | Separate entity manager / connection by config; XML mapping |
| Access | `security.*_roles` | Own checker, voter or `access_check` listener over permission sets |
| Events | Symfony dispatcher, after commit | Same; the host maps them to its bus |

Both kinds run in the test suite: `BundleWiringTest` (standard defaults; DDD host with its own connection, resolvers
and checker), `ApplyTest::theDddHostAppliesInItsOwnScopesOnItsOwnConnection`, and
`SchemaTest::xmlAndAttributeMappingsGiveTheSameSchema`.
