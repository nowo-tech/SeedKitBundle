# Release process

## Table of contents

- [Version plan](#version-plan)
- [Before each 0.x tag and 1.0.0](#before-each-0x-tag-and-100)
- [Standard workflow](#standard-workflow)
- [After the tag](#after-the-tag)

## Version plan

Contract Q-SK-1: **0.1.0** core + console + contract test kit + demo, **0.2.0** import / export pages (UiKit +
FormKit) and the Messenger apply, **1.0.0** after `bundle-req-audit` at 100 % and REQ-SEC-004. Both 0.x tags wait
for an **immediate** security audit (an import is a write path with permissions and secrets). The package
`nowo-tech/seed-kit-bundle` is already registered on Packagist by the owner; nothing to do there before the tag.

## Before each 0.x tag and 1.0.0

1. The immediate security audit of the slice is recorded with a passing grade (REQ-SEC-004); every blocking finding
   is fixed and checked again. The tag never comes before the audit.
2. The [release security checklist (12.4.1)](SECURITY.md#release-security-checklist-1241) is confirmed.
3. No open pull request is left (`make check-open-prs`, REQ-REL-003).
4. [CHANGELOG.md](CHANGELOG.md): `[Unreleased]` becomes `## [0.x.0] - YYYY-MM-DD` in the release commit.
5. [UPGRADING.md](UPGRADING.md): the section for that version is current (schema changes, configuration).
6. [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md): audited revision set to the tag.
7. `make release-check` green (co-author and open-PR checks, CS, Rector, PHPStan, PHPDoc, Igor, translations,
   coverage 100 %, demos).
8. CI green on the exact release commit, including "Databases" and "Dependency floor".

## Standard workflow

1. Release commit on `main`: move `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md) to `## [x.y.z] - YYYY-MM-DD` and
   update [UPGRADING.md](UPGRADING.md); update [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md) and
   [SECURITY.md](SECURITY.md) when behaviour changed.
2. Run `make check-no-cursor-coauthor` after the release commit and before `git push` (REQ-GIT-001).
3. Wait for CI on that commit.
4. Annotated tag: `git tag -a vx.y.z -m "Release vx.y.z"` (the message becomes the release body), then
   `make check-no-cursor-coauthor` again and push the tag.
5. `release.yml` creates the GitHub Release; `sync-releases.yml` fills missing changelog bodies.

## After the tag

- Check the GitHub Release and the Packagist version.
- Publish any approved GitHub Security Advisory for the fixed versions.
- Update [ROADMAP.md](ROADMAP.md) (slice status).
