#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
CONFIG_FILE="${REPO_ROOT}/config.php"
LOCAL_LATEST_FILE="${SCRIPT_DIR}/latest.json"
IMPORTS_FILE="${SCRIPT_DIR}/imports.txt"
REMOTE_LATEST_URL="https://developers.eveonline.com/static-data/tranquility/latest.jsonl"
SDE_ZIP_URL="https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip"

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

require_cmd curl
require_cmd unzip
require_cmd mktemp
require_cmd mongosh

if [[ ! -f "${CONFIG_FILE}" ]]; then
  echo "Could not find config file at ${CONFIG_FILE}" >&2
  exit 1
fi

mongo_uri="$({ sed -n 's/^[[:space:]]*\$mongoConnString[[:space:]]*=[[:space:]]*"\(.*\)";[[:space:]]*$/\1/p' "${CONFIG_FILE}" | head -n 1; } || true)"
if [[ -z "${mongo_uri}" ]]; then
  echo "Unable to parse \$mongoConnString from ${CONFIG_FILE}" >&2
  exit 1
fi

tmp_dir="$(mktemp -d)"
echo "Using temporary directory: ${tmp_dir}"
#cleanup() {
  #rm -rf "${tmp_dir}"
#}
#trap cleanup EXIT

remote_latest_file="${tmp_dir}/latest.remote.json"
zip_file="${tmp_dir}/sde.zip"
unzip_dir="${tmp_dir}/unzipped"
mkdir -p "${unzip_dir}"

curl -fsSL "${REMOTE_LATEST_URL}" -o "${remote_latest_file}"

if [[ -f "${LOCAL_LATEST_FILE}" ]] && cmp -s "${LOCAL_LATEST_FILE}" "${remote_latest_file}"; then
  echo "SDE is already current; no update required."
  exit 0
fi

echo "New SDE version detected; downloading archive..."
curl -fSL "${SDE_ZIP_URL}" -o "${zip_file}"
unzip -q "${zip_file}" -d "${unzip_dir}"

if [[ ! -f "${IMPORTS_FILE}" ]]; then
  echo "Could not find imports list at ${IMPORTS_FILE}" >&2
  exit 1
fi

mongosh_import_js="${tmp_dir}/import_jsonl.js"
cat > "${mongosh_import_js}" <<'MONGOSH_JS'
const fs = require('fs');

const jsonlPath = process.env.JSONL_FILE;
const collectionName = process.env.COLLECTION_NAME;

if (!jsonlPath || !collectionName) {
  throw new Error('JSONL_FILE and COLLECTION_NAME environment variables are required');
}

const collection = db.getCollection(collectionName);

function ensureUniqueSparseIndex(field, indexName) {
  const desiredKey = { [field]: 1 };
  try {
    collection.createIndex(desiredKey, { unique: true, sparse: true, name: indexName });
  } catch (err) {
    const msg = String(err && err.message ? err.message : err);
    const codeName = String(err && err.codeName ? err.codeName : '');
    const code = Number(err && err.code ? err.code : -1);

    const isExistingIndexConflict =
      /already exists|same name as the requested index|equivalent index already exists/i.test(msg) ||
      codeName === 'IndexOptionsConflict' ||
      codeName === 'IndexKeySpecsConflict' ||
      code === 85 ||
      code === 86;

    if (!isExistingIndexConflict) {
      throw err;
    }
  }
}

ensureUniqueSparseIndex('_key', '_key_unique');
ensureUniqueSparseIndex('key', 'key_unique');

const raw = fs.readFileSync(jsonlPath, 'utf8');
const lines = raw.split(/\r?\n/);
const batchSize = 1000;

let ops = [];
let processed = 0;
let upserts = 0;
let skipped = 0;
let parseErrors = 0;

function flush() {
  if (ops.length === 0) return;
  const result = collection.bulkWrite(ops, { ordered: false });
  upserts += (result.upsertedCount || 0) + (result.modifiedCount || 0) + (result.matchedCount || 0);
  ops = [];
}

for (const line of lines) {
  const trimmed = line.trim();
  if (!trimmed) continue;

  let doc;
  try {
    doc = JSON.parse(trimmed);
  } catch (err) {
    parseErrors += 1;
    continue;
  }

  processed += 1;

  const canonicalKey = doc.key !== undefined && doc.key !== null ? doc.key : (doc._key !== undefined && doc._key !== null ? doc._key : null);
  if (canonicalKey === null) {
    skipped += 1;
    continue;
  }

  doc.key = canonicalKey;
  doc._key = canonicalKey;

  ops.push({
    replaceOne: {
      filter: { $or: [{ key: canonicalKey }, { _key: canonicalKey }] },
      replacement: doc,
      upsert: true,
    },
  });

  if (ops.length >= batchSize) {
    flush();
  }
}

flush();

print(`Imported ${collectionName}: processed=${processed}, replaced_or_matched=${upserts}, skipped_no_key=${skipped}, parse_errors=${parseErrors}`);
MONGOSH_JS

import_count=0
while IFS= read -r import_entry || [[ -n "${import_entry}" ]]; do
  import_entry="${import_entry%%#*}"
  import_entry="${import_entry//[$'\t\r\n ']/}"

  if [[ -z "${import_entry}" ]]; then
    continue
  fi

  target_file="${import_entry}"
  if [[ "${target_file}" != *.jsonl ]]; then
    target_file="${target_file}.jsonl"
  fi

  jsonl_file="$(find "${unzip_dir}" -type f -name "${target_file}" | head -n 1)"
  if [[ -z "${jsonl_file}" ]]; then
    echo "Listed import file not found in archive: ${target_file}" >&2
    exit 1
  fi

  base_name="$(basename "${jsonl_file}")"
  collection="sde_${base_name%.jsonl}"
  echo "Importing ${base_name} -> ${collection}"
  JSONL_FILE="${jsonl_file}" COLLECTION_NAME="${collection}" mongosh "${mongo_uri}" --quiet --file "${mongosh_import_js}"
  import_count=$((import_count + 1))
done < "${IMPORTS_FILE}"

if [[ ${import_count} -eq 0 ]]; then
  echo "No valid import entries found in ${IMPORTS_FILE}" >&2
  exit 1
fi

mv "${remote_latest_file}" "${LOCAL_LATEST_FILE}"
echo "SDE import complete. Updated ${LOCAL_LATEST_FILE}."
