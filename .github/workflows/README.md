# GitHub Actions Workflows

**Last Updated:** 2025-10-27 23:48

This directory documents the CI/CD automation that keeps Etch Fusion Suite healthy across PHP, Node, and browser tooling.

---

## 📋 Workflows Overview

### CI Pipeline (`ci.yml`)

Runs on pushes to `main`, `develop`, and every pull request. Jobs execute sequentially:

1. **Lint** – Installs Composer dependencies inside `etch-fusion-suite/` and runs `vendor/bin/phpcs --standard=phpcs.xml.dist`, explicitly scanning `etch-fusion-suite.php`, `includes/`, `assets/`, and other PHP sources.
2. **Test** – Matrix across PHP 8.1, 8.2, 8.3, and 8.4. Exports `WP_TESTS_DIR=/tmp/wordpress-tests-lib` and `WP_CORE_DIR=/tmp/wordpress`, provisions the WordPress PHPUnit library via `install-wp-tests.sh`, and executes `vendor/bin/phpunit -c etch-fusion-suite/phpunit.xml.dist`.
3. **Node** – Sets up Node 18 with npm caching and runs `npm ci` inside `etch-fusion-suite/` to validate front-end tooling.

**Local Reproduction**

```bash
cd etch-fusion-suite

# Lint PHP
composer lint

# Fix linting violations
composer lint:fix

# Run full PHPUnit (unit, wordpress, integration, ui, performance)
composer test

# Regenerate coverage artifacts
composer test:coverage

# Playwright browser specs
EFS_ADMIN_USER=admin EFS_ADMIN_PASS=password npm run test:playwright
```

### CodeQL Security Scanning (`codeql.yml`)

Triggers on pushes to `main`, all pull requests, and every Monday at 06:00 UTC.

- Languages: PHP, JavaScript
- Queries: `security-extended`
- Config: `.github/codeql/codeql-config.yml`

**Review Flow**

1. Open the **Security → Code scanning alerts** tab.
2. Inspect alert details and triage duplicates or false positives.
3. Dismiss false positives with rationale or ship fixes.

### Dependency Review (`dependency-review.yml`)

Runs on every pull request. Blocks merges when new dependencies introduce:

- Known vulnerabilities (moderate severity or higher)
- Disallowed licenses (AGPL, SSPL)

Apply the `dependencies-reviewed` label if manual validation has taken place.

### Release Automation (`release.yml`)

Triggers on Git tags matching `v*` (e.g., `v1.0.0`). Packaging steps:

1. Validates plugin headers in `etch-fusion-suite.php` against the tag.
2. Installs production Composer dependencies.
3. Builds the distributable ZIP (excludes dev/test files).
4. Extracts changelog notes from `CHANGELOG.md`.
5. Publishes the GitHub Release with the compiled artifact.

**Release Checklist**

```bash
# 1. Update plugin version and changelog
vim etch-fusion-suite/etch-fusion-suite.php CHANGELOG.md

# 2. Commit changes
git add etch-fusion-suite/etch-fusion-suite.php CHANGELOG.md
git commit -m "Release v1.0.0"

# 3. Tag & push
git tag v1.0.0
git push origin main v1.0.0
```

---

## 🔒 Security Hardening

### Pinned Actions

- `actions/checkout@08eba0b27e820071cde6df949e0beb9ba4906955`
- `shivammathur/setup-php@bf6b4fbd49ca58e4608c9c89fba0b8d90bd2a39f`
- `actions/cache@0057852bfaa89a56745cba8c7296529d2fc39830`
- `github/codeql-action/init@42213152a85ae7569bdb6bec7bcd74cd691bfe41`
- `actions/dependency-review-action@40c09b7dc99638e5ddb0bfd91c1673effc064d8a`

### Least-Privilege Permissions

- **CI:** `contents: read`, `pull-requests: read`
- **CodeQL:** `actions: read`, `contents: read`, `security-events: write`
- **Dependency Review:** `contents: read`, `pull-requests: write`
- **Release:** `contents: write`, `packages: write`

---

## 🔄 Dependabot

`/.github/dependabot.yml` schedules weekly updates (Monday) for:

- Composer dependencies (`etch-fusion-suite/composer.json`)
- npm packages (`etch-fusion-suite/package.json`)
- GitHub Actions workflows (`.github/workflows/*.yml`)

Minor and patch updates are grouped to reduce PR volume. Review the generated PR, run targeted tests if necessary, and merge once CI succeeds.

---

## 📊 CI/CD Badges

```markdown
![CI](https://github.com/[owner]/EtchFusion-Suite/actions/workflows/ci.yml/badge.svg)
![CodeQL](https://github.com/[owner]/EtchFusion-Suite/actions/workflows/codeql.yml/badge.svg)
```

Replace `[owner]` with your GitHub username or organization.

---

## 🧪 Troubleshooting

### PHPUnit cannot locate the WordPress test suite

- Ensure `WP_TESTS_DIR=/tmp/wordpress-tests-lib` and `WP_CORE_DIR=/tmp/wordpress` are exported (CI does this automatically).
- Rerun `bash etch-fusion-suite/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest`.

### PHPCS failures

```bash
composer lint:fix
```

Re-run `composer lint` afterwards to verify.

### CodeQL noise or false positives

Dismiss from **Security → Code scanning alerts** with the "False positive" reason. Add context so future reviewers understand the decision.

### Release job blocked

Confirm the plugin version header in `etch-fusion-suite.php` matches the pushed tag and that `CHANGELOG.md` contains the release entry. Delete and recreate the tag if mismatch occurs:

```bash
git tag -d v1.0.0
git push origin :refs/tags/v1.0.0
# Fix metadata, then retag
git tag v1.0.0
git push origin v1.0.0
```

---

## 📚 Additional Resources

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [CodeQL for PHP](https://codeql.github.com/docs/codeql-language-guides/codeql-for-php/)
