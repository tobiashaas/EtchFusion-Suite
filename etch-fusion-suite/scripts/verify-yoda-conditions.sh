#!/bin/bash

set -euo pipefail

# -----------------------------------------------------------------------------
# verify-yoda-conditions.sh
# -----------------------------------------------------------------------------
# Verifies that PHP sources under includes/ comply with the
# WordPress.PHP.YodaConditions sniff. Provides optional regex-based heuristics
# for quick detection, generates a categorized report, and supports a
# "fix-preview" mode that highlights candidate automated swaps without
# mutating files.
#
# Usage:
#   ./scripts/verify-yoda-conditions.sh [--report] [--fix-preview]
#
# Exit codes:
#   0 - All checks pass
#   1 - Violations found
#   2 - Script error (missing dependencies, invalid options)
# -----------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHPCS_BIN="${PROJECT_ROOT}/vendor/bin/phpcs"
PHPCS_STANDARD="${PROJECT_ROOT}/phpcs.xml.dist"
TARGET_PATH="${PROJECT_ROOT}/includes"
REPORT_DIR="${PROJECT_ROOT}/docs"
REPORT_FILE="${REPORT_DIR}/yoda-conditions-violations-report.md"
TIMESTAMP="$(date +%Y-%m-%d' '%H:%M:%S)"
TMP_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t 'verify-yoda')"
trap 'rm -rf "${TMP_DIR}"' EXIT

FLAG_REPORT=false
FLAG_FIX_PREVIEW=false
JQ_AVAILABLE=true

print_usage() {
  cat <<"USAGE"
Usage: verify-yoda-conditions.sh [options]

Options:
  --report       Always regenerate docs/yoda-conditions-violations-report.md
  --fix-preview  Show hypothetical before/after swaps for simple comparisons
  --help         Show this help text
USAGE
}

error() {
  echo "[✗] $*" >&2
}

info() {
  echo "[•] $*"
}

warn() {
  echo "[!] $*" >&2
}

success() {
  echo "[✓] $*"
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --report)
        FLAG_REPORT=true
        ;;
      --fix-preview)
        FLAG_FIX_PREVIEW=true
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

  if ! command -v sed >/dev/null 2>&1; then
    error "sed command not available."
    exit 2
  fi

  if ! command -v awk >/dev/null 2>&1; then
    error "awk command not available."
    exit 2
  fi

  if ! command -v jq >/dev/null 2>&1; then
    warn "jq command not available. JSON summaries will be skipped."
    JQ_AVAILABLE=false
  fi

  success "Required dependencies OK"
}

run_phpcs() {
  info "Running PHPCS YodaConditions sniff"
  local output_file="${TMP_DIR}/phpcs-yoda.txt"
  if ! "${PHPCS_BIN}" \
      --standard="${PHPCS_STANDARD}" \
      --sniffs=WordPress.PHP.YodaConditions \
      "${TARGET_PATH}" \
      >"${output_file}" 2>&1; then
    cat "${output_file}"
    return 1
  fi
  cat "${output_file}"
  return 0
}

parse_phpcs_output() {
  if [[ "${JQ_AVAILABLE}" != true ]]; then
    return 1
  fi

  local phpcs_output_file="${TMP_DIR}/phpcs-yoda.txt"
  local json_file="${TMP_DIR}/phpcs-yoda.json"

  if ! "${PHPCS_BIN}" \
      --standard="${PHPCS_STANDARD}" \
      --sniffs=WordPress.PHP.YodaConditions \
      --report=json \
      "${TARGET_PATH}" >"${json_file}" 2>/dev/null; then
    # JSON report may fail if sniff errors out; ignore for now
    return 1
  fi

  echo "${json_file}"
}

run_regex_scan() {
  info "Running heuristic regex scan for likely non-Yoda comparisons"
  local results_file="${TMP_DIR}/regex-scan.txt"
  # Matches var === literal/number/bool/null while excluding already-Yoda cases
  local regex_pattern
  local already_yoda_pattern
  regex_pattern=$'\$[A-Za-z_][A-Za-z0-9_]*\s*(===|!==|==|!=)\s*("[^"]*"|\'[^\']*\'|[0-9]+|true|false|null|[A-Z_][A-Z0-9_]*)'
  already_yoda_pattern=$'("[^"]*"|\'[^\']*\'|[0-9]+|true|false|null|[A-Z_][A-Z0-9_]*)\s*(===|!==|==|!=)\s*\$[A-Za-z_]'

  LC_ALL=C grep -RInE "${regex_pattern}" "${TARGET_PATH}" \
    | grep -vE "${already_yoda_pattern}" \
    >"${results_file}" || true
  echo "${results_file}"
}

