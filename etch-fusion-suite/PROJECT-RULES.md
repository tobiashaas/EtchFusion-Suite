# Project Rules - Etch Fusion Suite

**Last Updated:** 2025-10-29 15:30

---

## 📋 General Rules

### 1. Documentation
- ✅ All documentation goes into `DOCUMENTATION.md`
- ✅ Always add timestamp when updating
- ✅ Keep documentation up-to-date with code changes

### 2. Changelog
- ✅ All changes MUST be documented in `CHANGELOG.md`
- ✅ Always add timestamp
- ✅ Format: `[Version] - YYYY-MM-DD (HH:MM)`
- ✅ Include: Features, Bug Fixes, Technical Changes

### 3. Todos
- ✅ All todos go into `TODOS.md`
- ✅ Always add timestamp
- ✅ Mark completed todos with ✅
- ✅ Remove completed todos after verification

### 4. File Creation
- ❌ **NEVER create new files without asking first**
- ✅ Always ask user before creating new documentation
- ✅ Update existing files instead of creating new ones
- ✅ Exception: Test scripts (see below)

### 5. Test Scripts
- ✅ All test scripts go into `/tests` folder
- ✅ Naming: `test-[feature].php` or `test-[feature].sh`
- ✅ Include description comment at top of file
- ✅ Clean up after testing

### 6. Development Scripts
- ✅ All helper scripts go into `/scripts` folder
- ✅ Use descriptive names with kebab-case
- ✅ Include usage examples and help text
- ✅ Handle errors gracefully with informative messages

---

## 📁 File Structure

```
etch-fusion-suite/
├── README.md                           # Main documentation
├── CHANGELOG.md                        # Version history (with timestamps)
├── DOCUMENTATION.md                    # Technical documentation (with timestamps)
├── TODOS.md                           # Todo list (with timestamps)
├── PROJECT-RULES.md                   # This file
├── .wp-env.json                       # wp-env configuration
├── .wp-env.override.json.example      # Override configuration example
├── package.json                       # npm scripts and dependencies
├── composer.json                      # PHP dependencies
├── playwright.config.ts               # Playwright test configuration
├── etch-fusion-suite.php              # Main plugin file
├── scripts/                           # Helper scripts
│   ├── dev.js                        # Development environment starter
│   ├── health-check.js               # Environment health monitoring
│   ├── filter-logs.js                # Log filtering and following
│   ├── save-logs.js                  # Log capture and saving
│   ├── check-ports.js                # Port availability checker
│   ├── env-info.js                   # Environment information display
│   ├── backup-db.js                  # Database backup utility
│   ├── restore-db.js                 # Database restore utility
│   ├── activate-plugins.js           # Plugin activation with retry
│   ├── wait-for-wordpress.js         # WordPress readiness checker
│   └── debug-info.js                 # Comprehensive debugging info
├── tests/                             # Test files
│   ├── playwright/                   # Playwright tests
│   │   ├── global-setup.ts          # Global test setup
│   │   ├── global-teardown.ts       # Global test teardown
│   │   └── *.spec.ts                # Test specifications
│   └── test-*.php                    # PHP test scripts
├── logs/                              # Log files (gitignored)
├── backups/                           # Database backups (gitignored)
├── .playwright-auth/                  # Playwright auth cache (gitignored)
└── includes/                          # Plugin source code
```

---

## 🔄 Workflow

### Making Changes

1. **Before coding:**
   - Check `TODOS.md` for current tasks
   - Update `TODOS.md` with new task (with timestamp)

2. **While coding:**
   - Make changes
   - Test changes
   - Document in code comments

3. **After coding:**
   - Update `CHANGELOG.md` (with timestamp)
   - Update `DOCUMENTATION.md` (with timestamp)
   - Mark todo as complete in `TODOS.md`
   - Test thoroughly

### Creating Helper Scripts

