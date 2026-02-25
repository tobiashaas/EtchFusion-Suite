# AGENTS.md

## Cursor Cloud specific instructions

### Overview

Etch Fusion Suite is a WordPress plugin that migrates sites from Bricks Builder to Etch. Development uses `@wordpress/env` (Docker) with a dual-instance setup. See `CLAUDE.md` for full project documentation and all standard commands.

### System prerequisites (pre-installed in the VM snapshot)

- **PHP 8.1** (from `ppa:ondrej/php`) with extensions: cli, curl, mbstring, xml, zip, mysql, sqlite3, intl, xdebug
- **Composer** (globally at `/usr/local/bin/composer`)
- **Docker CE** with `fuse-overlayfs` storage driver and `iptables-legacy` (required for DinD in Cloud Agent VM)
- **Node.js >=20.19** (already provided by the base image)
- **SVN and MySQL client** (for WP test suite installation)

### Starting Docker

Docker must be started manually before running `wp-env`:

```bash
sudo nohup dockerd > /tmp/dockerd.log 2>&1 &
sleep 4
sudo chmod 666 /var/run/docker.sock
```

### Starting the WordPress environment

The full `npm run dev` (from `etch-fusion-suite/`) requires commercial plugin ZIPs in `local-plugins/` (Bricks Builder, Etch Plugin, Etch Theme). Without them, use `npx wp-env start` directly:

```bash
cd etch-fusion-suite && npx wp-env start
```

This starts two WordPress instances:
- **Port 8888** — Bricks source site (development)
- **Port 8889** — Etch target site (tests)
- Login: `admin` / `password`

### Running PHPUnit tests

**Plugin-level unit tests** (107 tests) require the WordPress test suite installed against the Docker MySQL:

```bash
cd etch-fusion-suite
bash install-wp-tests.sh wordpress_test root password 127.0.0.1:$(docker port bricks-mysql 3306 | head -1 | cut -d: -f2) latest true
WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress composer test:unit
```

The MySQL port is dynamically assigned by wp-env; query it via `docker port bricks-mysql 3306`. Password is `password`.

**Root-level tests** can run without MySQL using `EFS_SKIP_WP_LOAD=1`:

```bash
EFS_SKIP_WP_LOAD=1 php vendor/bin/phpunit -c phpunit.xml.dist
```

### Linting and type checking

All commands run from `etch-fusion-suite/`:

- `composer lint` — PHPCS (WordPress coding standards)
- `npm run lint` — ESLint for JS
- `npm run typecheck` — TypeScript check

### Commercial plugins

The full `npm run dev` requires commercial plugin ZIPs in `etch-fusion-suite/local-plugins/`. The setup script (`node scripts/setup-commercial-plugins.js`) auto-detects versioned ZIPs and creates `-latest.zip` copies:

| Plugin | Required | Naming pattern |
|---|---|---|
| Bricks Builder | Yes | `bricks-*.zip` (not `bricks-child`) |
| Etch Plugin | Yes | `etch-<version>.zip` (not `etch-theme`) |
| Etch Theme | Yes | `etch-theme-*.zip` |
| Frames | No | `frames-*.zip` |
| Automatic.css | No | `automatic*.zip` |

License keys go in `etch-fusion-suite/.env` (copy from `.env.example`).

### Key gotchas

- The Composer `--no-dev` flag is used inside Docker containers; for local dev testing with PHPUnit/PHPCS, always use `composer install` (with dev dependencies) in `etch-fusion-suite/`.
- The `postinstall` npm script runs `patch-package` which patches `@wordpress/env`. Always use `npm install` (not `npm ci`) if the lockfile hasn't changed but patches need reapplying.
- Root-level PHPUnit (`phpunit.xml.dist`) uses PHPUnit 10.5 with a different bootstrap than plugin-level PHPUnit 9.6.
- `npm run health` has a pre-existing SyntaxError (`await` outside `async` function in `health-check.js`). Use direct `wp-env run cli wp ...` commands to verify environment health instead.
