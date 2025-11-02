# Etch Fusion Suite

![CI](https://github.com/tobiashaas/EtchFusion-Suite/workflows/CI/badge.svg)
![CodeQL](https://github.com/tobiashaas/EtchFusion-Suite/workflows/CodeQL/badge.svg)
![PHP Version](https://img.shields.io/badge/PHP-7.4%20%7C%208.1%20%7C%208.2%20%7C%208.3%20%7C%208.4-blue)

**Version:** 0.8.0-beta  
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

### 1. Installation

#### On Bricks Site (Source):
```bash
# Upload plugin to wp-content/plugins/
# Activate plugin in WordPress admin
```

#### On Etch Site (Target):
```bash
# Upload plugin to wp-content/plugins/
# Activate plugin in WordPress admin
# Generate Application Password in Etch admin
```

### 2. Configuration

1. Go to **Bricks Dashboard** → **Etch Fusion Suite**
2. Enter **Etch Site URL** (e.g., `https://your-etch-site.com`)
3. Enter **Application Password** from Etch site
4. Click **Test Connection** to verify
5. Click **Start Migration**

### 3. Migration Process

The migration runs in 3 steps:

1. **CSS Migration** - Converts Bricks Global Classes to Etch Styles
2. **Media Migration** - Transfers images and attachments
3. **Content Migration** - Migrates posts and pages

Progress is shown in real-time with detailed logs.

---

## 🐳 Local Development

**Note:** The Docker Compose setup in `test-environment/` is deprecated. Use the npm-based wp-env workflow instead.

### Recommended: wp-env Workflow

See **[etch-fusion-suite/README.md](etch-fusion-suite/README.md)** for complete setup instructions.

```bash
cd etch-fusion-suite
npm install
npm run dev
```

**Access:**
- Bricks Site: http://localhost:8888
- Etch Site: http://localhost:8889

### Testing

```bash
# Clean up Etch site (manual cleanup script)
./cleanup-etch.sh

# Run migration
# Go to Bricks admin and click "Start Migration"
```

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

```
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

**Check logs:**
```bash
# Bricks site (development environment)
npm run logs:bricks

# Etch site (tests environment)
npm run logs:etch
```

Need to inspect files directly? Drop into a shell and run commands there:

```bash
# Open an interactive shell
npm run shell:bricks
# or for the Etch site
npm run shell:etch

# Once inside the shell
tail -n 100 /var/www/html/wp-content/debug.log
```

**Run WP-CLI commands:**

```bash
# Example: list plugins on the Bricks site
npm run wp:bricks -- plugin list

# Example: clear cache on the Etch site
npm run wp:etch -- cache flush
```

### CSS Classes Missing

1. Verify CSS migration completed successfully
2. Check `etch_styles` option exists
3. Check `b2e_style_map` option exists
4. Re-run migration

### API Connection Issues

1. Verify Application Password is correct
2. Check Etch site is accessible
3. Test connection before migration
4. Check firewall/security settings

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

**Code Quality:**
```bash
# Run WordPress Coding Standards check
composer lint

# Auto-fix coding standards violations
composer lint:fix
```

**Testing:**
```bash
# Run PHPUnit tests
composer test

# Generate coverage report
composer test:coverage
```

**PHP UI tests:**
```bash
composer test:ui
```

Locates WordPress-backed UI assertions in `tests/ui`. Use the dedicated suite for targeted runs:

- `composer test:wordpress` – WordPress-specific unit helpers under `tests/unit/WordPress`
- `composer test:integration` – Cross-service integration coverage in `tests/integration`
- `composer test:performance` – Synthetic migration benchmarks in `tests/performance`

**Browser end-to-end coverage:**

Playwright specs live under `etch-fusion-suite/tests/playwright/` and run via storage-state authentication:

```bash
EFS_ADMIN_USER=admin EFS_ADMIN_PASS=password npm run test:playwright
```

**CI/CD:**
All pull requests automatically run:
1. **Lint** – WordPress Coding Standards via `vendor/bin/phpcs`
2. **Test** – PHPUnit suite across PHP 7.4, 8.1, 8.2, 8.3, 8.4 with WordPress test library installed in `/tmp`
3. **Node** – Validates npm scripts with Node 18 using `npm ci`
4. **CodeQL** – Security scanning (JavaScript sources currently enabled)
5. **Dependency Review** – Blocks insecure dependency changes

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
- [GitHub Repository](https://github.com/tobiashaas/Bricks-to-Etch-Migration)

---

**Last Updated:** October 24, 2025  
**Version:** 0.8.0
