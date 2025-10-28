# Bricks to Etch Migration Test Environment

## ⚠️ IMPORTANT

**The Docker Compose setup described in this document is deprecated.**

Please use the npm-based wp-env workflow instead.

**See [`../etch-fusion-suite/README.md`](../etch-fusion-suite/README.md) for current setup instructions.**

This document is retained for reference only.

---

## Overview

The legacy Docker environment has been replaced by the official `@wordpress/env` tool. wp-env provisions two WordPress sites automatically:

- **Bricks Source** – http://localhost:8888 (development environment)
- **Etch Target** – http://localhost:8889 (tests environment)

Each site is fully isolated, auto-installs WordPress core, and mounts the plugin folder for live development.

## Setup

1. Change into the plugin directory:
   ```bash
   cd ../etch-fusion-suite
   ```
2. Install dependencies:
   ```bash
   npm install
   ```
3. Start both WordPress instances:
   ```bash
   npm run dev
   ```

`npm run dev` performs the entire setup: starts wp-env, installs Composer dependencies inside the container, activates all required plugins/themes, and provisions an application password for the Etch instance.

By default, `.wp-env.json` uses the shared `WordPress/6.8` release from the wp-env registry so clean clones work without local archives. If you maintain a custom WordPress ZIP (for example in `test-environment/wordpress.zip`), copy `.wp-env.override.json.example` to `.wp-env.override.json` inside `etch-fusion-suite/` and adjust the `core` path there.

## Required Archives

Download and place the provided plugin and theme packages before running `npm run dev`. The shared configuration no longer uses local filesystem mappings—wp-env installs these archives directly so that clean clones remain portable:

```
test-environment/
  plugins/
    bricks.2.1.2.zip
    frames-1.5.11.zip
    automatic.css-3.3.5.zip
    etch-1.0.0-alpha-5.zip
    automatic.css-4.0.0-dev-27.zip
  themes/
    bricks-child.zip
    etch-theme-0.0.2.zip
```

wp-env extracts these archives automatically for the relevant environment while mounting the checked-out plugin directory (`"."`).

## Credentials & Access

- **Bricks Source:** http://localhost:8888/wp-admin (admin / password)
- **Etch Target:** http://localhost:8889/wp-admin (admin / password)

Use WP-CLI through npm scripts:

- `npm run wp <command>` – Bricks environment
- `npm run wp:etch <command>` – Etch environment

Examples:

```bash
npm run wp plugin list
npm run wp:etch post list --post_type=post --format=count
```

## Useful npm Scripts

- `npm run stop` – Stop both wp-env instances
- `npm run destroy` – Remove containers and data (fresh reset)
- `npm run create-test-content` – Seed Bricks with demo posts/pages/classes
- `npm run test:connection` – Validate API connectivity Bricks → Etch
- `npm run test:migration` – Execute end-to-end migration smoke test
- `npm run debug` – Collect environment diagnostics into a timestamped report

## Local Overrides

Copy `.wp-env.override.json.example` to `.wp-env.override.json` (in the plugin root) to customize ports, PHP version, Xdebug settings, or additional plugins/themes. The override file is gitignored and merges with the default configuration.

## Migration Workflow

1. Start environments (`npm run dev`).
2. Log in to Bricks, open the Bricks to Etch admin page, and configure the target URL (http://localhost:8889) and API credentials (admin + generated application password).
3. Use the provided test scripts:
   - `npm run create-test-content` to seed data on Bricks.
   - `npm run test:connection` to confirm REST API access.
   - `npm run test:migration` for a scripted migration.
4. Inspect results on the Etch instance.

## Troubleshooting

- **Ports already in use:** Copy the override example and change `port`/`testsPort`.
- **Composer errors:** Re-run `npm run composer:install`.
- **Plugins missing:** Ensure ZIP archives exist in `plugins/` and `themes/` folders, then run `npm run activate`.
- **Reset environment:** `npm run destroy` followed by `npm run dev`.

---

## Current Development Workflow

**All new development must use the wp-env workflow.**

### Quick Start

```bash
cd ../etch-fusion-suite
npm install
npm run dev
```

### Documentation

- **Setup & Commands:** [`../etch-fusion-suite/README.md`](../etch-fusion-suite/README.md)
- **Testing Guide:** [`../etch-fusion-suite/TESTING.md`](../etch-fusion-suite/TESTING.md)
- **Plugin Setup:** [`PLUGIN-SETUP.md`](PLUGIN-SETUP.md)

### Legacy Docker Resources

The Docker Compose setup (`docker-compose.yml`) and Makefile in this directory are **deprecated and no longer maintained**. They are retained for reference only. Do not use them for new development.
