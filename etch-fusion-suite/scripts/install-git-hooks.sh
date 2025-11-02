#!/bin/bash

set -euo pipefail

# -----------------------------------------------------------------------------
# install-git-hooks.sh
# -----------------------------------------------------------------------------
# Installs the Etch Fusion Suite Git hooks from the scripts/ directory into the
# local .git/hooks path. Backs up existing hooks before overwriting.
# -----------------------------------------------------------------------------

SCRIPT_NAME=${0##*/}
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
HOOK_SOURCE="${SCRIPT_DIR}/pre-commit"
HOOK_DEST="${PROJECT_ROOT}/.git/hooks/pre-commit"
HOOK_BACKUP="${HOOK_DEST}.backup"

usage() {
  cat <<'USAGE'
Usage: ./scripts/install-git-hooks.sh [--force]

Options:
  --force   Overwrite existing hooks without prompting (backup retained)
  --help    Show this help text
USAGE
}

confirm_overwrite() {
  local response
  read -r -p "Existing pre-commit hook detected. Overwrite? (y/N) " response || true
  case "${response}" in
    [yY][eE][sS]|[yY])
      return 0
      ;;
    *)
      echo "[✗] Installation aborted by user."
      exit 1
      ;;
  esac
}

main() {
  local force=false

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --force)
        force=true
        ;;
      --help|-h)
        usage
        exit 0
        ;;
      *)
        echo "[✗] Unknown option: $1" >&2
        usage
        exit 2
        ;;
    esac
    shift
  done

  if [[ ! -f "${HOOK_SOURCE}" ]]; then
    echo "[✗] Source hook not found at ${HOOK_SOURCE}" >&2
    exit 2
  fi

  mkdir -p "$(dirname "${HOOK_DEST}")"

  if [[ -f "${HOOK_DEST}" ]]; then
    # Always create backup if hook exists
    cp "${HOOK_DEST}" "${HOOK_BACKUP}"
    echo "[•] Existing hook backed up to ${HOOK_BACKUP}"
    
    # Only prompt if not forced
    if [[ ${force} == false ]]; then
      confirm_overwrite
    fi
  fi

  cp "${HOOK_SOURCE}" "${HOOK_DEST}"
  chmod +x "${HOOK_DEST}"

  echo "[✓] Pre-commit hook installed at .git/hooks/pre-commit"
  if [[ -f "${HOOK_BACKUP}" ]]; then
    echo "    Backup: ${HOOK_BACKUP}"
  fi
  echo ""
  echo "Usage:"
  echo "  - Hook runs PHPCS on staged PHP files"
  echo "  - Optional flag: scripts/pre-commit --verify-all"
  echo "  - Bypass (not recommended): git commit --no-verify"
}

main "$@"
