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

## Planned: to 0.2.0

0.2.0 adds the import / export pages (UiKit + FormKit) and the Messenger apply. Expected host actions, to be confirmed
by the 0.2.0 section: new configuration sections `ui` and `messenger` (off by default), route mounts per scope kind,
`canForActor()` in your access checker (or `messenger.trust_run_actor`) for queued applies, and possibly new columns in
`<prefix>seed_run` (a migration).
