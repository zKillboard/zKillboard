// Usage: node setup/updateFitData.mjs [release]
import { readFile } from 'node:fs/promises';
import { writeFileSync, renameSync, unlinkSync, mkdtempSync, copyFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, basename } from 'node:path';
import { gzipSync, gunzipSync } from 'node:zlib';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const directory = new URL('../public/vendor/eveshipfit/', import.meta.url);
let release = process.argv[2];
const headers = { 'User-Agent': 'zKillboard fitting integration (https://zkillboard.com)' };
if (!release) {
    const response = await fetch('https://registry.npmjs.org/@eveshipfit%2Fsde/latest', { headers, signal: AbortSignal.timeout(60000) });
    if (!response.ok) throw new Error('Unable to find the latest data release: ' + response.status);
    release = (await response.json()).version;
}
if (!/^\d+(\.\d+)+$/.test(release)) throw new Error('Invalid data release.');

try {
    const installed = JSON.parse(gunzipSync(await readFile(new URL('data.json.gz', directory))));
    await readFile(new URL('sde.dat.gz', directory));
    if (installed.release === release && installed.filtered === true && Number.isInteger(installed.build)) {
        console.log('Fitting data is current: ' + release);
        process.exit(0);
    }
} catch (error) {
    if (error.code !== 'ENOENT') throw error;
}

const response = await fetch('https://cdn.jsdelivr.net/npm/@eveshipfit/sde@' + release + '/dist/sde.dat', { headers, signal: AbortSignal.timeout(60000) });
if (!response.ok) throw new Error('Unable to download fitting data: ' + response.status);
const sde = Buffer.from(await response.arrayBuffer());
if (sde.subarray(4, 8).toString() !== 'ESF1') throw new Error('Invalid fitting data.');

function field(table, index) {
    const vtable = table - sde.readInt32LE(table);
    const position = vtable + 4 + index * 2;
    if (position >= vtable + sde.readUInt16LE(vtable)) return 0;
    const offset = sde.readUInt16LE(position);
    return offset ? table + offset : 0;
}
function integer(table, index) {
    const position = field(table, index);
    return position ? sde.readInt32LE(position) : 0;
}
function boolean(table, index) {
    const position = field(table, index);
    return position ? sde.readUInt8(position) !== 0 : false;
}
function float(table, index) {
    const position = field(table, index);
    return position ? sde.readFloatLE(position) : undefined;
}
function string(table, index) {
    const position = field(table, index);
    if (!position) return undefined;
    const start = position + sde.readUInt32LE(position);
    return sde.toString('utf8', start + 4, start + 4 + sde.readUInt32LE(start));
}
function vector(table, index) {
    const position = field(table, index);
    if (!position) return { start: 0, length: 0 };
    const start = position + sde.readUInt32LE(position);
    return { start: start + 4, length: sde.readUInt32LE(start) };
}
function vectorTable(vector, index) {
    const position = vector.start + index * 4;
    return position + sde.readUInt32LE(position);
}

const root = sde.readUInt32LE(0);
const data = { types: {}, typeDogma: {}, dogmaAttributes: {}, dogmaEffects: {} };
const attributes = vector(root, 4);
for (let i = 0; i < attributes.length; i++) {
    const table = vectorTable(attributes, i);
    const id = integer(table, 0);
    data.dogmaAttributes[id] = { name: string(table, 1), displayName: string(table, 2) };
}
const effects = vector(root, 5);
for (let i = 0; i < effects.length; i++) {
    const table = vectorTable(effects, i);
    data.dogmaEffects[integer(table, 0)] = { name: string(table, 1) };
}
const types = vector(root, 1);
for (let i = 0; i < types.length; i++) {
    const table = vectorTable(types, i);
    const id = integer(table, 0);
    const type = {
        name: string(table, 1),
        groupID: integer(table, 2),
        categoryID: integer(table, 3),
        published: boolean(table, 4),
        factionID: integer(table, 5),
        marketGroupID: integer(table, 6),
        metaGroupID: integer(table, 7),
        raceID: integer(table, 8)
    };
    if (![6, 7, 8, 16, 18, 32, 65, 66, 87].includes(type.categoryID) && type.groupID !== 1306) continue;
    for (const [index, name] of [[9, 'capacity'], [10, 'mass'], [11, 'radius'], [12, 'volume']]) {
        const value = float(table, index);
        if (value !== undefined) type[name] = value;
    }
    data.types[id] = type;
    const dogmaAttributes = vector(table, 13);
    const dogmaEffects = vector(table, 14);
    if (dogmaAttributes.length || dogmaEffects.length) {
        data.typeDogma[id] = { dogmaAttributes: [], dogmaEffects: [] };
        for (let j = 0; j < dogmaAttributes.length; j++) {
            const position = dogmaAttributes.start + j * 8;
            data.typeDogma[id].dogmaAttributes.push({ attributeID: sde.readInt32LE(position), value: sde.readFloatLE(position + 4) });
        }
        for (let j = 0; j < dogmaEffects.length; j++) {
            const position = dogmaEffects.start + j * 8;
            data.typeDogma[id].dogmaEffects.push({ effectID: sde.readInt32LE(position), isDefault: sde.readUInt8(position + 4) !== 0 });
        }
    }
}
if (!Object.keys(data.types).length || !Object.keys(data.dogmaAttributes).length) throw new Error('Empty fitting data.');

const temporaryDirectory = mkdtempSync(join(tmpdir(), 'zkb-fit-'));
const stagedMetadata = join(temporaryDirectory, 'data.json.gz');
const stagedSde = join(temporaryDirectory, 'sde.dat.gz');
const publishingMetadata = new URL('.' + basename(temporaryDirectory) + '-data.tmp', directory);
const publishingSde = new URL('.' + basename(temporaryDirectory) + '-sde.tmp', directory);
let validation;
function cleanup() {
    try {
        for (const path of [publishingMetadata, publishingSde]) {
            try { unlinkSync(path); } catch (error) { if (error.code !== 'ENOENT') throw error; }
        }
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
    writeFileSync(stagedMetadata, gzipSync(JSON.stringify({ release, build: integer(root, 0), filtered: true, data })));
    writeFileSync(stagedSde, gzipSync(sde));
    const status = await new Promise((resolve, reject) => {
        validation = spawn(process.execPath, [fileURLToPath(new URL('../tests/fit-stats.mjs', import.meta.url)), stagedMetadata, stagedSde], {
            stdio: 'inherit', timeout: 120000
        });
        validation.once('error', reject);
        validation.once('close', resolve);
    });
    validation = null;
    if (status !== 0) throw new Error('Fitting checks failed; existing data was retained.');
    copyFileSync(stagedMetadata, publishingMetadata);
    copyFileSync(stagedSde, publishingSde);
    renameSync(publishingSde, new URL('sde.dat.gz', directory));
    renameSync(publishingMetadata, new URL('data.json.gz', directory));
    console.log('Updated fitting data to ' + release);
} finally {
    cleanup();
    process.removeListener('exit', cleanup);
}
