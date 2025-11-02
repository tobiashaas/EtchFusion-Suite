#!/bin/bash

set -euo pipefail

# -----------------------------------------------------------------------------
# verify-strict-comparison.sh
# -----------------------------------------------------------------------------
# Verifies that all in_array() calls use strict comparison (third parameter true)
# across security, repository, and core files. Optionally generates a report and
# runs PHPCBF when violations are detected.
#
# Usage:
#   ./scripts/verify-strict-comparison.sh [--fix] [--report]
#
# Exit codes:
#   0 - All checks pass
#   1 - Violations found
#   2 - Script error (missing dependencies, invalid options)
# -----------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHPCS_BIN="${PROJECT_ROOT}/vendor/bin/phpcs"
PHPCBF_BIN="${PROJECT_ROOT}/vendor/bin/phpcbf"
PHPCS_STANDARD="${PROJECT_ROOT}/phpcs.xml.dist"
TARGET_PATHS=(
  "${PROJECT_ROOT}/includes/security/"
  "${PROJECT_ROOT}/includes/repositories/"
  "${PROJECT_ROOT}/includes/error_handler.php"
)
REPORT_DIR="${PROJECT_ROOT}/docs"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_FILE="${REPORT_DIR}/phpcs-strict-comparison-report-${TIMESTAMP}.txt"

FLAG_FIX=false
FLAG_REPORT=false
GREP_USED_FALLBACK=false

print_usage() {
  cat <<USAGE
Usage: ${0##*/} [options]

Options:
  --fix       Run PHPCBF for detected violations before re-checking
  --report    Always generate detailed report (even when compliant)
  --help      Show this help text
USAGE
}

error() {
  echo "[✗] $*" >&2
}

info() {
  echo "[•] $*"
}

success() {
  echo "[✓] $*"
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --fix)
        FLAG_FIX=true
        ;;
      --report)
        FLAG_REPORT=true
        ;;
      --help|-h)
        print_usage
        exit 0
        ;;
      *)
        error "Unknown option: $1"
        print_usage
        exit 2
        ;;
    esac
    shift
  done
}

check_dependencies() {
  info "Checking dependencies"

  if [[ ! -x "${PHPCS_BIN}" ]]; then
    error "phpcs binary not found at ${PHPCS_BIN}. Run composer install."
    exit 2
  fi

  if [[ ! -f "${PHPCS_STANDARD}" ]]; then
    error "phpcs.xml.dist not found at ${PHPCS_STANDARD}."
    exit 2
  fi

  if ! command -v grep >/dev/null 2>&1; then
    error "grep command not available."
    exit 2
  fi

  success "Dependencies OK"
}

run_phpcs() {
  info "Running PHPCS strict comparison sniff"
  local output
  if ! output="$(${PHPCS_BIN} --standard="${PHPCS_STANDARD}" --sniffs=WordPress.PHP.StrictInArray "${TARGET_PATHS[@]}")"; then
    echo "${output}"
    return 1
  fi
  echo "${output}"
  return 0
}

run_phpcbf() {
  info "Running PHPCBF to attempt auto-fix"
  "${PHPCBF_BIN}" --standard="${PHPCS_STANDARD}" --sniffs=WordPress.PHP.StrictInArray "${TARGET_PATHS[@]}" || true
}

run_grep_check() {
  info "Scanning for non-strict in_array() calls"
  local violations=""
  local using_pcre=false

  if command -v pcregrep >/dev/null 2>&1; then
    # Matches in_array() calls that do not pass the strict (true) flag before closing parenthesis.
    local pattern='in_array\s*\((?:(?!,\s*true\s*\)).)*\)'
    violations=$(pcregrep -nM "${pattern}" "${TARGET_PATHS[@]}" 2>/dev/null || true)
    using_pcre=true
    GREP_USED_FALLBACK=false
  else
    info "pcregrep not available; falling back to basic grep (multiline calls may be missed). Results are informational only; PHPCS determines pass/fail."
    # Use simpler grep pattern that won't cause "parentheses not balanced" error
    violations=$(grep -rn "in_array(" "${TARGET_PATHS[@]}" 2>/dev/null | grep -v ", true)" || true)
    GREP_USED_FALLBACK=true
  fi

  if [[ -n "${violations}" ]]; then
    echo "${violations}"
    if [[ "${using_pcre}" == true ]]; then
      return 1
    fi
  fi

  return 0
}

