#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
ICON_DIR="${REPO_ROOT}/public/img/icons"
LOCAL_LATEST_FILE="${SCRIPT_DIR}/latest.json"

RELEASE_API_URL="https://api.github.com/repos/SentientTurtle/EVE-TurtleTools/releases/latest"
ASSET_NAME="Image.Export.Collection.zip"

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_cmd curl
require_cmd php
require_cmd unzip
require_cmd mktemp

tmp_dir="$(mktemp -d)"
cleanup() {
  rm -rf "${tmp_dir}"
}
trap cleanup EXIT

release_file="${tmp_dir}/release.json"
remote_latest_file="${tmp_dir}/latest.remote.json"
zip_file="${tmp_dir}/${ASSET_NAME}"

curl_args=(
  -fsSL
  --retry 3
  --retry-delay 5
  -H "Accept: application/vnd.github+json"
  -H "User-Agent: zKillboard-icon-updater"
)

if [[ -n "${GITHUB_TOKEN:-}" ]]; then
  curl_args+=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
fi

curl "${curl_args[@]}" "${RELEASE_API_URL}" -o "${release_file}"

php -r '
$releasePath = $argv[1];
$assetName = $argv[2];
$outPath = $argv[3];

$release = json_decode(file_get_contents($releasePath), true);
if (!is_array($release)) {
    fwrite(STDERR, "Could not parse GitHub release JSON.\n");
    exit(1);
}

$asset = null;
foreach (($release["assets"] ?? []) as $candidate) {
    if (($candidate["name"] ?? "") === $assetName) {
        $asset = $candidate;
        break;
    }
}

if ($asset === null) {
    fwrite(STDERR, "Release asset not found: $assetName\n");
    exit(1);
}

$latest = [
    "release_id" => $release["id"] ?? null,
    "tag_name" => $release["tag_name"] ?? null,
    "published_at" => $release["published_at"] ?? null,
    "asset_id" => $asset["id"] ?? null,
    "asset_name" => $asset["name"] ?? null,
    "asset_size" => $asset["size"] ?? null,
    "asset_digest" => $asset["digest"] ?? null,
    "asset_updated_at" => $asset["updated_at"] ?? null,
    "browser_download_url" => $asset["browser_download_url"] ?? null,
];

if (empty($latest["browser_download_url"])) {
    fwrite(STDERR, "Release asset has no browser_download_url.\n");
    exit(1);
}

file_put_contents($outPath, json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "${release_file}" "${ASSET_NAME}" "${remote_latest_file}"

if [[ -f "${LOCAL_LATEST_FILE}" ]] && cmp -s "${LOCAL_LATEST_FILE}" "${remote_latest_file}"; then
  echo "Icon export collection is already current; no update required."
  exit 0
fi

download_url="$(php -r '$data = json_decode(file_get_contents($argv[1]), true); echo $data["browser_download_url"] ?? "";' "${remote_latest_file}")"
digest="$(php -r '$data = json_decode(file_get_contents($argv[1]), true); echo $data["asset_digest"] ?? "";' "${remote_latest_file}")"
tag_name="$(php -r '$data = json_decode(file_get_contents($argv[1]), true); echo $data["tag_name"] ?? "unknown";' "${remote_latest_file}")"

if [[ -z "${download_url}" ]]; then
  echo "Unable to determine ${ASSET_NAME} download URL." >&2
  exit 1
fi

echo "New icon export collection detected: ${tag_name}"
echo "Downloading ${ASSET_NAME}..."
curl -fL --retry 3 --retry-delay 10 --connect-timeout 30 --speed-time 60 --speed-limit 1024 \
  -H "User-Agent: zKillboard-icon-updater" \
  "${download_url}" \
  -o "${zip_file}"

if [[ "${digest}" == sha256:* ]]; then
  expected_sha="${digest#sha256:}"
  actual_sha="$(php -r 'echo hash_file("sha256", $argv[1]);' "${zip_file}")"
  if [[ "${actual_sha}" != "${expected_sha}" ]]; then
    echo "Downloaded archive SHA-256 mismatch." >&2
    echo "Expected: ${expected_sha}" >&2
    echo "Actual:   ${actual_sha}" >&2
    exit 1
  fi
fi

while IFS= read -r zip_entry; do
  case "${zip_entry}" in
    ""|/*|..|../*|*/..|*/../*)
      echo "Unsafe zip entry path: ${zip_entry}" >&2
      exit 1
      ;;
  esac
done < <(unzip -Z1 "${zip_file}")

mkdir -p "${ICON_DIR}"
echo "Extracting ${ASSET_NAME} into ${ICON_DIR}..."
unzip -q -o "${zip_file}" -d "${ICON_DIR}"

cp "${remote_latest_file}" "${LOCAL_LATEST_FILE}"
echo "Icon export collection updated successfully."
