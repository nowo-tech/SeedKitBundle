# Seed Kit Bundle demos

| Demo | Symfony | PHP | URL |
|---|---|---|---|
| `symfony8` | 8.1 | 8.5 | http://localhost:8271 |

```bash
make up-symfony8      # prints "Demo started at: http://localhost:8271"
make setup-symfony8   # composer install + database
make down-symfony8
```

- FrankenPHP **worker mode** by default (`FRANKENPHP_MODE=worker`: kernel kept alive, not reset between requests);
  `classic` in `.env` + recreate.
- Pages use **UiKit**'s demo platform layout and default theme (REQ-DEMO-014: no CSS framework, no CDN); the login is
  a **FormKit** form with the `demo` profile (REQ-DEMO-015).
- Version 0.1.0 of the bundle has no UI: the demo shows the console (`seed:list`, `seed:export`, `seed:diff`,
  `seed:apply`) and the provider API (two providers in `symfony8/src/Seed`), plus GET downloads of the empty template,
  the current export and the JSON Schema for signed-in users (`admin` / `viewer`, password `demo`).
- The port is bound to 127.0.0.1. MySQL 8.4 runs on the private Compose network (no published port, demo credentials).
- `make demo-smoke` from the repository root boots each demo in turn and expects HTTP 200 (REQ-TEST-011).

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