1. Create in `/scripts` folder
2. Name: `script-name.js` (kebab-case)
3. Add shebang: `#!/usr/bin/env node`
4. Include comprehensive help text
5. Handle errors gracefully
6. Add to `package.json` scripts
7. Document in `DOCUMENTATION.md`

### Creating Test Scripts

1. Create in `/tests` folder
2. Name: `test-[feature].php` or `test-[feature].sh`
3. Add description comment
4. Clean up after testing
5. Document in `DOCUMENTATION.md` if needed

### Updating Documentation

1. Open `DOCUMENTATION.md`
2. Find relevant section
3. Update content
4. Add timestamp: `**Updated:** YYYY-MM-DD HH:MM`

---

## ✅ Examples

### Changelog Entry
```markdown
## [0.4.1] - 2025-10-29 (15:30)

### 🚀 Features
- Added comprehensive health check system
- Implemented log filtering and saving utilities
- Enhanced Playwright setup with global setup/teardown

### 🔧 Technical Changes
- Enhanced wp-env configuration with per-environment settings
- Added 20+ new npm scripts for development tasks
- Improved error handling across all helper scripts
```

### Todo Entry
```markdown
- [ ] Implement Framer integration queue system - **Added:** 2025-10-29 15:30
- [✅] Add comprehensive health check system - **Completed:** 2025-10-29 15:30
```

### Documentation Update
```markdown
## Helper Scripts

**Updated:** 2025-10-29 15:30

### Health Check System
The health check system provides comprehensive monitoring of WordPress instances...
```

---

## 🚫 Don'ts

- ❌ Don't create new markdown files without asking
- ❌ Don't create test scripts in root folder
- ❌ Don't update code without updating CHANGELOG
- ❌ Don't add todos without timestamp
- ❌ Don't leave completed todos in TODOS.md
- ❌ Don't commit sensitive information
- ❌ Don't ignore error handling in scripts
- ❌ Don't skip documentation updates

---

## ✅ Do's

- ✅ Always ask before creating new files
- ✅ Always add timestamps to changes
- ✅ Keep documentation up-to-date
- ✅ Test changes thoroughly
- ✅ Clean up after testing
- ✅ Use existing files instead of creating new ones
- ✅ Handle errors gracefully in all scripts
- ✅ Include usage examples for new features
- ✅ Follow established naming conventions
- ✅ Add comprehensive help text to CLI tools

---

## 📝 Code Standards

### JavaScript/TypeScript
- Use ESLint and Prettier for formatting
- Add JSDoc comments for functions
- Handle async/await errors properly
- Use descriptive variable and function names
- Include error messages with actionable steps

### PHP
- Follow WordPress coding standards
- Use proper PHPDoc comments
- Sanitize and validate all inputs
- Use WordPress functions when available
- Include proper error handling

### Shell Scripts
- Use `#!/usr/bin/env bash` shebang
- Add `set -euo pipefail` for safety
- Include comprehensive error handling
- Use descriptive variable names
- Add help text and usage examples

---

## 🧪 Testing

### Running Tests
```bash
npm run test:playwright              # Run all Playwright tests
npm run test:php                     # Run PHP unit tests
npm run health                       # Check environment health
npm run debug:full                   # Get debugging information
```

### Test Requirements
- All new features must have tests
- Tests should cover error conditions
- Include integration tests where appropriate
- Test cross-platform compatibility for scripts
- Verify documentation examples work

---

## 🔧 Development Environment

### Setup
```bash
npm run dev                          # Start development environment
npm run health                       # Verify environment health
npm run ports:check                  # Check port availability
npm run env-info                     # Display environment information
```

### Maintenance
```bash
npm run logs:save                    # Save current logs
npm run db:backup                    # Backup databases
npm run reset:soft                   # Restart containers
npm run reset:hard                   # Rebuild environment
```

---

**Created:** 2025-10-29 15:30  
**Version:** 1.0  
**Next Review:** 2025-11-05 15:30
