#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHPCS_BIN="${ROOT_DIR}/vendor/bin/phpcs"
PHPCS_CONFIG="${ROOT_DIR}/phpcs.xml.dist"
BACKLOG_DOC="${ROOT_DIR}/docs/phpcs-manual-fixes-backlog.md"
LOG_DIR="${ROOT_DIR}/docs"
TIME_STAMP="$(date '+%Y-%m-%d %H:%M')"
FILE_STAMP="$(date +%Y%m%d-%H%M%S)"
JSON_OUTPUT="/tmp/phpcs-violations-${FILE_STAMP}.json"
STDERR_LOG="${LOG_DIR}/phpcs-analyze-${FILE_STAMP}.stderr.log"

cd "${ROOT_DIR}"

if [[ ! -x "${PHPCS_BIN}" ]]; then
  echo "[analyze-phpcs] Error: vendor/bin/phpcs not found. Run 'composer install'." >&2
  exit 1
fi

if [[ ! -f "${PHPCS_CONFIG}" ]]; then
  echo "[analyze-phpcs] Error: phpcs.xml.dist not found at ${PHPCS_CONFIG}." >&2
  exit 2
fi

mkdir -p "${LOG_DIR}"

echo "[analyze-phpcs] Running PHPCS with JSON output..."
set +e
${PHPCS_BIN} --standard="${PHPCS_CONFIG}" --report=json -q > "${JSON_OUTPUT}" 2> "${STDERR_LOG}"
phpcs_exit_code=$?
set -e

if [[ ${phpcs_exit_code} -eq 0 ]]; then
  echo "[analyze-phpcs] PHPCS completed with exit code 0 (no errors)."
else
  echo "[analyze-phpcs] PHPCS exited with code ${phpcs_exit_code}. Review ${STDERR_LOG} for details."
fi

if [[ ! -s "${JSON_OUTPUT}" ]]; then
  echo "[analyze-phpcs] Error: PHPCS JSON output is empty or missing." >&2
  exit 3
fi

echo "[analyze-phpcs] PHPCS violations saved to ${JSON_OUTPUT}."

if command -v jq >/dev/null 2>&1; then
  if ! jq empty "${JSON_OUTPUT}" >/dev/null 2>&1; then
    echo "[analyze-phpcs] Error: PHPCS JSON output is not valid JSON." >&2
    echo "[analyze-phpcs] Review ${STDERR_LOG} for PHPCS diagnostics." >&2
    exit 4
  fi
fi

