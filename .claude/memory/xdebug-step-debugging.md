---
name: xdebug-step-debugging
description: how the ./docker.sh xdebug toggle works + the Colima host-gateway gotcha that blocked #82
type: reference
---

# Xdebug step debugging (#82)

Step debugging is **opt-in** via `./docker.sh xdebug on|off|status`. Off by default =
coverage-only (`xdebug.mode=coverage`), so the test suite is unaffected.

## How the toggle works
- `docker-compose.yml` (shop service) sets `PHP_INI_SCAN_DIR=":/var/www/html/docker/xdebug"`.
  Leading `:` = scan the compiled-in default conf.d **plus** that bind-mounted dir.
- `xdebug on` copies the committed template `docker/xdebug/zz-xdebug-debug.ini.dist` to the
  active (gitignored) `docker/xdebug/zz-xdebug-debug.ini`, then `apache2ctl graceful`.
- PHP only auto-loads files ending in `.ini`, so the `.dist` template is inert until copied.
  State persists across `./docker.sh start` for free (PHP scans the host file natively).
- `status` is a pure host-file check (no docker). `on`/`off` need the container running.

## The gotcha that broke every prior attempt (#82)
On **Colima / plain Linux Docker**, `host.docker.internal` does NOT resolve inside the
container by default (it's automatic only on Docker Desktop). Xdebug initiates the
connection *to* the IDE, so without resolution it can never reach it. Fix: the shop service
has `extra_hosts: ["host.docker.internal:host-gateway"]`. Verified: resolves to the gateway
(e.g. 192.168.5.2). This single missing entry is why hand-config attempts failed.

## Apply-time costs (easy to forget)
- Changing compose `environment`/`extra_hosts` → needs a container **recreate** (`up -d`).
- Changing the baked `xdebug.mode` in the Dockerfile → needs a **rebuild**.
- The on-config uses `start_with_request=yes`, so while ON every CLI/test process attempts a
  9003 connection → slow suite. `run_tests()` prints a warning if the active ini is present;
  run `./docker.sh xdebug off` before the suite.

Dev guide: `docs/development/xdebug-step-debugging.md` (PhpStorm + VS Code). Port 9003,
path mapping repo-root ↔ `/var/www/html`.
