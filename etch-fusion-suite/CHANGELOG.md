# Changelog

All notable changes to the Etch Fusion Suite project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### 🐛 Bug Fixes
- Fixed backup metadata version retrieval mis-mapping environments in `scripts/backup-db.js` - **2025-11-04 21:22**
  - Added `normalizeEnvironmentToTarget()` function for consistent environment mapping
  - Updated version helpers to accept both logical names ('bricks'/'etch') and direct targets ('cli'/'tests-cli')
  - Modified `backupAllDatabases()` to use logical names for clarity and consistency
  - Added verbose debug logging with `--verbose` flag and `EFS_DEBUG` environment variable
  - Ensured Bricks metadata comes from `cli` container and Etch metadata from `tests-cli` consistently

### 🚀 Features
- Enhanced wp-env development environment with comprehensive helper scripts
- Added health check system for monitoring WordPress instances
- Implemented log filtering and saving utilities
- Added port availability checker with process identification
- Created environment information display tool
- Added database backup and restore functionality with manifest tracking
- Enhanced Playwright setup with global health checks and log capture
- Improved error handling and retry logic across all scripts

### 🔧 Technical Changes
- Enhanced `.wp-env.json` with per-environment configuration and lifecycle scripts
- Added `.wp-env.override.json.example` for local customizations
- Updated `package.json` with 20+ new npm scripts for development tasks
- Improved `dev.js` with pre-flight checks, retry logic, and detailed summaries
- Enhanced `wait-for-wordpress.js` with timeout, JSON output, and WordPress verification
- Upgraded `debug-info.js` with comprehensive system analysis and multiple output formats
- Enhanced `activate-plugins.js` with retry logic, force mode, and verification
- Added global setup/teardown to Playwright configuration
- Integrated health checks into Playwright test execution

### 🛠️ Development Experience
- Added structured logging with color coding and severity levels
- Implemented cross-platform compatibility for all helper scripts
- Added progress indicators and spinners for long-running operations
- Created comprehensive error messages with actionable troubleshooting steps
- Added dry-run modes for destructive operations
- Implemented automatic cleanup of temporary files and old logs

### 📋 Documentation
- Added inline documentation for all new helper scripts
- Enhanced configuration examples with detailed comments
- Added usage examples and troubleshooting guides
- Documented all new npm scripts with descriptions

---

## [0.4.1] - 2025-10-21 (23:20)

### 🐛 Bug Fixes
- Fixed Custom CSS migration not merging with existing styles
- Updated `parse_custom_css_stylesheet()` to use existing style IDs

---

## [0.4.0] - 2025-10-21 (22:45)

### 🚀 Features
- Added comprehensive Bricks to Etch migration system
- Implemented support for custom field mapping
- Added batch processing for large datasets
- Introduced progress tracking and reporting

### 🔧 Technical Changes
- Refactored migration engine for better performance
- Added database transaction support
- Implemented error recovery mechanisms
- Enhanced logging and debugging capabilities

---

## [0.3.0] - 2025-10-20 (18:30)

### 🚀 Features
- Added initial WordPress integration
- Implemented basic data migration
- Added configuration management system

### 🔧 Technical Changes
- Set up project structure and build system
- Added Composer and npm package management
- Implemented basic testing framework

---

## [0.2.0] - 2025-10-19 (14:15)

### 🚀 Features
- Created project foundation
- Added basic plugin architecture
- Implemented core functionality

### 🔧 Technical Changes
- Initial project setup
- Added basic file structure
- Set up development environment

---

## [0.1.0] - 2025-10-18 (10:00)

### 🎉 Initial Release
- Project inception
- Basic concept implementation
- Development environment setup
