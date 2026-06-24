# Step Debugging with Xdebug

Xdebug is installed in the `shop` container. Step debugging is **off by default**
(coverage-only, zero overhead) and toggled on per developer when needed.

## Toggle

```bash
./docker.sh xdebug on       # enable step debugging (graceful apache reload)
./docker.sh xdebug off      # back to coverage-only
./docker.sh xdebug status   # show current state
```

`on` connects to your IDE on **every** request while enabled, so turn it **off**
before running the full test suite. The state is a file under `docker/xdebug/`
and survives `./docker.sh start`.

> First-time setup only: the `host.docker.internal` resolution and ini scan dir
> are applied by `docker/docker-compose.yml`. If you set this repo up before that
> change, run `./docker.sh start` once to recreate the `shop` container.

## PhpStorm

1. **Settings → PHP → Servers** → add a server:
   - Name: `o3shop` (any name)
   - Host: `localhost`, Port: `8080`
   - Debugger: Xdebug
   - **Use path mappings** → map your project root (this repo) to `/var/www/html`.
2. **Settings → PHP → Debug** → confirm Debug port `9003`.
3. **Run → Start Listening for PHP Debug Connections** (the phone icon turns green).
4. `./docker.sh xdebug on`, set a breakpoint, load a page at `http://localhost:8080`.

## VS Code

1. Install the **PHP Debug** extension (xdebug.php-debug).
2. Add to `.vscode/launch.json`:

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "pathMappings": {
        "/var/www/html": "${workspaceFolder}"
      }
    }
  ]
}
```

3. Start the "Listen for Xdebug" configuration (F5).
4. `./docker.sh xdebug on`, set a breakpoint, load a page.

## CLI debugging

With xdebug on, `oe-console` and other CLI scripts also connect:

```bash
docker compose -f docker/docker-compose.yml exec shop php bin/oe-console <command>
```

## Troubleshooting

- **No breakpoint hit?** Make sure the IDE listener is running *before* you load the page.
- **Check the connection log inside the container:**

```bash
docker compose -f docker/docker-compose.yml exec shop cat /tmp/xdebug.log
```

  `Connected to debugging client` = success. `Could not connect ... 9003` means the
  IDE isn't listening or the port is blocked.
- **`host.docker.internal` not resolving?** Confirm the `extra_hosts: host-gateway`
  entry exists in `docker/docker-compose.yml` and recreate the container with
  `./docker.sh start`.
- **Path mapping wrong?** Breakpoints stay hollow / never bind — re-check that the
  project root maps to `/var/www/html`.
