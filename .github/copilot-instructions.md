## AI contribution guidelines (Nowo Symfony bundle)

Use this when suggesting code, tests, documentation, or CI changes for this repository.

### Scope

- This is **SeedKitBundle** (`nowo-tech/seed-kit-bundle`), a **Symfony bundle** published on Packagist: seed data
  through providers (one per kind of data), YAML / JSON / JSON Lines documents validated by a JSON Schema, a dry run
  with a plan hash and an idempotent batched apply through the host's own handlers. Design: `docs/CONTRACT.md`.
- Respect the **PHP** and **Symfony** version ranges declared in `composer.json`.
- Prefer **PHP 8 attributes** for configuration and metadata. Do not introduce `doctrine/annotations` for new code.

### Code

- Follow **PER Coding Style** (`@PER-CS` + `@Symfony`) and the project conventions in `.php-cs-fixer.dist.php`.
- Respect the layers checked by Deptrac: `Domain` (plain PHP, no Symfony / Doctrine / HTTP / Console types),
  `Application`, `Infrastructure`, UI edge (`Infrastructure/Symfony/{Command,Security,Event,CacheWarmer,Attribute}`),
  `DependencyInjection`, `Bridge`, `Testing`.
- Never persist, flush, clear or reset an EntityManager in `src/`; the bundle writes its tables through DBAL write units.
- Keep the code worker-safe (FrankenPHP, kernel not reset): no request, user or tenant state in services or statics.
- Exception messages and logs are static: never a value from a seed file, a natural key, a path or a secret.
- Use **strict comparison** (`===`) where appropriate.
- Keep changes **minimal** and consistent with existing patterns in `src/` and `tests/`.
- Align with `composer cs-check`, `composer phpstan`, and `composer test` expectations.

### Documentation

- User-facing documentation is **English** under `docs/` per Nowo bundle standards.
- Only `README.md` and `CODE_OF_CONDUCT.md` at repository root (no extra root markdown files).
- Keep `specs/001-baseline/code-inventory.md` in sync with `src/` and `docs/CHANGELOG.md` (`[Unreleased]`) current.

### Tests

- Add or update tests for new behaviour; keep coverage of `src/` at 100 % (`make test-coverage-100`); PHPUnit attributes.
- Database behaviour is tested on SQLite and, in CI, on MySQL 8.4, MariaDB 11.4 and PostgreSQL (`make test-with-db`).

## Commits

- Never add AI co-author trailers: no `Co-authored-by: Cursor` / `cursoragent@cursor.com`, no `Co-Authored-By: Claude …` / `noreply@anthropic.com` (REQ-GIT-001).
