#!/usr/bin/env bash
#
# build.sh - Produce a distributable WordPress plugin zip with dev tooling stripped.
#
# Source of truth is the committed tree (git archive HEAD), so untracked or local
# files can never leak into a release. Output is build/domain-monitor-<version>.zip
# with a single top-level domain-monitor/ directory, as WordPress requires.
#
# Usage:
#   bin/build.sh                  # WordPress.org-clean build (no self-updater)
#   bin/build.sh --with-updater   # GitHub "dogfood" build (bundles plugin-update-checker)
#   bin/build.sh --keep-staging   # leave the unzipped staging dir for inspection
# Flags may be combined. Set ALLOW_DIRTY=1 to build from a dirty tree.
#
set -euo pipefail

SLUG="domain-monitor"
UPDATER_PACKAGE="yahnis-elsts/plugin-update-checker:^5.6"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

BUILD_DIR="${ROOT_DIR}/build"
STAGING_DIR="${BUILD_DIR}/${SLUG}"

KEEP_STAGING=0
WITH_UPDATER=0
for arg in "$@"; do
  case "${arg}" in
    --keep-staging) KEEP_STAGING=1 ;;
    --with-updater) WITH_UPDATER=1 ;;
    *) echo "error: unknown argument '${arg}'" >&2; exit 1 ;;
  esac
done

# --- required tools ---
for tool in git composer zip php tar; do
  command -v "${tool}" >/dev/null 2>&1 || { echo "error: required tool '${tool}' not found" >&2; exit 1; }
done

# --- refuse to build from a dirty tree ---
# The package is exported from HEAD (git archive), so uncommitted changes would
# silently NOT ship. Fail loudly instead. Set ALLOW_DIRTY=1 to override locally.
if [[ "${ALLOW_DIRTY:-0}" != "1" && -n "$(git status --porcelain)" ]]; then
  echo "error: working tree is dirty - commit or stash before building (or set ALLOW_DIRTY=1)" >&2
  git status --short >&2
  exit 1
fi

# --- version single-sourcing (plugin header is canonical; constant must agree) ---
PLUGIN_FILE="${ROOT_DIR}/${SLUG}.php"
HEADER_VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "${PLUGIN_FILE}" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
CONST_VERSION="$(grep -E "define\([[:space:]]*'DOMAIN_MONITOR_VERSION'" "${PLUGIN_FILE}" | head -1 | sed -E "s/.*'DOMAIN_MONITOR_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'.*/\1/")"

[[ -n "${HEADER_VERSION}" ]] || { echo "error: could not parse Version from plugin header" >&2; exit 1; }
if [[ "${HEADER_VERSION}" != "${CONST_VERSION}" ]]; then
  echo "error: version mismatch - header '${HEADER_VERSION}' != DOMAIN_MONITOR_VERSION '${CONST_VERSION}'" >&2
  exit 1
fi
VERSION="${HEADER_VERSION}"
if [[ "${WITH_UPDATER}" -eq 1 ]]; then
  echo "==> Building ${SLUG} ${VERSION} (GitHub dogfood channel, with self-updater)"
else
  echo "==> Building ${SLUG} ${VERSION} (WordPress.org-clean channel)"
fi

# --- clean staging ---
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGING_DIR}"

# --- export tracked files only ---
echo "==> Exporting tracked files (git archive HEAD)"
git archive HEAD | tar -x -C "${STAGING_DIR}"

# --- production autoloader (no dev deps) ---
echo "==> Installing production dependencies"
composer install \
  --working-dir="${STAGING_DIR}" \
  --no-dev \
  --classmap-authoritative \
  --optimize-autoloader \
  --no-interaction \
  --no-progress \
  --quiet

# --- optional: bundle the GitHub self-updater (dogfood channel only) ---
if [[ "${WITH_UPDATER}" -eq 1 ]]; then
  echo "==> Bundling self-updater (${UPDATER_PACKAGE})"
  composer require "${UPDATER_PACKAGE}" \
    --working-dir="${STAGING_DIR}" \
    --update-no-dev \
    --classmap-authoritative \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --quiet
fi

# --- prune dev-only files ---
echo "==> Pruning dev tooling"
DEV_PATHS=(
  "tests"
  "docs"
  "design"
  "bin"
  ".github"
  ".wp-env.json"
  ".wp-env.override.json"
  ".phpunit.result.cache"
  "phpunit.xml.dist"
  "package.json"
  "package-lock.json"
  "composer.json"
  "composer.lock"
  ".gitignore"
  ".gitattributes"
  ".editorconfig"
)
for p in "${DEV_PATHS[@]}"; do
  rm -rf "${STAGING_DIR:?}/${p}"
done
find "${STAGING_DIR}" -name '.DS_Store' -delete
find "${STAGING_DIR}" -name '.*.swp' -delete

# --- verify ---
echo "==> Verifying package"
for required in "${SLUG}.php" "uninstall.php" "src" "vendor/autoload.php"; do
  [[ -e "${STAGING_DIR}/${required}" ]] || { echo "error: missing required '${required}'" >&2; exit 1; }
done
if [[ -d "${STAGING_DIR}/vendor/phpunit" ]]; then
  echo "error: phpunit present in vendor (dev deps not stripped)" >&2; exit 1
fi
for forbidden in tests docs design bin phpunit.xml.dist package.json composer.json; do
  [[ -e "${STAGING_DIR}/${forbidden}" ]] && { echo "error: dev path '${forbidden}' leaked into package" >&2; exit 1; }
done
# Self-updater must match the requested channel.
if [[ "${WITH_UPDATER}" -eq 1 ]]; then
  [[ -d "${STAGING_DIR}/vendor/yahnis-elsts/plugin-update-checker" ]] || { echo "error: --with-updater requested but plugin-update-checker not bundled" >&2; exit 1; }
else
  [[ -e "${STAGING_DIR}/vendor/yahnis-elsts" ]] && { echo "error: self-updater leaked into WordPress.org-clean build" >&2; exit 1; }
fi

echo "==> php -l sweep"
lint_failed=0
while IFS= read -r -d '' f; do
  if ! php -l "${f}" >/dev/null 2>&1; then
    echo "error: syntax error in ${f}" >&2
    lint_failed=1
  fi
done < <(find "${STAGING_DIR}" -name '*.php' -print0)
[[ "${lint_failed}" -eq 0 ]] || exit 1

# --- package ---
ZIP_NAME="${SLUG}-${VERSION}.zip"
echo "==> Creating ${BUILD_DIR}/${ZIP_NAME}"
( cd "${BUILD_DIR}" && zip -rq "${ZIP_NAME}" "${SLUG}" )

# --- cleanup ---
[[ "${KEEP_STAGING}" -eq 0 ]] && rm -rf "${STAGING_DIR}"

echo "==> Done: build/${ZIP_NAME}"
( cd "${BUILD_DIR}" && unzip -l "${ZIP_NAME}" | tail -1 )
