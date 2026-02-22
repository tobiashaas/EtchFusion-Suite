#!/bin/bash

set -euo pipefail

# -----------------------------------------------------------------------------
# verify-no-debug-filewrites.sh
# -----------------------------------------------------------------------------
# Prevents regression of ad-hoc debug file writes (e.g. debug-cd448e.log,
# #region agent log, raw fopen/file_put_contents to .log). Scans plugin PHP
# runtime directories for forbidden patterns and fails if any match is found.
#
# Usage:
#   ./scripts/verify-no-debug-filewrites.sh
#
# Exit codes:
#   0 - No forbidden patterns found (clean)
#   1 - One or more violations found (file:line:match printed)
#   2 - Script error (missing dependencies, invalid state)
# -----------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# All runtime PHP paths; exclude vendor/ and optionally tests/ to avoid noise.
# error_handler.php exclusions for log-related patterns are applied separately below.
SCAN_PATHS=(
  "${PROJECT_ROOT}"
  "${PROJECT_ROOT}/includes/"
)

# Forbidden patterns (grep -E); scanned with --include="*.php" only.
FORBIDDEN_PATTERNS=(
  "debug-cd448e\\.log"
  "#region agent log"
  "cd448e"
)

# Patterns that must not match in error_handler.php (uses file_put_contents for WP debug.log).
# Scanned with --exclude=error_handler.php.
FORBIDDEN_PATTERNS_EXCLUDE_ERROR_HANDLER=(
  "fopen\\s*\\(.*\\.log"
  "file_put_contents\\s*\\(.*\\.log"
)

error() {
  echo "[✗] $*" >&2
}

info() {
  echo "[•] $*"
}

success() {
  echo "[✓] $*"
}

check_dependencies() {
  info "Checking dependencies"
  if ! command -v grep >/dev/null 2>&1; then
    error "grep command not available."
    exit 2
  fi
  success "Dependencies OK"
}

run_grep_check() {
  info "Scanning for forbidden debug file-write patterns"
  local all_matches=""
  local match
  local exclude_dirs=( --exclude-dir=vendor --exclude-dir=tests )

  for pattern in "${FORBIDDEN_PATTERNS[@]}"; do
    match=$(grep -rn --include="*.php" "${exclude_dirs[@]}" -E "${pattern}" "${SCAN_PATHS[@]}" 2>/dev/null || true)
    if [[ -n "${match}" ]]; then
      all_matches="${all_matches}${match}"$'\n'
    fi
  done

  for pattern in "${FORBIDDEN_PATTERNS_EXCLUDE_ERROR_HANDLER[@]}"; do
    match=$(grep -rn --include="*.php" --exclude=error_handler.php "${exclude_dirs[@]}" -E "${pattern}" "${SCAN_PATHS[@]}" 2>/dev/null || true)
    if [[ -n "${match}" ]]; then
      all_matches="${all_matches}${match}"$'\n'
    fi
  done

  # Trim trailing newline for consistent handling
  all_matches="${all_matches%$'\n'}"
  echo "${all_matches}"
}

display_summary() {
  local match_output="$1"
  local violation_count=0
  if [[ -n "${match_output}" ]]; then
    violation_count=$(echo "${match_output}" | wc -l | tr -d ' ')
  fi

  echo ""
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "  NO DEBUG FILE-WRITES VERIFICATION"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  printf "Paths scanned: %s\n" "$(IFS=,; echo "${SCAN_PATHS[*]}")"
  printf "Violations found: %s\n" "${violation_count}"

  if [[ "${violation_count}" -eq 0 ]]; then
    success "No forbidden debug file-write patterns detected."
  else
    error "Forbidden pattern(s) detected. Remove debug artefacts and re-run."
    echo ""
    echo "Offending matches (file:line:match):"
    echo "${match_output}"
    echo ""
    echo "Next steps:"
    echo "  1. Remove or replace the matched debug code"
    echo "  2. Use EFS_Error_Handler / EFS_Audit_Logger for logging"
    echo "  3. Re-run: ./scripts/verify-no-debug-filewrites.sh"
  fi
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
}

main() {
  check_dependencies
  local match_output
  match_output=$(run_grep_check)

  display_summary "${match_output}"

  if [[ -n "${match_output}" ]]; then
    exit 1
  fi
  exit 0
}

main "$@"
