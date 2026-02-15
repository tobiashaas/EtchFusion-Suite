# wp-env Memory Configuration for Etch/tests-cli

## Automated Configuration (Recommended)

Memory limits are now configured directly in `etch-fusion-suite/.wp-env.json`.

Configured values:
- `WP_MEMORY_LIMIT: "512M"`
- `WP_MAX_MEMORY_LIMIT: "512M"`

This applies to both wp-env environments:
- Bricks (`cli`)
- Etch (`tests-cli`)

For standard setups, manual container edits are no longer required.

Verify with:

```bash
npm run health
```

## Verification

Check the effective memory limit directly:

```bash
npm run wp:etch -- eval "echo WP_MEMORY_LIMIT;"
```

Expected output:

```text
512M
```

If the output differs:
1. Confirm memory values in `etch-fusion-suite/.wp-env.json`
2. Restart the environment with `npm run reset`
3. Re-run the memory check command

## Advanced/Legacy Approaches

Use these only when automated configuration is blocked by custom local constraints.

### 1. `.htaccess` (Web Requests Only)

Map an `.htaccess` file and add:

```apache
php_value memory_limit 256M
```

This affects web server requests, not all `wp-env run tests-cli wp ...` commands.

### 2. Rebuild Containers with Custom PHP ini Changes

In `node_modules/@wordpress/env/lib/init-config.js`, after:

```text
RUN echo 'post_max_size = 1G' >> /usr/local/etc/php/php.ini
```

Add:

```text
RUN echo 'memory_limit = 256M' >> /usr/local/etc/php/php.ini
```

Then rebuild:

```bash
npm run destroy
npm run dev
```

### 3. Manual Plugin Activation on Etch

If plugin listing fails but memory is otherwise stable:

```bash
npx wp-env run tests-cli wp plugin activate etch-fusion-suite
```

If this still fails with memory exhaustion, use automated configuration checks first, then apply advanced option 2.

## Troubleshooting

### Memory still reports `128M`

1. Destroy and recreate the environment:

```bash
npm run destroy
npm run dev
```

2. Verify again:

```bash
npm run wp:etch -- eval "echo WP_MEMORY_LIMIT;"
```

3. Check for override files that may replace settings:
- `.wp-env.override.json`
- Any local wp-env customization in your environment

4. Run health validation:

```bash
npm run health
```
