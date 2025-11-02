#!/usr/bin/env bash

# build-release.sh
#
# Creates a production-ready distribution zip of the Etch Fusion Suite plugin.
# Usage:
#   scripts/build-release.sh [version]
#
# If no version argument is provided, the script will attempt to infer it from
# the latest git tag. The version is validated against the plugin header and the
# resulting archive is saved to the dist/ directory along with a SHA-256
# checksum.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SLUG="etch-fusion-suite"
PLUGIN_DIR="${ROOT_DIR}/${PLUGIN_SLUG}"
BUILD_DIR="${ROOT_DIR}/build"
DIST_DIR="${ROOT_DIR}/dist"
DISTIGNORE_FILE="${ROOT_DIR}/.distignore"
PLUGIN_FILE="${PLUGIN_DIR}/etch-fusion-suite.php"
ZIP_BASENAME="${PLUGIN_SLUG}-v"

log() {
  printf '[build-release] %s\n' "$1"
}

die() {
  printf '[build-release] Error: %s\n' "$1" >&2
  exit 1
}

ensure_command() {
  local cmd="$1"
  local hint="$2"
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    die "Command '${cmd}' not found. ${hint}"
  fi
}

extract_version_from_header() {
  local header_line
  header_line="$(grep -E "^[[:space:]]*\*[[:space:]]*Version:" "${PLUGIN_FILE}" | head -n1 || true)"
  if [[ -z "${header_line}" ]]; then
    die "Unable to locate plugin header version in ${PLUGIN_FILE}."
  fi
  # shellcheck disable=SC2001
  printf '%s' "${header_line}" | sed 's/.*Version:[[:space:]]*//' | tr -d '\r' | xargs
}

extract_version_from_constant() {
  local constant_line
  constant_line="$(grep -E "define\([[:space:]]*'ETCH_FUSION_SUITE_VERSION'" "${PLUGIN_FILE}" | head -n1 || true)"
  if [[ -z "${constant_line}" ]]; then
    die "Unable to locate ETCH_FUSION_SUITE_VERSION constant in ${PLUGIN_FILE}."
  fi
  printf '%s' "${constant_line}" | sed -E "s/.*'ETCH_FUSION_SUITE_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'.*/\1/"
}

verify_version_format() {
  local version="$1"
  local semver_regex='^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'
  if [[ ! "${version}" =~ ${semver_regex} ]]; then
    die "Version '${version}' is not a valid semantic version (expected X.Y.Z or pre-release suffix)."
  fi
}

compute_version() {
  local provided_version="${1:-}" inferred_version
  if [[ -n "${provided_version}" ]]; then
    echo "${provided_version}"
    return
  fi

  ensure_command git "Ensure git is installed and available in PATH."
  if ! inferred_version="$(git -C "${ROOT_DIR}" describe --tags --abbrev=0 2>/dev/null)"; then
    die "No version provided and unable to infer version from git tags."
  fi
  echo "${inferred_version}"
}

compute_checksum() {
  local file_path="$1" output_file="$2"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "${file_path}" > "${output_file}"
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "${file_path}" > "${output_file}"
  else
    die "Neither sha256sum nor shasum is available to compute checksums."
  fi
}

main() {
  ensure_command rsync "Install rsync to continue."
  ensure_command zip "Install zip (e.g., apt-get install zip)."
  ensure_command composer "Install composer (https://getcomposer.org/)."

  if [[ ! -d "${PLUGIN_DIR}" ]]; then
    die "Plugin directory not found at ${PLUGIN_DIR}."
  fi
  if [[ ! -f "${PLUGIN_FILE}" ]]; then
    die "Plugin bootstrap file not found at ${PLUGIN_FILE}."
  fi
  if [[ ! -f "${DISTIGNORE_FILE}" ]]; then
    die ".distignore file not found at ${DISTIGNORE_FILE}."
  fi

  local raw_version
  raw_version="$(compute_version "${1:-}")"
  raw_version="${raw_version#v}"
  verify_version_format "${raw_version}"

  local header_version constant_version
  header_version="$(extract_version_from_header)"
  constant_version="$(extract_version_from_constant)"

  if [[ "${header_version}" != "${raw_version}" ]]; then
    die "Version mismatch: Plugin header has ${header_version} but build version is ${raw_version}."
  fi
  if [[ "${constant_version}" != "${raw_version}" ]]; then
    die "Version mismatch: Version constant has ${constant_version} but build version is ${raw_version}."
  fi

  log "Building version ${raw_version}."

  log "Cleaning previous build artifacts."
  rm -rf "${BUILD_DIR}" "${DIST_DIR}"
  mkdir -p "${BUILD_DIR}" "${DIST_DIR}"

  local build_plugin_dir="${BUILD_DIR}/${PLUGIN_SLUG}"
  log "Copying plugin files with rsync (respecting .distignore)."
  rsync -av --delete --exclude-from="${DISTIGNORE_FILE}" "${PLUGIN_DIR}/" "${build_plugin_dir}/"

  pushd "${build_plugin_dir}" >/dev/null

  log "Removing existing vendor directory (if any)."
  rm -rf vendor

  log "Installing composer dependencies (production mode)."
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

  if [[ -f package.json ]]; then
    if command -v npm >/dev/null 2>&1; then
      local build_script
      build_script="$(npm pkg get scripts.build 2>/dev/null || echo "null")"
      if [[ "${build_script}" != "null" ]]; then
        log "Installing Node dependencies via npm ci."
        npm ci --no-audit --no-fund
        log "Running npm run build."
        npm run build
        log "Removing node_modules after build."
        rm -rf node_modules
      else
        log "No npm build script detected; skipping asset build."
      fi
    else
      log "npm not available; skipping asset build."
    fi
  fi

  popd >/dev/null

  local zip_path="${DIST_DIR}/${ZIP_BASENAME}${raw_version}.zip"
  log "Creating zip archive at ${zip_path}."
  (cd "${BUILD_DIR}" && zip -r -q "${zip_path}" "${PLUGIN_SLUG}")

  local checksum_file="${zip_path}.sha256"
  log "Generating SHA-256 checksum at ${checksum_file}."
  compute_checksum "${zip_path}" "${checksum_file}"

  log "Distribution contents:"
  unzip -l "${zip_path}" | head -n 20 || true

  log "Build complete. Files generated:"
  ls -lh "${DIST_DIR}" | tail -n +2

  log "Done."
}

main "$@"
