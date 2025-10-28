#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
LOG_DIR="${ROOT_DIR}/docs"
PHPCBF_BIN="${ROOT_DIR}/vendor/bin/phpcbf"
PHPCS_BIN="${ROOT_DIR}/vendor/bin/phpcs"
PHPCS_CONFIG="${ROOT_DIR}/phpcs.xml.dist"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
CBF_LOG="${LOG_DIR}/phpcbf-output-${TIMESTAMP}.log"
PHPCS_POST_LOG="${LOG_DIR}/phpcs-post-cbf-${TIMESTAMP}.log"

usage() {
  cat <<USAGE
Usage: scripts/run-phpcbf.sh [options]

Options:
  --php-only   Limit post-run git diff statistics to PHP files
  --stash      Temporarily stash existing working tree changes before running PHPCBF
  -h, --help   Show this help message
USAGE
}

php_only=false
stash_before_run=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --php-only)
      php_only=true
      shift
      ;;
    --stash)
      stash_before_run=true
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[run-phpcbf] Unknown option: $1" >&2
      usage >&2
      exit 64
      ;;
  esac
done

cd "${ROOT_DIR}"

if [[ ! -x "${PHPCBF_BIN}" ]]; then
  echo "[run-phpcbf] Error: vendor/bin/phpcbf not found. Run 'composer install'." >&2
  exit 1
fi

if [[ ! -f "${PHPCS_CONFIG}" ]]; then
  echo "[run-phpcbf] Error: phpcs.xml.dist not found at ${PHPCS_CONFIG}." >&2
  exit 2
fi

if [[ ! -x "${PHPCS_BIN}" ]]; then
  echo "[run-phpcbf] Error: vendor/bin/phpcs not found. Run 'composer install'." >&2
  exit 3
fi

if ! command -v git >/dev/null 2>&1; then
  echo "[run-phpcbf] Error: git is required to run this script." >&2
  exit 3
fi

mkdir -p "${LOG_DIR}"

echo "[run-phpcbf] Working directory: ${ROOT_DIR}"

git_status="$(git status --porcelain)"
if [[ -n "${git_status}" ]]; then
  echo "[run-phpcbf] Warning: Working tree contains uncommitted changes." >&2
else
  echo "[run-phpcbf] Working tree is clean."
fi

stash_pushed=false
stash_summary="disabled"

restore_trap() {
  local exit_code="$1"
  if [[ "${stash_pushed}" == "true" ]]; then
    echo "[run-phpcbf] Restoring stashed changes..."
    set +e
    if ! git stash pop --quiet; then
      echo "[run-phpcbf] Warning: git stash pop encountered conflicts. Resolve manually." >&2
    fi
    set -e
  fi
  trap - EXIT
  exit "${exit_code}"
}

if [[ "${stash_before_run}" == "true" ]]; then
  echo "[run-phpcbf] --stash enabled."
  trap 'restore_trap "$?"' EXIT
  if git diff --quiet --ignore-submodules HEAD -- && git diff --quiet --cached --ignore-submodules HEAD --; then
    echo "[run-phpcbf] No changes detected; nothing to stash."
    stash_summary="enabled (no changes to stash)"
  else
    echo "[run-phpcbf] Stashing working tree changes..."
    stash_message="run-phpcbf-${TIMESTAMP}"
    set +e
    stash_output="$(git stash push --include-untracked --keep-index -m "${stash_message}" 2>&1)"
    stash_status=$?
    set -e
    if [[ ${stash_status} -ne 0 ]]; then
      echo "[run-phpcbf] Error: failed to stash changes." >&2
      printf '%s\n' "${stash_output}" >&2
      exit 4
    fi
    printf '%s\n' "${stash_output}"
    if [[ "${stash_output}" != *"No local changes to save"* ]]; then
      stash_pushed=true
      stash_summary="enabled (changes stashed)"
    else
      stash_summary="enabled (no changes to stash)"
    fi
  fi
fi

baseline_ref="$(git rev-parse HEAD)"
baseline_ref_short="$(git rev-parse --short HEAD)"
echo "[run-phpcbf] Baseline commit: ${baseline_ref_short}"
if [[ -n "${git_status}" ]]; then
  echo "[run-phpcbf] Note: Existing uncommitted changes will appear in the post-run diff." >&2
fi

backup_branch="backup/phpcbf-${TIMESTAMP}"
if git show-ref --verify --quiet "refs/heads/${backup_branch}"; then
  echo "[run-phpcbf] Backup branch ${backup_branch} already exists; skipping creation."
else
  git branch "${backup_branch}" >/dev/null 2>&1 && \
    echo "[run-phpcbf] Created backup branch ${backup_branch} at $(git rev-parse --short HEAD)."
fi

echo "[run-phpcbf] Running pre-execution PHPCS summary..."
set +e
pre_summary="$(${PHPCS_BIN} --standard="${PHPCS_CONFIG}" --report=summary 2>&1)"
set -e
printf '%s\n' "${pre_summary}"

echo "[run-phpcbf] Executing PHPCBF..."
if ! ${PHPCBF_BIN} --standard="${PHPCS_CONFIG}" --report=summary 2>&1 | tee "${CBF_LOG}"; then
  echo "[run-phpcbf] Error: PHPCBF execution failed. See ${CBF_LOG}." >&2
  exit 3
fi

echo "[run-phpcbf] PHPCBF summary saved to ${CBF_LOG}."

echo "[run-phpcbf] Running post-execution PHPCS summary..."
set +e
post_summary="$(${PHPCS_BIN} --standard="${PHPCS_CONFIG}" --report=summary 2>&1 | tee "${PHPCS_POST_LOG}")"
set -e
printf '%s\n' "${post_summary}"

echo "[run-phpcbf] PHPCS post-run summary saved to ${PHPCS_POST_LOG}."

if [[ "${php_only}" == "true" ]]; then
  diff_scope_description="PHP files (*.php)"
  diff_pathspec=("*.php")
else
  diff_scope_description="entire plugin directory"
  diff_pathspec=(".")
fi

modified_files="$(git diff --name-only "${baseline_ref}" -- "${diff_pathspec[@]}")"
modified_count="$(printf '%s\n' "${modified_files}" | sed '/^$/d' | wc -l | tr -d ' ')"

git_diff_stat="$(git diff --stat "${baseline_ref}" -- "${diff_pathspec[@]}")"

echo "[run-phpcbf] Git diff statistics (vs ${baseline_ref_short}, scope: ${diff_scope_description}):"
printf '%s\n' "${git_diff_stat}"

cat <<EOF

==================== PHPCBF SUMMARY ====================
Pre-run PHPCS summary:
${pre_summary}

Post-run PHPCS summary:
${post_summary}

Options:
- Diff scope: ${diff_scope_description}
- Stash mode: ${stash_summary}

Modified files (${modified_count}, scope: ${diff_scope_description}):
${modified_files:-<none>}

Log files:
- PHPCBF output: ${CBF_LOG}
- Post-run PHPCS summary: ${PHPCS_POST_LOG}

Baseline commit:
${baseline_ref}

Next steps:
1. Review the modifications above.
2. Run project tests (e.g., composer test).
3. Update documentation and changelog as required (capture diff scope and stash options used).
4. Commit the changes once validated.
=======================================================
EOF

if [[ "${stash_before_run}" == "true" ]]; then
  restore_trap 0
fi

exit 0
