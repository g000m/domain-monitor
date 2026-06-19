#!/usr/bin/env bash
#
# bump-version.sh - Set the plugin version in every place it lives, in one shot.
#
# The plugin header "Version:" is canonical (bin/build.sh refuses to build when
# it disagrees with the DOMAIN_MONITOR_VERSION constant). This script keeps those
# two in lockstep and also updates package.json, then verifies all three agree.
#
# It does NOT commit, tag, or push - that stays a deliberate manual step.
#
# Usage:
#   bin/bump-version.sh 0.1.2
#   bin/bump-version.sh           # prints the current version and exits
#
set -euo pipefail

SLUG="domain-monitor"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

PLUGIN_FILE="${ROOT_DIR}/${SLUG}.php"
PACKAGE_FILE="${ROOT_DIR}/package.json"

# --- helpers to read the current values ---------------------------------------
read_header_version() {
  grep -E '^[[:space:]]*\*[[:space:]]*Version:' "${PLUGIN_FILE}" | head -1 \
    | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]'
}
read_const_version() {
  grep -E "define\([[:space:]]*'DOMAIN_MONITOR_VERSION'" "${PLUGIN_FILE}" | head -1 \
    | sed -E "s/.*'DOMAIN_MONITOR_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'.*/\1/"
}

CURRENT="$(read_header_version)"

# --- no argument: report and exit ---------------------------------------------
if [[ $# -eq 0 ]]; then
  echo "current version: ${CURRENT}"
  echo "usage: bin/bump-version.sh <new-version>   (e.g. bin/bump-version.sh 0.1.2)"
  exit 0
fi

NEW="$1"

# --- validate the requested version (semver core, optional pre-release) -------
if [[ ! "${NEW}" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
  echo "error: '${NEW}' is not a valid version (expected MAJOR.MINOR.PATCH[-prerelease])" >&2
  exit 1
fi

if [[ "${NEW}" == "${CURRENT}" ]]; then
  echo "error: version is already ${NEW}; nothing to do" >&2
  exit 1
fi

# --- required tooling ---------------------------------------------------------
command -v perl >/dev/null 2>&1 || { echo "error: perl is required" >&2; exit 1; }

echo "==> Bumping ${SLUG}: ${CURRENT} -> ${NEW}"

# --- plugin header "Version:" -------------------------------------------------
perl -i -pe 's/^(\s*\*\s*Version:\s*).*$/${1}'"${NEW}"'/' "${PLUGIN_FILE}"

# --- DOMAIN_MONITOR_VERSION constant ------------------------------------------
perl -i -pe "s/(define\(\s*'DOMAIN_MONITOR_VERSION'\s*,\s*')[^']*(')/\${1}${NEW}\${2}/" "${PLUGIN_FILE}"

# --- package.json top-level "version" (first occurrence only) ------------------
if [[ -f "${PACKAGE_FILE}" ]]; then
  perl -0777 -i -pe 's/("version"\s*:\s*")[^"]*"/${1}'"${NEW}"'"/' "${PACKAGE_FILE}"
fi

# --- verify everything agrees -------------------------------------------------
HEADER_AFTER="$(read_header_version)"
CONST_AFTER="$(read_const_version)"
PKG_AFTER=""
if [[ -f "${PACKAGE_FILE}" ]]; then
  PKG_AFTER="$(grep -E '"version"[[:space:]]*:' "${PACKAGE_FILE}" | head -1 \
    | sed -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/')"
fi

fail=0
[[ "${HEADER_AFTER}" == "${NEW}" ]] || { echo "error: plugin header is '${HEADER_AFTER}', expected '${NEW}'" >&2; fail=1; }
[[ "${CONST_AFTER}"  == "${NEW}" ]] || { echo "error: DOMAIN_MONITOR_VERSION is '${CONST_AFTER}', expected '${NEW}'" >&2; fail=1; }
if [[ -f "${PACKAGE_FILE}" ]]; then
  [[ "${PKG_AFTER}" == "${NEW}" ]] || { echo "error: package.json version is '${PKG_AFTER}', expected '${NEW}'" >&2; fail=1; }
fi
[[ "${fail}" -eq 0 ]] || exit 1

echo "==> Updated:"
echo "    ${SLUG}.php  Version:                 ${HEADER_AFTER}"
echo "    ${SLUG}.php  DOMAIN_MONITOR_VERSION:  ${CONST_AFTER}"
[[ -f "${PACKAGE_FILE}" ]] && echo "    package.json version:             ${PKG_AFTER}"
echo
echo "Next:"
echo "    git commit -am \"chore(release): ${NEW}\""
echo "    git tag -a v${NEW} -m \"v${NEW}\" && git push origin main v${NEW}"
