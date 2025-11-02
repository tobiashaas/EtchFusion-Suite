# Bricks to Etch wp-env Testing Guide

**Updated:** 2025-10-31 09:58

## 1. Pre-Flight Checks

1. Verify Node.js version:

   ```bash
   node -v
   ```

   Ensure the version is **>= 18**.

2. Verify npm is available:

   ```bash
   npm -v
   ```

3. Confirm Docker Desktop is running and healthy:

   ```bash
   docker ps
   ```

4. Confirm ports 8888/8889 are free:

   ```bash
   netstat -an | findstr 8888
   netstat -an | findstr 8889
   ```

   No results = ports available.

## 2. Environment Setup Tests

1. Install dependencies:

   ```bash
   npm install
   ```

   Expect a populated `node_modules/` directory.

2. Start environments:

   ```bash
   npm run dev
   ```

   The command should complete without errors and print the Bricks/Etch URLs.

3. Browser smoke-test:

   - Visit <http://localhost:8888> and <http://localhost:8889>.
   - Log in with **admin / password** on both.

4. Verify plugin activation via WP-CLI:

   ```bash
   npm run wp plugin status bricks-etch-migration
   npm run wp:etch plugin status bricks-etch-migration
   ```

5. Confirm Composer artifacts exist:

   ```bash
   npm run wp "eval 'echo file_exists(WP_PLUGIN_DIR . "/bricks-etch-migration/vendor/autoload.php") ? "yes" : "no";'"
   ```

   Expected output: `yes`.

## 3. Plugin Functional Tests

1. Load the Bricks to Etch admin screen:
   - Navigate to **Bricks to Etch → Dashboard** in the Bricks site.
   - Confirm there are no PHP warnings/notices in the UI.

2. Validate AJAX endpoints:
   - Open browser console → Network tab.
   - Trigger an action (e.g., refresh status) and ensure 200 responses.

3. Confirm REST API endpoint:

   ```bash
   curl -u admin:$(npm run wp:etch user application-password list admin --silent -- --fields=password --format=csv | tail -n +2) \
     http://localhost:8889/wp-json/b2e/v1/status
   ```

   Response should include `{"status":"ok", "version":"..."}`.

## 4. Migration Smoke Test

1. Seed content:

   ```bash
   npm run create-test-content
   ```

   Expect confirmation that posts, pages, classes, and media were created.

2. Generate Etch credentials (auto-run by `npm run dev`, but can be repeated):

   ```bash
   npm run wp:etch user application-password create admin smoke-test --porcelain
   ```

3. Configure plugin settings on Bricks:

   ```bash
   npm run wp option update b2e_migration_settings '{"target_url":"http://localhost:8889","api_username":"admin","api_key":"<app-password>"}'
   ```

4. Trigger migration:

   ```bash
   npm run test:migration
   ```

   The command completes when the migration status is `completed`.

5. Validate record counts:

   ```bash
   npm run wp post list --post_type=post --format=count
   npm run wp:etch post list --post_type=post --format=count
   ```

   Counts should match after migration.


## 5. Performance Spot Checks

1. Measure migration duration:

   ```bash
   time npm run test:migration
   ```

   Record the total runtime.

2. Capture memory usage on Etch:

   ```bash
   npm run wp:etch "eval 'echo memory_get_peak_usage(true);'"
   ```


## 6. Error Handling Scenarios

1. Invalid API key:

   ```bash
   npm run wp option update b2e_migration_settings '{"target_url":"http://localhost:8889","api_username":"admin","api_key":"invalid"}'
   npm run test:migration
   ```
   Expect the migration to fail gracefully with a descriptive error.

2. Network interruption:
   - Run `npm run stop` during a migration and confirm retry/timeout messaging is clear.

3. Database error simulation:

   ```bash
   npm run wp:etch "eval 'global $wpdb; $wpdb->query(\"SET SESSION sql_mode='STRICT_ALL_TABLES'\");'"
   npm run test:migration
   ```
   Ensure errors are logged to `wp-content/debug.log`.


## 7. Admin Dashboard UI Testing

These checks cover the restructured dashboard rendered at `http://localhost:8888/wp-admin/admin.php?page=etch-fusion-suite` (Bricks) and `http://localhost:8889/wp-admin/admin.php?page=etch-fusion-suite` (Etch). Start both stacks via `npm run dev`; admin auth states are pre-seeded in `.playwright-auth/bricks.json` and `.playwright-auth/etch.json` per @etch-fusion-suite/playwright.config.ts#41-144.

### Accordion Functionality

**Manual:**
1. Log into the Bricks site (`admin/password`).
2. Verify that the "Connection Settings" accordion is expanded by default and that toggling other headers collapses previous sections.
3. Repeat on the Etch site and confirm four accordion sections appear.

**Automated:**
- Playwright coverage: @etch-fusion-suite/tests/playwright/dashboard-tabs.spec.ts#70-162.
- Run focused suite:
  ```bash
  npm run test:playwright -- dashboard-tabs.spec.ts --project=chromium
  ```

### Feature Flags UI

