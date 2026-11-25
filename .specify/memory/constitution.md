# SeedKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behaviour lives in `docs/CONTRACT.md` (contract v1.0: owner answers Q-SK-1 … 14 and Beacon answers QB-SK-1 … 6
of 2026-11-24, plus the accepted 0.1.0 deviations), `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`
and the integrator docs (`USAGE.md`, `CONFIGURATION.md`, `FORMAT.md`, `PLAN-HASH.md`). The code is the reference for
0.1.0; demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit, PHPStan (level max, FrankenPHP rulesets) and Deptrac are the mechanical proof. Behavioural changes require
tests; the coverage of `src/` (including `src/Testing`) stays at 100 %.

### III. 100% code inventory traceability
Every production file under `src/` appears in `specs/001-baseline/code-inventory.md` with a semantic requirement id.
New, moved or removed files update the inventory in the same pull request.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`, scripts `sh`). Skills live in
`.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow the PHP (`>=8.2 <8.6`) and Symfony (`^7.4 || ^8.0`) ranges of `composer.json` and the README badges.

### VI. Standard by default, DDD opt-in
Works out of the box in a standard Symfony project (`AbstractSeedProvider`, role-based access, default resolvers);
usable from a strict DDD / hexagonal host (`SeedProviderInterface` without base class, own resolvers and checker, XML
mapping, separate entity manager). Public contracts are interfaces and value objects without Symfony, Doctrine or
HTTP types; layers are checked by Deptrac. Both host kinds are tested.

### VII. Writes through the host, exact and fail closed
The bundle never writes host data itself: providers write through the host's handlers inside the bundle's batch
transaction. The bundle never persists, flushes, clears or resets an entity manager (DBAL only). Every check that can
fail runs before the first write; plans are bound by a plan hash and batch digests; re-applying converges.
Identifiers compare byte for byte; secrets leave only as vault references; access is denied by default (prune: nobody);
messages and logs never carry values.

### VIII. Worker-safe
FrankenPHP worker mode with the kernel kept alive and not reset: no request, user or tenant state in services; the one
memo (compiled schemas) is bounded and resettable; a 1 000-request worker test proves flat memory and no leak between
two actors.

## Governance
Amendments update this file, the baseline spec when principles affect behavior, and `docs/CHANGELOG.md` when
consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-11-24 | **Last Amended**: 2026-11-24