if command -v jq >/dev/null 2>&1; then
  echo "[analyze-phpcs] Analyzing violations with jq..."
  
  total_errors=$(jq '.totals.errors' "${JSON_OUTPUT}")
  total_warnings=$(jq '.totals.warnings' "${JSON_OUTPUT}")
  total_fixable=$(jq '.totals.fixable' "${JSON_OUTPUT}")
  
  echo "[analyze-phpcs] Total errors: ${total_errors}"
  echo "[analyze-phpcs] Total warnings: ${total_warnings}"
  echo "[analyze-phpcs] Fixable violations: ${total_fixable}"
  
  security_count=$(jq '[.files[].messages[] | select(.source | startswith("WordPress.Security"))] | length' "${JSON_OUTPUT}")
  yoda_count=$(jq '[.files[].messages[] | select(.source | startswith("WordPress.PHP.YodaConditions"))] | length' "${JSON_OUTPUT}")
  strict_count=$(jq '[.files[].messages[] | select(.source | contains("Strict"))] | length' "${JSON_OUTPUT}")
  prefix_count=$(jq '[.files[].messages[] | select(.source | startswith("WordPress.NamingConventions.PrefixAllGlobals"))] | length' "${JSON_OUTPUT}")
  i18n_count=$(jq '[.files[].messages[] | select(.source | startswith("WordPress.WP.I18n"))] | length' "${JSON_OUTPUT}")
  alternative_function_count=$(jq '[.files[].messages[] | select((.source | startswith("WordPress.WP.AlternativeFunctions")) and (.message | contains("date") | not))] | length' "${JSON_OUTPUT}")
  date_count=$(jq '[.files[].messages[] | select((.source | startswith("WordPress.WP.AlternativeFunctions")) and (.message | contains("date")))] | length' "${JSON_OUTPUT}")
  short_ternary_count=$(jq '[.files[].messages[] | select(.source == "Generic.Formatting.DisallowShortTernary.TernaryFound")] | length' "${JSON_OUTPUT}")
  other_count=$(jq '[.files[].messages[] | select((.source | startswith("WordPress.Security"))
      or (.source | startswith("WordPress.PHP.YodaConditions"))
      or (.source | contains("Strict"))
      or (.source | startswith("WordPress.NamingConventions.PrefixAllGlobals"))
      or (.source | startswith("WordPress.WP.I18n"))
      or (.source | startswith("WordPress.WP.AlternativeFunctions"))
      or (.source == "Generic.Formatting.DisallowShortTernary.TernaryFound")) | not] | length' "${JSON_OUTPUT}")

  echo "[analyze-phpcs] Security violations: ${security_count}"
  echo "[analyze-phpcs] Yoda conditions: ${yoda_count}"
  echo "[analyze-phpcs] Strict comparisons: ${strict_count}"
  echo "[analyze-phpcs] Date functions: ${date_count}"
  echo "[analyze-phpcs] Alternative functions: ${alternative_function_count}"
  echo "[analyze-phpcs] Hook prefixing: ${prefix_count}"
  echo "[analyze-phpcs] I18n issues: ${i18n_count}"
  echo "[analyze-phpcs] Short ternaries: ${short_ternary_count}"
  echo "[analyze-phpcs] Other coding standard issues: ${other_count}"
  
  total_remaining=$(( security_count + yoda_count + strict_count + prefix_count + i18n_count + alternative_function_count + date_count + short_ternary_count + other_count ))

  echo "[analyze-phpcs] Top violations:"
  if (( total_remaining > 0 )); then
    jq -r '.files | to_entries | map({file: .key, count: (.value.messages | length)}) | map(select(.count > 0)) | sort_by(.count) | reverse | .[0:10] | .[] | "\(.count)\t\(.file)"' "${JSON_OUTPUT}" | while IFS=$'\t' read -r count file; do
      printf "  %4d  %s\n" "${count}" "${file}"
    done
  else
    echo "  (none)"
  fi

  echo ""
  echo "[analyze-phpcs] Generating backlog update..."

  # Write backlog document
  {
    echo "# PHPCS Manual Fixes Backlog"
    echo ""
    echo "**Document Owner:** PHPCS Cleanup Initiative Team"
    echo "**Reference Config:** [phpcs.xml.dist](../phpcs.xml.dist)"
    echo "**Last Updated:** ${TIME_STAMP}"
    echo ""
    echo "---"
    echo ""
    echo "## Overview"
    echo ""
    echo "This backlog captures manual remediation tasks that remain after running the automated PHPCBF fixer."
    echo ""
    echo "- **Scope:** Active PHP sources in includes/, assets/, and etch-fusion-suite.php covered by [phpcs.xml.dist](../phpcs.xml.dist)."
    echo "- **How to update:** Run [scripts/analyze-phpcs-violations.sh](../scripts/analyze-phpcs-violations.sh) after run-phpcbf.sh or whenever new lint violations appear."
    echo ""
    echo "---"
    echo ""
    echo "## Remaining Violations Summary"
    echo ""
    echo "| Severity | Count | Notes |"
    echo "| --- | --- | --- |"
    echo "| 🔴 Critical | ${security_count} | Security-related sniffs (WordPress.Security.*) |"
    echo "| 🟠 High | $((yoda_count + strict_count)) | Yoda conditions, strict comparisons |"
    echo "| 🟡 Medium | $((date_count + alternative_function_count + prefix_count + i18n_count)) | Date functions, alternative functions, hook prefixing, I18n |"
    echo "| 🟢 Low | $((short_ternary_count + other_count)) | Formatting and miscellaneous style warnings |"
    echo ""
    echo "### Breakdown by Sniff Category"
    echo ""
    echo "| Category | Sniffs | Count | Priority |"
    echo "| --- | --- | --- |"
    echo "| Security | WordPress.Security.EscapeOutput, WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification | ${security_count} | 🔴 Critical |"
    echo "| Yoda Conditions | WordPress.PHP.YodaConditions | ${yoda_count} | 🟠 High |"
    echo "| Strict Comparisons | WordPress.PHP.StrictComparisons, WordPress.PHP.StrictInArray | ${strict_count} | 🟠 High |"
    echo "| Date Functions | WordPress.WP.AlternativeFunctions (date) | ${date_count} | 🟡 Medium |"
    echo "| Alternative Functions | WordPress.WP.AlternativeFunctions (non-date) | ${alternative_function_count} | 🟡 Medium |"
    echo "| Hook Prefixing | WordPress.NamingConventions.PrefixAllGlobals | ${prefix_count} | 🟡 Medium |"
    echo "| I18n | WordPress.WP.I18n | ${i18n_count} | 🟡 Medium |"
    echo "| Short Ternaries | Generic.Formatting.DisallowShortTernary.TernaryFound | ${short_ternary_count} | 🟢 Low |"
    echo "| Other | Other sniffs not listed above | ${other_count} | 🟢 Low |"
    echo ""
    echo "> Generated by scripts/analyze-phpcs-violations.sh on ${TIME_STAMP}."
    echo ""
    echo "---"
    echo ""
    
    if (( total_remaining == 0 )); then
      echo "## Status"
      echo ""
      echo "PHPCS currently reports **zero** outstanding manual fixes. Re-run this analysis after future code changes to keep the backlog current."
      echo ""
    else
      echo "## Next Manual Fix Targets"
      echo ""
      echo "- Tackle 🔴 and 🟠 items from the summary table first."
      echo "- Use the console output from this script to identify high-violation files."
      echo ""
      
      # Add file hotspots if any exist
      if (( total_remaining > 0 )); then
        echo "## File Hotspots"
        echo ""
        echo "| File | Violations |"
        echo "| --- | --- |"
        jq -r '.files | to_entries | map({file: .key, count: (.value.messages | length)}) | map(select(.count > 0)) | sort_by(.count) | reverse | .[0:10] | .[] | "| \(.file) | \(.count) |"' "${JSON_OUTPUT}"
        echo ""
      fi
    fi
    
    echo "## Data Sources"
    echo ""
    echo "- JSON report: ${JSON_OUTPUT}"
    echo "- PHPCS stderr: ${STDERR_LOG}"
    echo "- Regenerate: ./scripts/analyze-phpcs-violations.sh"
    echo ""
    echo "**Analysis generated:** ${TIME_STAMP}"
    echo ""
  } > "${BACKLOG_DOC}"
  
  echo "[analyze-phpcs] Backlog updated at ${BACKLOG_DOC}."
  
else
  echo "[analyze-phpcs] jq not available. Manual backlog update required."
  echo "[analyze-phpcs] Review ${JSON_OUTPUT} and update ${BACKLOG_DOC} manually."
fi

echo "[analyze-phpcs] Analysis complete."
exit 0
