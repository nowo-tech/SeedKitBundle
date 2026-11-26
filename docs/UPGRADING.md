# Upgrading

## Table of contents

- [To 0.1.0](#to-010)
  - [First release](#first-release)
  - [Tables](#tables)
  - [Configuration](#configuration)
  - [Breaking changes](#breaking-changes)
- [Planned: to 0.2.0](#planned-to-020)

## To 0.1.0

### First release

0.1.0 is the first release of `nowo-tech/seed-kit-bundle`: there is nothing to upgrade from. Follow
[INSTALLATION.md](INSTALLATION.md).

### Tables

Create `<prefix>seed_run` and `<prefix>seed_lease` (default prefix `nowo_`) with your migrations or the Doctrine
schema tool on the entity manager of `doctrine.entity_manager` ([INSTALLATION.md](INSTALLATION.md#create-the-tables)).
The bundle ships no migrations.

### Configuration

Everything is optional. A host with tenants sets `scope_kinds`, `scope_resolver` and its access mapping
([CONFIGURATION.md](CONFIGURATION.md)); `security.prune_roles` defaults to nobody.

### Breaking changes

None (first release). The public API is the set of ports, value objects, events, enums and exceptions under
`Nowo\SeedKitBundle\Domain`, `SeedOperations`, `AbstractSeedProvider`, the console commands and the contract test kit;
0.x minors may still change it, and every change will be listed here with the steps to follow.

## To 0.2.0

### Nothing to do unless you enable the new features

`ui`, `messenger` and `scheduler` are off by default and the tables do not change (0.1 created every column 0.2
uses: `document`, `version`, `expires_at`). No migration.

### Turning the pages on

1. Require `symfony/security-bundle`, `symfony/twig-bundle`, `symfony/form`, `symfony/validator`,
   `nowo-tech/ui-kit-bundle` (^2.74.1) and `nowo-tech/form-kit-bundle` (^2.25.1); the container build fails with a
   message otherwise.
2. Configure the mounts and, if your pages live in your own shell, the layout:

   ```yaml
   nowo_seed_kit:
       ui:
           enabled: true
           layout: 'layouts/admin.html.twig'   # optional; default nowo_ui_kit.layouts.admin
           max_upload_bytes: 2097152           # default 2 MiB
           mounts:
               platform:     { path: /developer/seeds }
               organization: { path: /organization/seeds }            # needs ui.current_scope (no {scopeId})
               project:      { path: '/projects/{scopeId}/settings/seeds' }
           current_scope: App\Seed\CurrentScope                       # SeedCurrentScopeInterface
   ```
3. Import the routes: `nowo_seed_kit: { resource: ., type: nowo_seed_kit }` (config/routes/nowo_seed_kit.yaml).
4. Put the prefixes behind your firewall (`access_control` with `IS_AUTHENTICATED_FULLY`); the bundle's guard and your
   checker decide the rest.

### Turning the asynchronous apply on

Set `messenger.enabled: true`, route `Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun` to an async transport, and
implement `SeedKitAccessCheckerInterface::canForActor()` (or set `messenger.trust_run_actor: true`): without one of
them every queued run is refused for good.

### Breaking changes (0.x minor)

- `RunStoreInterface` has new methods (`document`, `findPlanned`, `transition`, `expire`) and new optional parameters
  (`create(..., ?string $document = null)`, `list(..., ?ActorRef $actor = null)`); a host implementation of the port
  must add them (the bundle's DBAL store and `Testing\Support\InMemoryRunStore` do).
- `RunStoreInterface::finish()` keeps the document of a `partially_applied` run.
- `RunRecord`, `SeedKitSettings` and `SeedKitGuard::__construct()` have new trailing optional parameters.
- `runs:purge` prints `N run(s) deleted, M stored document(s) expired.`.
