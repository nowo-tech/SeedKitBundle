# Contributing

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Development setup](#development-setup)
- [Code style](#code-style)
- [Tests](#tests)
- [Pull requests](#pull-requests)
- [Cursor Agent](#cursor-agent)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are
expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

1. Clone the repository and install the git hooks: `make setup-hooks`.
2. Start the dev container: `make up` then `make install`.
3. Run the checks: `make test`, `make test-coverage-100`, `make test-with-db` (database suite on the MySQL 8.4 service
   of the `db` Compose profile), `make cs-check`, `make phpstan`, `make validate-phpdoc`, `make igor`,
   `make validate-translations`, `composer deptrac` (layer rules).
4. The demo: `make -C demo up-symfony8`, `make -C demo/symfony8 seed`, then `make test-e2e` and `make demo-smoke`.
5. Pre-release: `make release-check`.

## Code style

- PHP-CS-Fixer with `@PER-CS` + `@PER-CS:risky` and `@Symfony`: `make cs-fix` / `make cs-check`.
- PHPStan level max with the `nowo-tech/phpstan-frankenphp` rulesets, no baseline; Rector dry run; Igor.
- PHPDoc on every class and public method of `src/` (`make validate-phpdoc`), in **English**, like every Markdown file.
- `declare(strict_types=1)` everywhere; `final` and `readonly` by default; Domain types never name Symfony, Doctrine
  or HTTP classes (Deptrac checks it, [ARCHITECTURE.md](ARCHITECTURE.md#architecture-checks)).
- Messages and logs are static: never put a value from a seed file, a natural key, a path or a secret in an exception
  message or a log record.

## Tests

- Suites: `Unit`, `Integration` (kernel, console, security, DI, worker) and `Database`
  (`vendor/bin/phpunit --testsuite Database`). The database suite runs on SQLite by default and on a server with
  `NOWO_TEST_DATABASE_URL` (`make test-with-db`); CI runs it on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL. The
  two-connection lease and lock tests need a server.
- Coverage of `src/` (including `src/Testing`) stays at 100 % (`make test-coverage-100`).
- The worker test (`tests/Integration/Worker`) must stay green: one kernel, 1 000 mixed requests, two actors, flat
  memory.
- A change to `PlanHasher` or `CanonicalJson` changes every plan hash: update the vectors of
  [PLAN-HASH.md](PLAN-HASH.md) and treat it as a breaking change.
- Every file added, moved or removed under `src/` updates `specs/001-baseline/code-inventory.md` in the same pull
  request.

## Pull requests

- Target `main` with a conventional-commit title (`feat:`, `fix:`, `docs:`, `chore:`, …; checked by the PR lint).
- Merge method: squash merge.
- Ensure `make release-check` passes.
- Keep `docs/CHANGELOG.md` (`[Unreleased]`) and the docs updated when behaviour or configuration changes.

## Cursor Agent

Two user-level Cursor settings keep the agent chat from sending half-written prompts (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**): Cursor Settings → Agents / Chat → Submit with Ctrl + Enter
   (stored as `chatSubmitOnCmdEnter`). Enter then inserts a newline; Ctrl/⌘+Enter submits the prompt.
2. **Queue follow-ups while the agent is running**: in your user `settings.json` (Linux
   `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS
   `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

   With `queue` the next message waits until the current turn ends; do not use `steer` as the default, because it
   interrupts the running agent as soon as a follow-up is sent.

The repository ships its agent setup in `.cursor/` (rules, the Engram MCP entry and the Spec Kit skills); see
[ENGRAM.md](ENGRAM.md) and [SPEC-KIT.md](SPEC-KIT.md).

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` /
`noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks               # installs .githooks/commit-msg (strips those trailers) and .githooks/pre-commit
make check-no-cursor-coauthor  # after committing: the history must be clean
```

If trailers were already pushed, `make strip-cursor-coauthor-from-history` rewrites the history (last resort, then a
force-push needs the owner). CI checks the whole history in the "Git history" job ([GITHUB_CI.md](GITHUB_CI.md)).
