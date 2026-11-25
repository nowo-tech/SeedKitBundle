# PSR compliance

Package: `nowo-tech/seed-kit-bundle` (`symfony-bundle`)

| PSR | Status | Notes |
| --- | --- | --- |
| PSR-1 / PER Coding Style (PSR-12 successor) | **Adopted** | PHP-CS-Fixer (`@PER-CS` + `@PER-CS:risky`, `@Symfony`), `make cs-check` in CI |
| PSR-4 Autoloading | **Adopted** | `Nowo\SeedKitBundle\` maps to `src/`, `Nowo\SeedKitBundle\Tests\` to `tests/` |
| PSR-3 Logger | **Adopted** | Channel `seed_kit`; static messages with structured context (provider, scope, counts, hashes, run id); never a value, natural key or path; default audit sink |
| PSR-14 Event dispatcher | **Adopted** | Domain events are plain classes dispatched after the commit (value objects and ids only); the access check is a named Symfony event |
| PSR-20 Clock | **Adopted** | Run timestamps, lease expiry and export dates read an injected clock (`MockClock` in tests) |
| PSR-11 Container | **Adopted** | The provider registry receives a service locator (`Psr\Container\ContainerInterface`); no service pulls the container |
| PSR-6 / PSR-16 Cache | **N/A** | No cache; the only memo (compiled schemas) is an in-process bounded map ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)) |
| PSR-7 / PSR-15 / PSR-17 / PSR-18 | **N/A** | No HTTP in 0.1: no controller, no outbound call |
