# Admin Dashboard Redesign Deployment Checklist

This checklist is for the Bricks wizard + Etch receiving-status rollout.

## 1. Pre-Deployment Verification

- [ ] Run static checks: `npm run lint`, `npm run typecheck`, `composer phpcs`
- [ ] Run focused integration tests: `npm run test:playwright:admin-dashboard`
- [ ] Run migration smoke test: `npm run test:migration`
- [ ] Confirm AJAX nonce and capability checks still pass for:
  - `efs_wizard_*`
  - `efs_start_migration`
  - `efs_get_migration_progress`
  - `efs_get_receiving_status`
- [ ] Confirm no PHPCS violations in modified files

## 2. Manual QA Matrix

- [ ] Bricks Flow 1: connect with valid migration URL and token
- [ ] Bricks Flow 2: discovery loads and post-type mappings are editable
- [ ] Bricks Flow 3: preview warnings render for risky mapping choices
- [ ] Bricks Flow 4: migration progress updates and completion state appears
- [ ] Bricks Flow 5: browser refresh restores wizard state and resumes polling
- [ ] Etch Flow 1: migration URL generation + copy button
- [ ] Etch Flow 2: receiving takeover shows live source/phase/items
- [ ] Etch Flow 3: minimize/expand and dismiss controls work
- [ ] Etch Flow 4: stale state is visible after inactivity timeout

## 3. Performance + Security Spot Checks

- [ ] Verify progress and receiving polling cadence remains 3 seconds
- [ ] Confirm no token values are logged in browser console or server logs
- [ ] Confirm HTTPS warning displays for non-local HTTP Etch URLs
- [ ] Confirm rate limits return 429 with `Retry-After` when exceeded

## 4. Deployment Steps

- [ ] Tag release and update plugin version/changelog
- [ ] Deploy to staging first and re-run checklist sections 1-3
- [ ] Deploy to production during low-traffic window
- [ ] Monitor logs for 30 minutes:
  - AJAX 4xx/5xx spikes
  - migration failures
  - receiving-state anomalies

## 5. Rollback Plan

- [ ] Revert plugin to last stable release package
- [ ] Clear relevant transient/option state:
  - `efs_wizard_state_*`
  - `efs_migration_progress`
  - `efs_receiving_migration`
- [ ] Re-run `npm run health` and a migration smoke test on staging
- [ ] Publish rollback summary with root cause and remediation actions

