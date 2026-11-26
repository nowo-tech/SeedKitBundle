# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/seed-kit-bundle`  
**Last audited**: 2026-11-26 (0.2.0, branch `feat/0.2.0-ui-messenger`)

Scope: every file under `src/` (PHP classes, interfaces and enums, the two Doctrine XML mappings, the Twig templates, the
translation catalogues; `src/Testing` ships with the package and counts as production). Audit command: `find src -type f | wc -l`
→ **185**. Requirement IDs are defined in [`spec.md`](spec.md#functional-requirements).

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Application/Applier.php` | Apply | FR-APPLY-001, FR-RUN-002 |
| `src/Application/ApplyOutcome.php` | Stored runs | FR-RUN-002 |
| `src/Application/ApplyResult.php` | Apply | FR-APPLY-001 |
| `src/Application/BatchStep.php` | Apply | FR-APPLY-001 |
| `src/Application/ErrorBag.php` | Plan | FR-PLAN-001 |
| `src/Application/Exporter.php` | Export | FR-EXPORT-001 |
| `src/Application/ItemNormaliser.php` | Plan | FR-PLAN-001 |
| `src/Application/PlanHasher.php` | Plan | FR-PLAN-002 |
| `src/Application/Planner.php` | Plan | FR-PLAN-001 |
| `src/Application/PreparedDocument.php` | Plan | FR-PLAN-001 |
| `src/Application/ProviderRegistry.php` | Provider SPI | FR-PROVIDER-001 |
| `src/Application/PruneOptions.php` | Apply | FR-APPLY-002 |
| `src/Application/RunMaintenance.php` | Storage | FR-STORAGE-002, FR-RUN-003 |
| `src/Application/RunView.php` | UI | FR-UI-002 |
| `src/Application/SchemaLinter.php` | Schema | FR-SCHEMA-002 |
| `src/Application/SchemaPublisher.php` | Schema | FR-SCHEMA-002 |
| `src/Application/SeedKitSettings.php` | Bundle and DI | FR-BUNDLE-001, FR-BUNDLE-002 |
| `src/Application/SeedOperations.php` | Access | FR-ACCESS-001, FR-ACCESS-003 |
| `src/Application/SeedRuns.php` | Stored runs | FR-RUN-001, FR-RUN-002, FR-RUN-003 |
| `src/Bridge/AuditKit/AuditKitSeedAuditSink.php` | Audit and events | FR-AUDIT-001 |
| `src/Bridge/Messenger/ApplySeedRun.php` | Messenger and Scheduler | FR-ASYNC-001 |
| `src/Bridge/Messenger/ApplySeedRunHandler.php` | Messenger and Scheduler | FR-ASYNC-001 |
| `src/Bridge/Messenger/MessengerRunQueue.php` | Messenger and Scheduler | FR-ASYNC-001 |
| `src/Bridge/Scheduler/PurgeSeedRuns.php` | Messenger and Scheduler | FR-ASYNC-002 |
| `src/Bridge/Scheduler/PurgeSeedRunsHandler.php` | Messenger and Scheduler | FR-ASYNC-002 |
| `src/Bridge/Scheduler/SeedKitPurgeSchedule.php` | Messenger and Scheduler | FR-ASYNC-002 |
| `src/Bridge/Vault/VaultSecretReferenceValidator.php` | Secrets | FR-SECRET-001, FR-SECRET-002 |
| `src/Domain/Audit/SeedAuditRecord.php` | Audit and events | FR-AUDIT-001 |
| `src/Domain/Context/SeedContext.php` | Provider SPI | FR-PROVIDER-001 |
| `src/Domain/Document/DocumentFormat.php` | Documents and reader | FR-READ-001 |
| `src/Domain/Document/DocumentSet.php` | Documents and reader | FR-READ-001 |
| `src/Domain/Document/ExportDocument.php` | Export | FR-EXPORT-001 |
| `src/Domain/Document/ExportMode.php` | Export | FR-EXPORT-001 |
| `src/Domain/Document/SeedDocument.php` | Documents and reader | FR-READ-001 |
| `src/Domain/Document/SeedDocumentHeader.php` | Documents and reader | FR-READ-001 |
| `src/Domain/Document/SeedDocumentMeta.php` | Documents and reader | FR-READ-001 |
| `src/Domain/Document/SeedItem.php` | Documents and reader | FR-READ-001 |
| `src/Domain/Event/SeedBatchCommittedEvent.php` | Audit and events | FR-EVENT-001 |
| `src/Domain/Event/SeedExportedEvent.php` | Audit and events | FR-EVENT-001 |
| `src/Domain/Event/SeedRunAppliedEvent.php` | Audit and events | FR-EVENT-001 |
| `src/Domain/Event/SeedRunFailedEvent.php` | Audit and events | FR-EVENT-001 |
| `src/Domain/Event/SeedRunPlannedEvent.php` | Audit and events | FR-EVENT-001 |
| `src/Domain/Exception/AccessDeniedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/ApplyInterruptedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/DocumentInvalidException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/DocumentReadException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/InvalidValueException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/LeaseHeldException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/PlanTooLargeException.php` | Errors | FR-RUN-002 |
| `src/Domain/Exception/ProviderContractException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/PruneRefusedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/RunNotAvailableException.php` | Errors | FR-RUN-002 |
| `src/Domain/Exception/ScopeMismatchException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/ScopeNotFoundException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/SecretValueRefusedException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/SeedKitException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/StalePlanException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Exception/UnknownProviderException.php` | Errors | FR-ERROR-001 |
| `src/Domain/Model/ActorRef.php` | Identifiers | FR-ID-001 |
| `src/Domain/Model/DocumentHash.php` | Identifiers | FR-ID-001 |
| `src/Domain/Model/ExactText.php` | Identifiers | FR-ID-001 |
| `src/Domain/Model/NaturalKey.php` | Identifiers | FR-ID-001 |
| `src/Domain/Model/PlanHash.php` | Plan | FR-PLAN-002 |
| `src/Domain/Model/ProviderName.php` | Identifiers | FR-ID-001 |
| `src/Domain/Model/RunId.php` | Identifiers | FR-ID-001 |
| `src/Domain/Model/ScopeId.php` | Identifiers | FR-ID-001 |
| `src/Domain/Model/ScopeKind.php` | Identifiers | FR-ID-001 |
| `src/Domain/Model/SecretReference.php` | Secrets | FR-SECRET-001 |
| `src/Domain/Model/SeedScope.php` | Identifiers | FR-ID-001 |
| `src/Domain/Plan/AppliedBatch.php` | Apply | FR-APPLY-001 |
| `src/Domain/Plan/BatchPlan.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/BatchResult.php` | Apply | FR-APPLY-001 |
| `src/Domain/Plan/ChangeOp.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/DocumentPlan.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/FieldChange.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/FieldValueView.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/NaturalKeySet.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/PendingKeys.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/PlanOptions.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/RowChange.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/RowError.php` | Plan | FR-PLAN-001 |
| `src/Domain/Plan/SeedPlan.php` | Plan | FR-PLAN-001 |
| `src/Domain/Port/DocumentWriterInterface.php` | Export | FR-EXPORT-001 |
| `src/Domain/Port/IdGeneratorInterface.php` | Apply | FR-APPLY-001 |
| `src/Domain/Port/LeaseStoreInterface.php` | Apply | FR-APPLY-001 |
| `src/Domain/Port/RunDocumentCodecInterface.php` | Stored runs | FR-RUN-001 |
| `src/Domain/Port/RunQueueInterface.php` | Messenger and Scheduler | FR-ASYNC-001 |
| `src/Domain/Port/RunStoreInterface.php` | Storage | FR-STORAGE-001, FR-RUN-001 |
| `src/Domain/Port/SchemaValidatorInterface.php` | Schema | FR-SCHEMA-001 |
| `src/Domain/Port/SecretReferenceValidatorInterface.php` | Secrets | FR-SECRET-001 |
| `src/Domain/Port/SeedAccessGateInterface.php` | Access | FR-ACCESS-001 |
| `src/Domain/Port/SeedActorResolverInterface.php` | Access | FR-ACCESS-001, FR-ACCESS-003 |
| `src/Domain/Port/SeedAuditSinkInterface.php` | Audit and events | FR-AUDIT-001 |
| `src/Domain/Port/SeedCurrentScopeInterface.php` | UI | FR-UI-001 |
| `src/Domain/Port/SeedKitAccessCheckerInterface.php` | Access | FR-ACCESS-001 |
| `src/Domain/Port/SeedProviderInterface.php` | Provider SPI | FR-PROVIDER-001 |
| `src/Domain/Port/SeedScopeResolverInterface.php` | Access | FR-ACCESS-001, FR-ACCESS-003 |
| `src/Domain/Port/WriteRunnerInterface.php` | Apply | FR-APPLY-001 |
| `src/Domain/Provider/AbstractSeedProvider.php` | Provider SPI | FR-PROVIDER-002 |
| `src/Domain/Provider/RowDiffer.php` | Provider SPI | FR-PROVIDER-002 |
| `src/Domain/Run/BatchManifest.php` | Stored runs | FR-RUN-001 |
| `src/Domain/Run/Channel.php` | Access | FR-ACCESS-001 |
| `src/Domain/Run/RunRecord.php` | Storage | FR-STORAGE-001 |
| `src/Domain/Run/RunStatus.php` | Storage | FR-STORAGE-001 |
| `src/Domain/Run/SeedAction.php` | Access | FR-ACCESS-001 |
| `src/Domain/Schema/SeedSchema.php` | Schema | FR-SCHEMA-001 |
| `src/Domain/Support/CanonicalJson.php` | Plan | FR-PLAN-002 |
| `src/Infrastructure/Dbal/ConnectionOnlyRegistry.php` | Concurrency | FR-CONCURRENCY-001 |
| `src/Infrastructure/Dbal/DbalLeaseStore.php` | Concurrency | FR-CONCURRENCY-001 |
| `src/Infrastructure/Dbal/DbalRunStore.php` | Storage | FR-STORAGE-001, FR-RUN-001 |
| `src/Infrastructure/Dbal/DbalSql.php` | Storage | FR-STORAGE-001 |
| `src/Infrastructure/Dbal/DbalWriteRunner.php` | Concurrency | FR-CONCURRENCY-001 |
| `src/Infrastructure/Dbal/TableNames.php` | Storage | FR-STORAGE-001 |
| `src/Infrastructure/Doctrine/Entity/SeedLease.php` | Storage | FR-STORAGE-001 |
| `src/Infrastructure/Doctrine/Entity/SeedRun.php` | Storage | FR-STORAGE-001 |
| `src/Infrastructure/Doctrine/SchemaMetadataListener.php` | Storage | FR-STORAGE-001 |
| `src/Infrastructure/Reader/DocumentReader.php` | Documents and reader | FR-READ-001 |
| `src/Infrastructure/Reader/JsonDuplicateKeyScanner.php` | Documents and reader | FR-READ-001, FR-READ-002 |
| `src/Infrastructure/Reader/ReaderLimits.php` | Documents and reader | FR-READ-001, FR-READ-002 |
| `src/Infrastructure/Reader/YamlPreScanner.php` | Documents and reader | FR-READ-001, FR-READ-002 |
| `src/Infrastructure/Run/GzipRunDocumentCodec.php` | Stored runs | FR-RUN-001 |
| `src/Infrastructure/Schema/OpisSchemaValidator.php` | Schema | FR-SCHEMA-001 |
| `src/Infrastructure/Support/PsrLogAuditSink.php` | Audit and events | FR-AUDIT-001 |
| `src/Infrastructure/Support/UuidV7IdGenerator.php` | Apply | FR-APPLY-001 |
| `src/Infrastructure/Symfony/Attribute/AsSeedProvider.php` | Provider SPI | FR-PROVIDER-001 |
| `src/Infrastructure/Symfony/CacheWarmer/SchemaLintWarmer.php` | Schema | FR-SCHEMA-002 |
| `src/Infrastructure/Symfony/Command/ActorForgetCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/ApplyCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/CommandSupport.php` | Console | FR-CLI-001, FR-CLI-002 |
| `src/Infrastructure/Symfony/Command/ConsolePaths.php` | Console | FR-CLI-001, FR-CLI-002 |
| `src/Infrastructure/Symfony/Command/DiffCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/ExportCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/LintCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/ListCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/PlanPrinter.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/RunsListCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/RunsPurgeCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Command/SchemaCommand.php` | Console | FR-CLI-001 |
| `src/Infrastructure/Symfony/Controller/SeedKitController.php` | UI | FR-UI-001, FR-UI-002 |
| `src/Infrastructure/Symfony/Controller/UiOptions.php` | UI | FR-UI-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/SeedProviderPass.php` | Provider SPI | FR-PROVIDER-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ConfigurationTree.php` | Bundle and DI | FR-BUNDLE-001, FR-BUNDLE-002, FR-UI-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Requirement.php` | Bundle and DI | FR-BUNDLE-001, FR-BUNDLE-002 |
| `src/Infrastructure/Symfony/DependencyInjection/ServiceDefinitions.php` | Bundle and DI | FR-BUNDLE-001, FR-BUNDLE-002, FR-UI-001 |
| `src/Infrastructure/Symfony/Event/SeedAccessCheckEvent.php` | Access | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Event/SeedKitEvents.php` | Access | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Form/FormProfile.php` | UI | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/SeedActionType.php` | UI | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/SeedApplyType.php` | UI | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/SeedUploadType.php` | UI | FR-UI-003 |
| `src/Infrastructure/Symfony/Routing/SeedKitRouteLoader.php` | UI | FR-UI-001 |
| `src/Infrastructure/Symfony/Security/AllowAllSeedKitAccessChecker.php` | Access | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/ConfigurableSeedKitAccessChecker.php` | Access | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/PlatformScopeResolver.php` | Access | FR-ACCESS-001, FR-ACCESS-003 |
| `src/Infrastructure/Symfony/Security/SecurityActorResolver.php` | Access | FR-ACCESS-001, FR-ACCESS-003 |
| `src/Infrastructure/Symfony/Security/SeedKitGuard.php` | Access | FR-ACCESS-001, FR-UI-001 |
| `src/Infrastructure/Symfony/Security/SeedKitVoter.php` | Access | FR-ACCESS-002 |
| `src/Infrastructure/Symfony/Security/SeedPermissionSubject.php` | Access | FR-ACCESS-002 |
| `src/Infrastructure/Writer/DocumentWriter.php` | Export | FR-EXPORT-001 |
| `src/NowoSeedKitBundle.php` | Bundle and DI | FR-BUNDLE-001, FR-BUNDLE-002, FR-UI-001 |
| `src/Resources/config/doctrine/SeedLease.orm.xml` | Storage | FR-STORAGE-001 |
| `src/Resources/config/doctrine/SeedRun.orm.xml` | Storage | FR-STORAGE-001 |
| `src/Resources/translations/NowoSeedKitBundle.ca.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.eu.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.gl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSeedKitBundle.pt.yaml` | Translations | FR-I18N-001 |
| `src/Resources/views/_upload_errors.html.twig` | UI | FR-UI-004 |
| `src/Resources/views/index.html.twig` | UI | FR-UI-004 |
| `src/Resources/views/message.html.twig` | UI | FR-UI-004 |
| `src/Resources/views/run.html.twig` | UI | FR-UI-004 |
| `src/Resources/views/runs.html.twig` | UI | FR-UI-004 |
| `src/Resources/views/upload.html.twig` | UI | FR-UI-004 |
| `src/Resources/views/upload_fragment.html.twig` | UI | FR-UI-004 |
| `src/Testing/SeedProviderContractTestCase.php` | Contract test kit | FR-TESTKIT-001 |
| `src/Testing/Support/DirectWriteRunner.php` | Contract test kit | FR-TESTKIT-001 |
| `src/Testing/Support/GrantAllGate.php` | Contract test kit | FR-TESTKIT-001 |
| `src/Testing/Support/InMemoryLeaseStore.php` | Contract test kit | FR-TESTKIT-001 |
| `src/Testing/Support/InMemoryRunStore.php` | Contract test kit | FR-TESTKIT-001, FR-RUN-001 |
| `src/Testing/Support/SequentialIdGenerator.php` | Contract test kit | FR-TESTKIT-001 |

## Coverage summary

| Spec section | Files |
| --- | --- |
| Bundle and DI | 5 |
| Provider SPI | 7 |
| Identifiers | 9 |
| Documents and reader | 10 |
| Schema | 6 |
| Export | 5 |
| Plan | 18 |
| Apply | 10 |
| Concurrency | 3 |
| Storage | 12 |
| Stored runs | 5 |
| Secrets | 3 |
| Access | 16 |
| UI | 16 |
| Messenger and Scheduler | 7 |
| Audit and events | 9 |
| Errors | 16 |
| Console | 12 |
| Contract test kit | 6 |
| Translations | 10 |
| **Total** | **185** |

Mapped files: **185 / 185** (100%). Excluded from the inventory: `tests/`, `demo/`, `docs/` and the repository tooling.
