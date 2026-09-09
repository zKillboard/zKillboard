// Usage: node setup/updateFitData.mjs [release]
import { readFile } from 'node:fs/promises';
import { writeFileSync, renameSync, unlinkSync, mkdtempSync, copyFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, basename } from 'node:path';
import { gzipSync, gunzipSync } from 'node:zlib';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { esf } from '../public/vendor/eveshipfit/esf_pb2.js';

const directory = new URL('../public/vendor/eveshipfit/', import.meta.url);
let release = process.argv[2];
const headers = { 'User-Agent': 'zKillboard fitting integration (https://zkillboard.com)' };
if (!release) {
    const response = await fetch('https://api.github.com/repos/EVEShipFit/data/releases/latest', { headers, signal: AbortSignal.timeout(60000) });
    if (!response.ok) throw new Error('Unable to find the latest data release: ' + response.status);
    release = (await response.json()).tag_name.replace(/^v/, '');
}
if (!/^\d+(\.\d+)+$/.test(release)) throw new Error('Invalid data release.');

try {
    const installed = JSON.parse(gunzipSync(await readFile(new URL('data.json.gz', directory))));
    if (installed.release === release) {
        console.log('Fitting data is current: ' + release);
        process.exit(0);
    }
} catch (error) {
    if (error.code !== 'ENOENT') throw error;
}

const data = {};
await Promise.all([
    ['types', esf.Types],
    ['typeDogma', esf.TypeDogma],
    ['dogmaAttributes', esf.DogmaAttributes],
    ['dogmaEffects', esf.DogmaEffects]
].map(async ([name, decoder]) => {
    const response = await fetch('https://data.eveship.fit/v' + release + '/sde/' + name + '.pb2', { headers, signal: AbortSignal.timeout(60000) });
    if (!response.ok) throw new Error('Unable to download ' + name + ': ' + response.status);
    const decoded = await decoder.decode(response.body.getReader(), 0xffffffff);
    if (!Object.keys(decoded.entries).length) throw new Error('Empty data file: ' + name);
    // Preserve the decoder's prototype defaults when storing plain JSON.
    data[name] = JSON.parse(JSON.stringify(decoded.entries, (key, value) => {
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            const fields = {};
            for (const field in value) if (typeof value[field] !== 'function') fields[field] = value[field];
            return fields;
        }
        return value;
    }));
}));

// Publish one complete snapshot, so readers cannot mix data from different releases.
const temporaryDirectory = mkdtempSync(join(tmpdir(), 'zkb-fit-'));
const staged = join(temporaryDirectory, 'data.json.gz');
// The final rename must stay on the destination filesystem to remain atomic.
const publishing = new URL('.' + basename(temporaryDirectory) + '.tmp', directory);
let validation;
function cleanup() {
    try {
        try { unlinkSync(publishing); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    } finally {
        rmSync(temporaryDirectory, { recursive: true, force: true });
    }
}
process.once('exit', cleanup);
for (const [signal, code] of [['SIGINT', 130], ['SIGTERM', 143], ['SIGHUP', 129]]) {
    process.once(signal, () => {
        if (validation) validation.kill(signal);
        process.exit(code);
    });
}
try {
    // Synchronous file operations cannot complete after signal cleanup has run.
    writeFileSync(staged, gzipSync(JSON.stringify({ release, data })));
    const status = await new Promise((resolve, reject) => {
        validation = spawn(process.execPath, [fileURLToPath(new URL('../tests/fit-stats.mjs', import.meta.url)), staged], {
            stdio: 'inherit', timeout: 120000
        });
        validation.once('error', reject);
        validation.once('close', resolve);
    });
    validation = null;
    if (status !== 0) throw new Error('Fitting checks failed; existing data was retained.');
    copyFileSync(staged, publishing);
    renameSync(publishing, new URL('data.json.gz', directory));
    console.log('Updated fitting data to ' + release);
} finally {
    cleanup();
    process.removeListener('exit', cleanup);
}