categorize_violations() {
  if [[ "${JQ_AVAILABLE}" != true ]]; then
    return 1
  fi

  local json_file="$1"
  local categories_file="${TMP_DIR}/categories.txt"

  if [[ ! -f "${json_file}" ]]; then
    return 1
  fi

  jq -r '
    .files | to_entries | map({file: .key, messages: .value.messages}) | .[] |
    .messages[] | select(.source | startswith("WordPress.PHP.YodaConditions")) |
    {file: .file, line: .line, message: .message}
  ' "${json_file}" 2>/dev/null \
    | awk -F: '{
        file=$1; rest=$0;
        gsub(/^[ "]+|[ "]+$/, "", file);
        if (file ~ /includes\/security\//) category="Security";
        else if (file ~ /includes\/ajax\//) category="AJAX";
        else if (file ~ /includes\/services\//) category="Services";
        else if (file ~ /includes\/controllers\//) category="Controllers";
        else if (file ~ /includes\/repositories\//) category="Repositories";
        else if (file ~ /includes\/converters\// || file ~ /css_converter\.php/ || file ~ /gutenberg_generator\.php/) category="Converters";
        else category="Other";
        print category ":" rest;
      }' >"${categories_file}" || true

  echo "${categories_file}"
}

print_fix_preview() {
  local regex_file="$1"

  if [[ ! -s "${regex_file}" ]]; then
    info "No regex matches found for fix preview."
    return
  fi

  echo ""
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "  FIX PREVIEW (heuristic)"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

  awk -F: 'NR<=50 {
    file=$1; line=$2; text=$0;
    sub(/^[^:]+:[^:]+:/, "", text);
    gsub(/^\s+|\s+$/, "", text);
    printf "File: %s (line %s)\n", file, line;
    printf "  Before: %s\n", text;
    if (text ~ /(===|!==|==|!=)/) {
      split(text, parts, /(===|!==|==|!=)/);
      op=parts[2];
      left=parts[1]; right=parts[3];
      gsub(/^\s+|\s+$/, "", left);
      gsub(/^\s+|\s+$/, "", right);
      printf "  After:  %s %s %s\n", right, op, left;
    }
    print "";
  }
  NR==50 {print "  … (additional matches truncated)"}
  ' "${regex_file}"

  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo ""
}

generate_report() {
  local phpcs_output="$1"
  local json_file="$2"
  local regex_file="$3"

  mkdir -p "${REPORT_DIR}"

  local total_violations_display="0"
  if [[ "${JQ_AVAILABLE}" == true && -f "${json_file}" ]]; then
    total_violations_display=$(jq '[.files[].messages[] | select(.source | startswith("WordPress.PHP.YodaConditions"))] | length' "${json_file}" 2>/dev/null || echo 0)
  elif [[ "${JQ_AVAILABLE}" != true ]]; then
    total_violations_display="Unavailable (jq not installed)"
  fi

  local by_category=""
  if [[ -f "${TMP_DIR}/categories.txt" ]]; then
    by_category=$(awk -F: '{count[$1]++} END {for (cat in count) printf "- %s: %d\n", cat, count[cat]}' "${TMP_DIR}/categories.txt")
  elif [[ "${JQ_AVAILABLE}" != true ]]; then
    by_category="_- Skipped (jq not installed) -_"
  fi

  local regex_matches=0
  if [[ -s "${regex_file}" ]]; then
    regex_matches=$(wc -l <"${regex_file}" | tr -d ' ')
  fi

  cat <<EOF >"${REPORT_FILE}"
# Yoda Conditions Violations Report

**Generated:** ${TIMESTAMP}

## 1. Executive Summary
- PHPCS violations: ${total_violations_display}
- Regex heuristic matches: ${regex_matches}
- Target scope: \
[includes/](../includes)

## 2. Violations by Directory
${by_category:-_- None detected by PHPCS -_}

## 3. Detailed PHPCS Output

default report (truncated):

${phpcs_output:-<no output>}

## 4. Regex Candidates

$(if [[ -s "${regex_file}" ]]; then head -n 200 "${regex_file}"; else echo "No heuristic matches found."; fi)

## 5. Next Steps
1. Prioritize Security and AJAX directories.
2. Apply Yoda conversions for literals, constants, numbers, booleans, and nulls.
3. Manually review variable-to-variable comparisons for readability.
4. Re-run ./scripts/verify-yoda-conditions.sh --report until both PHPCS and regex checks are clean.

---

> Generated automatically by \
[scripts/verify-yoda-conditions.sh](../scripts/verify-yoda-conditions.sh)
EOF

  success "Report written to ${REPORT_FILE}"
}

summarize_results() {
  local phpcs_status=$1
  local regex_file="$2"
  local total_phpcs=0

  if [[ -f "${TMP_DIR}/categories.txt" ]]; then
    total_phpcs=$(awk -F: 'END {print NR}' "${TMP_DIR}/categories.txt")
  fi

  local regex_matches=0
  if [[ -s "${regex_file}" ]]; then
    regex_matches=$(wc -l <"${regex_file}" | tr -d ' ')
  fi

  echo ""
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "  YODA CONDITIONS VERIFICATION"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  printf "PHPCS violations: %d\n" "${total_phpcs}"
  printf "Regex matches:    %d\n" "${regex_matches}"

  if [[ "${phpcs_status}" -eq 0 && "${regex_matches}" -eq 0 ]]; then
    success "Compliance: 100%"
  else
    error "Compliance below target"
    echo "Recommended actions:"
    echo "  1. Review docs/yoda-conditions-violations-report.md"
    echo "  2. Address literal/constant comparisons first"
    echo "  3. Re-run verification script"
  fi

  if [[ "${JQ_AVAILABLE}" != true ]]; then
    warn "jq not installed; JSON categorization skipped."
  fi

  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
}

main() {
  parse_args "$@"
  check_dependencies

  local phpcs_status=0
  local phpcs_output=""

  if ! phpcs_output=$(run_phpcs); then
    phpcs_status=1
  fi

  local regex_file
  regex_file=$(run_regex_scan)

  local json_file=""
  if [[ "${JQ_AVAILABLE}" == true ]]; then
    json_file=$(parse_phpcs_output) || true
    categorize_violations "${json_file:-}" >/dev/null || true
  fi

  if [[ "${FLAG_FIX_PREVIEW}" == true ]]; then
    print_fix_preview "${regex_file}"
  fi

  if [[ "${FLAG_REPORT}" == true || "${phpcs_status}" -ne 0 || -s "${regex_file}" ]]; then
    generate_report "${phpcs_output}" "${json_file:-}" "${regex_file}"
  fi

  summarize_results "${phpcs_status}" "${regex_file}"

  if [[ "${phpcs_status}" -ne 0 || -s "${regex_file}" ]]; then
    exit 1
  fi

  exit 0
}

main "$@"
