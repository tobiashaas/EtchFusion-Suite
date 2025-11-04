# Etch Fusion Suite

![CI](https://github.com/tobiashaas/EtchFusion-Suite/workflows/CI/badge.svg)
![CodeQL](https://github.com/tobiashaas/EtchFusion-Suite/workflows/CodeQL/badge.svg)
![PHP Version](https://img.shields.io/badge/PHP-7.4%20%7C%208.1%20%7C%208.2%20%7C%208.3%20%7C%208.4-blue)

**Version:** 0.10.2  
**Status:** ✅ Production Ready
**Release Automation:** ✅ GitHub Actions & build script

End-to-end migration and orchestration toolkit for transforming Bricks Builder sites into fully native Etch experiences. Automates content conversion, Gutenberg block generation, style remapping, asset handling, and API provisioning—backed by security logging, rate limiting, and deep WordPress integration.

---

## 🎯 Features

- ✅ **CSS Migration:** Converts 1135+ Bricks Global Classes to Etch Styles
- ✅ **Content Migration:** Migrates posts, pages, and Gutenberg content
- ✅ **Media Migration:** Transfers images and attachments
- ✅ **CSS Classes:** Frontend rendering with correct class names
- ✅ **Custom CSS:** Supports custom CSS from Global Classes
- ✅ **Batch Processing:** Efficient migration of large sites

---

## 📋 Requirements

- WordPress 5.0+
- PHP 7.4+
- Bricks Builder (source site)
- Etch PageBuilder (target site)
- Docker (for local testing)

---

## 🚀 Quick Start

### Installation

#### On Bricks Site (Source)

```bash
# Upload plugin to wp-content/plugins/
# Activate plugin in WordPress admin
```

#### On Etch Site (Target)

```bash
# Upload plugin to wp-content/plugins/
# Activate plugin in WordPress admin
# Generate Migration Key in Etch admin
```

### Configuration

1. Go to **Bricks Dashboard** → **Etch Fusion Suite**
2. **Paste Migration Key** from Etch site (single JWT token contains URL + credentials)
3. Click **Test Connection** to verify
4. Click **Start Migration**

**Simplified Authentication**: The new JWT-based system eliminates the need for separate URL and application password fields. A single migration key contains everything needed for secure authentication.

### Migration Process

The migration runs in 3 steps with real-time progress display:

1. **CSS Migration** - Converts Bricks Global Classes to Etch Styles
2. **Media Migration** - Transfers images and attachments
3. **Content Migration** - Migrates posts and pages

Progress is shown in real-time with detailed logs without needing to expand/collapse sections.

---

## 🐳 Local Development

### wp-env Workflow (Recommended)

The plugin uses `@wordpress/env` (wp-env) for local development with dual WordPress instances. This is the only supported development method.

#### Enhanced Quick Start

```bash
cd etch-fusion-suite
npm install
npm run dev
```

**What `npm run dev` does**:
- Performs pre-flight checks (Docker running, ports available)
- Starts both WordPress instances automatically
- Waits for WordPress to be ready
- Installs Composer dependencies
- Activates required plugins and themes
- Runs health checks
- Displays access URLs and credentials
- Provides troubleshooting suggestions if setup fails

**Access URLs**:

- Bricks Site: <http://localhost:8888/wp-admin> (admin / password)
- Etch Site: <http://localhost:8889/wp-admin> (admin / password)

**Expected Output**:

```text
✅ Docker is running
✅ Ports 8888 and 8889 are available
✅ Starting WordPress instances...
✅ Installing Composer dependencies...
✅ Activating plugins...
✅ Health checks passed

🚀 Development environment ready!
📋 Bricks (Source): <http://localhost:8888/wp-admin>
📋 Etch (Target): <http://localhost:8889/wp-admin>
👤 Username: admin
🔑 Password: password
```

#### Development Commands

**Essential Commands**:
```bash
npm run dev              # Start both instances with setup
npm run stop             # Stop both instances
npm run reset            # Clean data and restart
npm run health           # Check instance health
npm run logs:bricks      # View Bricks site logs
npm run logs:etch        # View Etch site logs
npm run test:migration   # Run migration test
```

**Testing & Debugging**:
```bash
npm run reset:soft       # Clean Etch site data
npm run test:connection  # Verify API connectivity
npm run test:migration   # Run end-to-end migration test
npm run test:playwright  # Run browser tests
npm run debug:full       # Generate diagnostic report
```

**Log Management**:
```bash
npm run logs:bricks:errors  # Filter Bricks logs for errors
npm run logs:etch:errors    # Filter Etch logs for errors
npm run logs:save           # Capture logs to timestamped files
```

For comprehensive documentation of all available commands, see **[etch-fusion-suite/README.md](etch-fusion-suite/README.md)**.

---

## Documentation

### Main Documentation
- **[CHANGELOG.md](CHANGELOG.md)** - Version history and changes
- **[DOCUMENTATION.md](DOCUMENTATION.md)** - Technical documentation
- **[docs/MIGRATOR-API.md](etch-fusion-suite/docs/MIGRATOR-API.md)** - Developer guide for the migrator system
- **[docs/FRAMER-EXTRACTION.md](etch-fusion-suite/docs/FRAMER-EXTRACTION.md)** - Framer template extraction pipeline

---

## Release Process

**Updated:** 2025-10-30 08:37

1. **Update Version** – Edit `etch-fusion-suite/etch-fusion-suite.php` and synchronise the plugin header `Version:` line with the `ETCH_FUSION_SUITE_VERSION` constant.
2. **Document Changes** – Update `CHANGELOG.md` with release notes and ensure `DOCUMENTATION.md` / `TODOS.md` entries include timestamps.
3. **Commit & Tag** – Commit the changes (`git commit -am "Bump version to X.Y.Z"`) and create a tag (`git tag vX.Y.Z`). Push commit and tag (`git push && git push --tags`).
4. **Automated Build** – GitHub Actions workflow (`.github/workflows/release.yml`) runs on any `v*` tag. It verifies the tag version against the plugin file, executes `scripts/build-release.sh`, and packages artefacts into `dist/`.
5. **Release Assets** – The workflow publishes the ZIP and its SHA-256 checksum to the GitHub Release alongside a generated changelog based on recent commits.
6. **Pre-release Testing** – For beta/RC builds tag with suffixes (e.g., `v0.10.3-beta.1`); the workflow marks the GitHub Release as a pre-release automatically.
7. **Manual Validation** – Download the uploaded ZIP, install on a test site, and confirm migrations succeed before notifying users.

### Manual Build (Optional)

To build locally without tagging:

```bash
chmod +x scripts/build-release.sh
./scripts/build-release.sh 0.10.3
```

The ZIP and checksum will be in the `dist/` directory.

### Troubleshooting

- Workflow failures due to version mismatch: ensure header/constant values equal the tag version.
- Re-run after fixing: delete the tag locally and remotely (`git tag -d vX.Y.Z && git push origin :refs/tags/vX.Y.Z`), then retag.
- Inspect logs via the GitHub Actions “Release Plugin” workflow run for detailed errors.

---

## Technical Details

### Plugin Structure

```text
etch-fusion-suite/
├── includes/
│   ├── admin_interface.php      # Admin UI and AJAX handlers
│   ├── css_converter.php         # CSS conversion logic
│   ├── gutenberg_generator.php   # Content conversion
│   ├── media_migrator.php        # Media transfer
│   ├── api_client.php            # Etch API client
│   └── ...
├── assets/
│   ├── css/                      # Admin styles
│   └── fonts/                    # Custom fonts
└── etch-fusion-suite.php     # Main plugin file
```

### Key Features

#### CSS Classes in Frontend
Etch renders CSS classes from `etchData.attributes.class`:

```json
{
  "etchData": {
    "styles": ["abc123"],
    "attributes": {
      "class": "my-css-class"
    }
  }
}
```

#### Custom CSS Support
Custom CSS from Bricks Global Classes is merged with normal styles:

```css
.my-class {
  /* Normal CSS */
  padding: 1rem;
  
  /* Custom CSS */
  --my-var: value;
  border-radius: var(--radius);
}
```

#### Element Support
- ✅ Headings (h1-h6)
- ✅ Paragraphs (p)
- ✅ Images (figure + img)
- ✅ Sections (section)
- ✅ Containers (div)
- ✅ Flex-Divs (div)

### Repository Pattern

The plugin uses Repository Pattern to abstract data access. Three repositories handle different data domains:

- **Settings_Repository** - Plugin settings, API keys, migration settings
- **Migration_Repository** - Progress, steps, stats, tokens, imported data
- **Style_Repository** - CSS styles, style maps, Etch-specific options

**Benefits:**
1. **Separation of concerns** - Business logic separated from data access
2. **Built-in caching** - Transient caching for performance (2-10 minute expiration)
3. **Easier testing** - Mock repositories for unit tests
4. **Future flexibility** - Easy to change data storage (e.g., custom tables)

**Example Usage:**
```php
// Inject repository into service
class B2E_Migration_Service {
    private $migration_repository;
    
    public function __construct(
        // ... other dependencies
        Migration_Repository_Interface $migration_repository
    ) {
        $this->migration_repository = $migration_repository;
    }
    
    public function save_progress($progress) {
        // Use repository instead of direct get_option/update_option
        $this->migration_repository->save_progress($progress);
    }
}
```

All repositories are registered in the DI container and automatically injected into services, controllers, and other components.

---

## 🐛 Troubleshooting

### Migration Fails

**Check logs with new npm scripts**:
```bash
# Bricks site (development environment)
npm run logs:bricks:errors

# Etch site (tests environment)
npm run logs:etch:errors

# Save logs for sharing
npm run logs:save

# Generate comprehensive diagnostic report
npm run debug:full
```

**Drop into container shells for inspection**:
```bash
# Open interactive shell in Bricks container
npm run shell:bricks

# Open interactive shell in Etch container
npm run shell:etch

# Once inside the shell
tail -n 100 /var/www/html/wp-content/debug.log
ls -la wp-content/plugins/etch-fusion-suite/vendor/
```

**Run WP-CLI commands with npm aliases**:
```bash
# List plugins on the Bricks site
npm run wp:bricks -- plugin list

# Clear cache on the Etch site
npm run wp:etch -- cache flush

# Check migration settings
npm run wp:bricks -- option get b2e_settings
npm run wp:etch -- option get etch_styles
```

### Environment Won't Start

**Symptoms**: Docker errors, port conflicts, resource issues

**Solutions**:
1. Verify Docker is running: `docker ps`
2. Check port availability: `npm run ports:check`
3. Ensure Docker has enough resources (4GB+ memory recommended)
4. Try clean start: `npm run destroy && npm run dev`
5. Check Docker logs: `docker logs <container-id>`

### Port Conflicts

**Symptom**: "Error: Port 8888 already in use"

**Solutions**:
1. Identify conflicting process: `npm run ports:check`
2. Stop conflicting service or kill process
3. Use custom ports: Copy `.wp-env.override.json.example` to `.wp-env.override.json`
4. Restart: `npm run dev`

### Health Check Failures

**Symptoms**: Sites not responding, health check errors

**Solutions**:
1. Generate full diagnostic: `npm run debug:full`
2. Check specific instance: `npm run health:bricks` or `npm run health:etch`
3. Review logs: `npm run logs:bricks:errors` and `npm run logs:etch:errors`
4. Verify WordPress is responding: Visit <http://localhost:8888> and <http://localhost:8889>
5. Restart if needed: `npm run reset:soft`

### Slow Performance

**Symptoms**: Environment is slow or unresponsive

**Solutions**:
1. Check Docker resource allocation in Docker Desktop settings
2. Increase memory limit: Add `WP_MEMORY_LIMIT: "512M"` to `.wp-env.override.json`
3. Disable Xdebug if not needed
4. Clean up old containers: `docker system prune`
5. Restart Docker Desktop

### Database Issues

**Symptoms**: Database connection errors, corrupted data

**Solutions**:
1. Backup current state: `npm run db:backup`
2. Check database connection: `npm run wp:bricks -- db check`
3. Repair database: `npm run wp:bricks -- db repair`
4. Reset if needed: `npm run reset:hard`
5. Restore from backup: `npm run db:restore`

### Getting Help

When reporting issues, include:
1. Diagnostic report: `npm run debug:full > debug-report.txt`
2. Saved logs: `npm run logs:save`
3. Environment info: `npm run env:info > env-info.txt`
4. Health check results: `npm run health > health-check.txt`
5. Steps to reproduce the issue

### CSS Classes Missing

1. Verify CSS migration completed successfully
2. Check `etch_styles` option exists
3. Check `b2e_style_map` option exists
4. Re-run migration

### API Connection Issues

1. Check the migration key (JWT) is valid and not expired
2. Ensure the Etch site is reachable
3. Run `npm run test:connection`
4. Verify the REST status endpoint
5. Review security logs

---

## 📊 Migration Statistics

| Category | Count | Status |
|----------|-------|--------|
| Global Classes | 1135+ | ✅ Migrated |
| Etch Styles | 1141+ | ✅ Generated |
| Element Types | 6+ | ✅ Supported |

---

## 🎉 Success Criteria

A successful migration shows:

### Database
```json
{
  "etchData": {
    "styles": ["abc123"],
    "attributes": {
      "class": "my-css-class"
    }
  }
}
```

### Frontend
```html
<div class="my-css-class">Content</div>
```

### CSS
```css
.my-css-class {
  /* Styles from Bricks */
}
```

---

## 🤝 Contributing

This is a one-time migration tool. For issues or improvements:

1. Check existing documentation
2. Review CHANGELOG.md
3. Test in wp-env before shipping
4. Run regression suite
5. Keep documentation up to date

```bash
cd etch-fusion-suite
composer lint
composer test
```

### Development Workflow

#### Development Environment

The plugin uses a dual-instance wp-env architecture for optimal development:

- **Bricks Site** (development environment): Source site running Bricks Builder on port 8888
- **Etch Site** (tests environment): Target site running Etch PageBuilder on port 8889
- **Shared Plugin Code**: Both instances mount the same plugin directory from your local filesystem
- **Independent Data**: Each instance has its own database, uploads, and configuration

**Environment Naming**: wp-env uses "development" for the Bricks site and "tests" for the Etch site. This naming is reflected in npm script aliases like `wp:bricks` and `wp:etch`.

**Customization**: Use `.wp-env.override.json` to customize ports, PHP version, plugins, and more without modifying the shared configuration.

#### Logging & Debugging

**Comprehensive Log Management**:
```bash
# View logs for specific sites
npm run logs:bricks          # Bricks site logs
npm run logs:etch            # Etch site logs
npm run logs:bricks:follow   # Tail Bricks logs in real-time
npm run logs:etch:follow     # Tail Etch logs in real-time

# Filter logs for errors only
npm run logs:bricks:errors   # Bricks errors only
npm run logs:etch:errors     # Etch errors only

# Save logs to files
npm run logs:save            # Capture to timestamped files
```

**Health Checks & Diagnostics**:
```bash
npm run health               # Check both instances
npm run health:bricks        # Check Bricks site only
npm run health:etch          # Check Etch site only
npm run debug:full           # Generate comprehensive report
npm run env:info             # Show environment configuration
npm run ports:check          # Verify port availability
```

**Debug Report Contents**:
- Docker container status and resource usage
- WordPress instance health and configuration
- Plugin activation status and errors
- PHP error logs and warnings
- Database connection status
- Network connectivity between sites
- Environment variables and settings

#### Code Quality

```bash
# Run WordPress Coding Standards check
composer lint

# Auto-fix coding standards violations
composer lint:fix
```

#### Testing

```bash
# Run PHPUnit tests
composer test

# Generate coverage report
composer test:coverage
```

**PHP UI tests**:
```bash
composer test:ui
```

#### Browser End-to-End Testing

Playwright tests provide comprehensive coverage of migration workflows and admin interfaces:

**Test Architecture**:
- **Dual-Site Testing**: Tests run against both WordPress instances
- **Storage State Authentication**: Avoids repeated logins
- **Cross-Site Workflows**: Tests complete migration flows
- **Real-Time Progress**: Verifies UI updates and status displays

**Running Tests**:
```bash
# Run all tests
EFS_ADMIN_USER=admin EFS_ADMIN_PASS=password npm run test:playwright

# Run in headed mode (see browser)
npx playwright test --headed

# Debug mode with Playwright Inspector
npx playwright test --debug

# Run specific browser
npx playwright test --project=chromium
npx playwright test --project=firefox
npx playwright test --project=webkit

# Run specific test file
npx playwright test tests/playwright/migration.spec.ts
```

**Environment Variables**:
- `EFS_ADMIN_USER` - WordPress admin username (default: admin)
- `EFS_ADMIN_PASS` - WordPress admin password (default: password)
- `EFS_BRICKS_URL` - Bricks site URL (default: <http://localhost:8888>)
- `EFS_ETCH_URL` - Etch site URL (default: <http://localhost:8889>)

**Global Setup/Teardown**:
- **Setup**: Runs health checks, creates auth directory, validates sites
- **Teardown**: Saves logs on failure, cleans temporary files, generates reports

**CI/CD**:
All pull requests automatically run:
1. **Lint** – WordPress Coding Standards via `vendor/bin/phpcs`
2. **Test** – PHPUnit suite across PHP 7.4, 8.1, 8.2, 8.3, 8.4 with WordPress test library
3. **Node** – Validates npm scripts with Node 18
4. **CodeQL** – Security scanning (JavaScript sources)
5. **Dependency Review** – Blocks insecure dependency changes
6. **Playwright** – Browser tests across Chromium, Firefox, and WebKit

See [`.github/workflows/README.md`](.github/workflows/README.md) for detailed CI/CD documentation.

---

## 📝 License

GPL v2 or later

---

## 👤 Author

**Tobias Haas**

---

## 🔗 Links

- [Bricks Builder](https://bricksbuilder.io/)
- [Etch PageBuilder](https://etchtheme.com/)
- [GitHub Repository](https://github.com/tobiashaas/EtchFusion-Suite)

---

**Last Updated:** November 4, 2025  
**Version:** 0.10.2