count_in_array_calls() {
  grep -r "in_array(" "${TARGET_PATHS[@]}" 2>/dev/null | wc -l | tr -d ' '
}

count_files_scanned() {
  find "${TARGET_PATHS[@]}" -type f \( -name "*.php" -o -path "*/error_handler.php" \) | wc -l | tr -d ' '
}

generate_report() {
  mkdir -p "${REPORT_DIR}"
  local phpcs_output="$1"
  local grep_output="$2"
  local violations=$3
  local files_scanned
  files_scanned=$(count_files_scanned)
  local in_array_total
  in_array_total=$(count_in_array_calls)

  cat <<REPORT >"${REPORT_FILE}"
Strict Comparison Verification Report
====================================
Generated: ${TIMESTAMP}

Directories scanned:
$(printf '  - %s
' "${TARGET_PATHS[@]}")

Files scanned: ${files_scanned}
Total in_array() calls: ${in_array_total}
Violations found: ${violations}

PHPCS Output:
-------------
${phpcs_output:-<no output>}

Grep Output:
------------
${grep_output:-<no output>}
REPORT

  success "Report written to ${REPORT_FILE}"
}

display_summary() {
  local violations=$1
  local files_scanned
  files_scanned=$(count_files_scanned)
  local in_array_total
  in_array_total=$(count_in_array_calls)

  echo ""
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "  STRICT COMPARISON VERIFICATION"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  printf "Files scanned: %s\n" "${files_scanned}"
  printf "Total in_array() calls: %s\n" "${in_array_total}"
  printf "Violations found: %s\n" "${violations}"

  if [[ "${violations}" -eq 0 ]]; then
    success "Compliance: 100%%"
  else
    error "Compliance below target"
    echo "Next steps:"
    echo "  1. Review report"
    echo "  2. Add strict parameter"
    echo "  3. Re-run verification"
  fi
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
}

main() {
  parse_args "$@"
  check_dependencies

  local phpcs_output
  local violations=0

  if ! phpcs_output=$(run_phpcs); then
    violations=1
  fi

  # Run grep check for informational purposes only
  run_grep_check >/tmp/strict-grep.log 2>&1 || true
  if [[ "${GREP_USED_FALLBACK}" == true ]]; then
    info "Fallback grep results are informational only (see /tmp/strict-grep.log); PHPCS is authoritative for failures."
  fi

  if [[ "${violations}" -ne 0 && "${FLAG_FIX}" == true ]]; then
    run_phpcbf
    violations=0
    if ! phpcs_output=$(run_phpcs); then
      violations=1
    fi
    # Re-run grep check for informational purposes
    run_grep_check >/tmp/strict-grep.log 2>&1 || true
    if [[ "${GREP_USED_FALLBACK}" == true ]]; then
      info "Fallback grep results are informational only (see /tmp/strict-grep.log); PHPCS is authoritative for failures."
    fi
  fi

  local grep_output=""
  if [[ -f /tmp/strict-grep.log ]]; then
    grep_output="$(cat /tmp/strict-grep.log)"
  fi

  if [[ "${FLAG_REPORT}" == true || "${violations}" -ne 0 ]]; then
    generate_report "${phpcs_output}" "${grep_output}" "${violations}"
  fi

  display_summary "${violations}"

  if [[ "${violations}" -ne 0 ]]; then
    exit 1
  fi

  exit 0
}

main "$@"