**Manual:** Disable `template_extractor` via Feature Flags, reload Etch dashboard, and confirm the Templates tab shows the disabled state with guidance toast.

**Automated:**
- Disabled-state behaviour: @etch-fusion-suite/tests/playwright/dashboard-tabs.spec.ts#256-331.
- Re-enable flow: same spec lines #289-331.

### Migration Key Component

**Manual:**
1. On Bricks, save settings with target URL and API key, open Migration Key accordion, and confirm hidden inputs include both values.
2. On Etch, ensure the generated key component includes the site URL but hides the API key field.

**Automated:**
- Component assertions: @etch-fusion-suite/tests/playwright/dashboard-tabs.spec.ts#333-365.
- PHPUnit coverage: @etch-fusion-suite/tests/ui/AdminUITest.php#121-155. Execute:
  ```bash
  vendor/bin/phpunit -c phpunit.xml.dist --testsuite=ui --filter=migration_key_component
  ```

### Button Grouping and Forms

**Manual:** Confirm grouped button alignment within Connection Settings and Migration sections, and ensure AJAX flows succeed without navigation.

**Automated:**
- Playwright actions: @etch-fusion-suite/tests/playwright/dashboard-tabs.spec.ts#391-441.
- PHPUnit assertions: @etch-fusion-suite/tests/ui/AdminUITest.php#206-233.

## 8. Accessibility Testing

The accessibility suite exercises aria roles, keyboard navigation, and automated axe-core audits.

### Prerequisites
- Install Axe if not already present:
  ```bash
  npm install --save-dev @axe-core/playwright
  ```
- Start both local sites (`npm run dev`).

### Manual Checklist
1. Confirm accordion headers expose `aria-expanded`/`aria-controls` attributes.
2. Tab through dashboard controls and ensure focus states remain visible.
3. With Template Extractor disabled, verify `aria-disabled="true"` on the tab.

### Automated Runs
- Full accessibility suite: @etch-fusion-suite/tests/playwright/accessibility.spec.ts#53-384.
- Execute across a desktop browser:
  ```bash
  npm run test:playwright -- accessibility.spec.ts --project=chromium
  ```
- For additional browsers, append `--project=firefox` or `--project=webkit`.

## 9. Responsive Design Testing

Responsive checks use device emulation to validate mobile, tablet, desktop, orientation, zoom, and touch scenarios (@etch-fusion-suite/tests/playwright/responsive.spec.ts#1-326).

**Command shortcuts:**
```bash
npm run test:playwright -- responsive.spec.ts --project=mobile-chrome
npm run test:playwright -- responsive.spec.ts --project=mobile-safari
```

**Key scenarios:**
- Mobile (≤480px) – no horizontal scroll, accordion tap targets expand fully.
- Tablet (768px) – spacing and inline action alignment remain consistent.
- Desktop (≥1920px) – content remains constrained under 1400px.
- Orientation & zoom – tests at 200% and 50% zoom ensure UI remains usable.

## 10. Cross-Browser Testing

Playwright projects mirror @etch-fusion-suite/playwright.config.ts#95-143:

```bash
npx playwright test --project=chromium
npx playwright test --project=firefox
npx playwright test --project=webkit
```

Optional mobile projects (`mobile-chrome`, `mobile-safari`) reuse stored auth states. When targeting a single spec:

```bash
npx playwright test tests/playwright/dashboard-tabs.spec.ts --project=webkit
```

## 11. PHPUnit Integration Tests

`vendor/bin/phpunit -c phpunit.xml.dist --testsuite=ui` covers dashboard rendering assertions (@etch-fusion-suite/tests/ui/AdminUITest.php#50-334). Use the helpers within that suite to toggle Bricks vs Etch contexts (`set_bricks_environment()`, `set_etch_environment()`) and seed option values prior to rendering.

Common commands:
```bash
vendor/bin/phpunit -c phpunit.xml.dist --testsuite=ui
vendor/bin/phpunit -c phpunit.xml.dist --testsuite=ui --filter=bricks_setup_section
```

## 12. Continuous Integration

The GitHub Actions workflow (@etch-fusion-suite/.github/workflows/tests.yml#1-133) runs PHPUnit first, followed by Playwright.

Highlights:
- PHPUnit job provisions MySQL, installs the WordPress test suite, and executes `vendor/bin/phpunit -c phpunit.xml.dist`.
- Playwright job installs Node dependencies, launches the wp-env stacks (`npm run e2e:start`), and runs `npx playwright test --project=chromium --project=firefox --project=webkit`.
- On failures, reports publish to `etch-fusion-suite/playwright-report/` for download.

To replicate the CI run locally:
```bash
composer install
npm ci
npm run e2e:start
npx playwright test --project=chromium --project=firefox --project=webkit
npm run e2e:stop
```

## 13. Cleanup

1. Destroy environments:
   ```bash
   npm run destroy
   ```
2. Restart for verification:
   ```bash
   npm run dev
   ```
   Ensures clean re-provisioning works repeatedly.

3. Optional database exports:
   ```bash
   npm run db:export:bricks
   npm run db:export:etch
   ```

Document test results in `DOCUMENTATION.md` with timestamps after each run.
