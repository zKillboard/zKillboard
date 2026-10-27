// Focused simulator checks using the shipped game data and real WASM engine.
// Run: node tests/simulate.mjs
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { gzipSync, gunzipSync } from 'node:zlib';
import { racks, slotCount, compatible, compatibleCharge, hasDroneBay, hasFighterBay, validateDrones, validateFighters, addModule, importEFT, importSimulation, importTypeIDFit, exportEFT, warnings } from '../public/js/simulate-model.js';

assert.doesNotMatch(await readFile(new URL('../public/js/simulate.js', import.meta.url), 'utf8'), /text-(?:muted|secondary|body-secondary)/, 'Simulator text must not use low-contrast gray utilities');

let result;
globalThis.self = { postMessage: value => { result = value; } };
const priceLookups = [];
let rateLimitedPrice;
let priceFallbacks = 0;
let characterLookups = 0;
globalThis.fetch = async url => {
    if (String(url).startsWith('https://esi.evetech.net/characters/')) {
        characterLookups++;
        return new Response(JSON.stringify({ name: 'Test Pilot', security_status: 4.2 }));
    }
    if (String(url).startsWith('/api/killID/')) {
        return new Response(JSON.stringify([{ victim: { items: [{ flag: 89, item_type_id: 3084, quantity_destroyed: 1 }] } }]));
    }
    if (String(url).startsWith('/api/prices/date/')) {
        priceLookups.push(url);
        if (!rateLimitedPrice) {
            rateLimitedPrice = url;
            return new Response('', { status: 429, headers: { 'Retry-After': '0' } });
        }
        return new Response(JSON.stringify(Object.fromEntries(Object.keys(catalog).filter(typeID => Number(typeID) !== byName('Avatar').id).map(typeID => [typeID, 100]))));
    }
    if (String(url).startsWith('/api/prices/')) {
        priceFallbacks++;
        return new Response(JSON.stringify({ currentPrice: 75000000000 }));
    }
    const path = new URL(url);
    path.search = '';
    return new Response(await readFile(path));
};
await import('../public/js/fit-stats-worker.js');
await self.onmessage({ data: { id: 1, catalog: true } });
assert.ok(!result.error, result.error);
const catalog = result.catalog;
const byName = name => Object.values(catalog).find(type => type.name === name);
async function calculate(fit, skillLevel = 5) {
    await self.onmessage({ data: { id: 2, fit, simulate: true, skillLevel } });
    assert.ok(!result.error, result.error);
    return result;
}
for (const ship of ['Confessor', 'Svipul', 'Jackdaw', 'Hecate']) {
    const modeFit = { ship_type_id: byName(ship).id, items: [] };
    const defense = await calculate(modeFit);
    const propulsion = await calculate({ ...modeFit, mode: 'Propulsion' });
    const sharpshooter = await calculate({ ...modeFit, mode: 'Sharpshooter' });
    assert.ok(propulsion.stats.maxVelocity > defense.stats.maxVelocity || propulsion.stats.alignTime < defense.stats.alignTime, ship + ' propulsion bonus');
    assert.ok(sharpshooter.stats.maxTargetRange > defense.stats.maxTargetRange, ship + ' targeting bonus');
    assert.ok(defense.stats.ehp > sharpshooter.stats.ehp, ship + ' defense bonus');
}
const fit = { ship_type_id: 587, name: 'Test fit', items: [] };
const uniformDamage = await calculate(fit);
const emDamage = await calculate({ ...fit, damageProfile: { em: 100, thermal: 0, kinetic: 0, explosive: 0 } });
assert.notEqual(emDamage.stats.ehp, uniformDamage.stats.ehp, 'Incoming damage profile changes effective HP');
const repairingFit = importEFT('[Rifter, Repair profile]\nSmall Armor Repairer I', catalog);
const uniformRepair = await calculate(repairingFit);
const emRepair = await calculate({ ...repairingFit, damageProfile: { em: 100, thermal: 0, kinetic: 0, explosive: 0 } });
assert.notEqual(emRepair.stats.armorEffectiveRepairRate, uniformRepair.stats.armorEffectiveRepairRate, 'Incoming damage profile changes effective repair rates');
const pacifier = { ship_type_id: byName('Pacifier').id, items: [] };
const lowSecurity = await calculate({ ...pacifier, securityStatus: -10 });
const highSecurity = await calculate({ ...pacifier, securityStatus: 5 });
assert.notEqual(lowSecurity.stats.concordTankBonus, highSecurity.stats.concordTankBonus, 'Pilot security status changes security-scaled ship bonuses');
const bare = await calculate(fit);
assert.equal(bare.stats.upgradeLoad, 0, 'An unrigged hull uses zero calibration');
const implantFit = importEFT("[Rifter, Implants]\nZainou 'Gnome' Shield Management SM-706", catalog);
assert.deepEqual(implantFit.implants, [{ type_id: 3084, slot: 7 }]);

const pulsar = byName('Class 1 Pulsar Effects');
assert.equal(pulsar.environmental, true);
assert.equal(byName('Abyssal Electrical Storm — Strength 1').environmentGroup, 'Abyssal weather');
const pulsarFit = { ...fit, spaceSecurity: 'wormhole', environmentEffect: { type_id: pulsar.id, enabled: true } };
const pulsarResult = await calculate(pulsarFit);
assert.ok(Math.abs(pulsarResult.stats.shieldCapacity / bare.stats.shieldCapacity - 1.3) < 0.00001, 'Class 1 Pulsar adds 30% shield HP through the real engine');
assert.ok(pulsarResult.environmentAffected.includes('shieldCapacity'));
assert.ok(pulsarResult.environmentAffected.includes('ehp'), 'Derived environmental changes are marked too');
const strongPulsar = await calculate({ ...pulsarFit, environmentEffect: { type_id: byName('Class 6 Pulsar Effects').id } });
assert.equal(strongPulsar.stats.shieldCapacity, bare.stats.shieldCapacity * 2, 'Wormhole class changes effect strength');
const noEnvironment = await calculate({ ...pulsarFit, environmentEffect: { ...pulsarFit.environmentEffect, enabled: false } });
assert.deepEqual(noEnvironment.stats, bare.stats, 'Disabling an environment restores baseline statistics');
assert.deepEqual(noEnvironment.environmentAffected, []);
const darkness = await calculate({ ...fit, environmentEffect: { type_id: byName('Abyssal Dark Matter Field — Strength 1').id } });
assert.equal(darkness.stats.maxVelocity, bare.stats.maxVelocity * 1.5, 'Abyssal buffs are applied as well as projected effects');
const environmentalImplants = await calculate({ ...implantFit, environmentEffect: pulsarFit.environmentEffect });
assert.ok(Math.abs(environmentalImplants.stats.shieldCapacity / bare.stats.shieldCapacity - 1.3 * 1.06) < 0.00001, 'Environment combines with implants');
for (const type of Object.values(catalog).filter(type => type.environmental)) {
    await calculate({ ...fit, environmentEffect: { type_id: type.id } });
}
for (const settings of [{ spaceSecurity: 'invalid' }, { environmentEffect: null }, { environmentEffect: { type_id: 587 } }, { environmentEffect: { type_id: pulsar.id, enabled: 'false' } }]) {
    await self.onmessage({ data: { id: 3, fit: { ...fit, ...settings }, simulate: true } });
    assert.match(result.error, /Invalid (space security|environmental effect)/);
    assert.throws(() => importSimulation(JSON.stringify({ ...fit, ...settings }), catalog), /Invalid (space security|environmental effect)/);
}
console.log('Environment engine checks passed: all supported beacons, class strength, Abyssal buffs, implants, disable, markers, and validation.');

const implanted = await calculate(implantFit);
assert.ok(implanted.stats.shieldCapacity > bare.stats.shieldCapacity, 'Implants affect ship statistics');
assert.equal(importEFT(exportEFT(implantFit, catalog), catalog).implants[0].type_id, 3084, 'EFT preserves implants');
assert.throws(() => importEFT("[Rifter, Implants]\nZainou 'Gnome' Shield Management SM-706\nZainou 'Gnome' Shield Management SM-704", catalog), /duplicate implant slot/);
assert.equal(importEFT("[Rifter, Implants]\nGenolution 'Auroral' AU-79", catalog).implants[0].slot, 79, 'Published special implant slots are supported');
assert.equal(byName("Agency 'Hardshell' TB9 Dose IV").attributes.implantness, undefined, 'Combat boosters are not implants');
const boosterFit = importEFT("[Rifter, Boosters]\nSmall Armor Repairer I\nStrong Exile Booster\nAgency 'Hardshell' TB9 Dose IV", catalog);
assert.equal(boosterFit.boosters.length, 2, 'Different booster slots can be combined');
const boostedRepair = await calculate(boosterFit);
assert.ok(boostedRepair.stats.armorEffectiveRepairRate > uniformRepair.stats.armorEffectiveRepairRate * 1.3, 'Combat drugs and Agency boosters affect repair rates together');
assert.ok(boostedRepair.boosterAffected.includes('armorEffectiveRepairRate'), 'Derived repair stats report booster effects');
assert.equal(boostedRepair.stats.armorHP, uniformRepair.stats.armorHP, 'Random drug side effects are not applied');
assert.deepEqual(importEFT(exportEFT(boosterFit, catalog), catalog).boosters, boosterFit.boosters, 'EFT preserves boosters');
assert.throws(() => importEFT('[Rifter, Boosters]\nStrong Exile Booster\nStandard Blue Pill Booster', catalog), /duplicate booster slot/);
const cargoBooster = importEFT('[Rifter, Cargo]\nStrong Exile Booster x2', catalog);
assert.equal(cargoBooster.boosters.length, 0, 'Cargo drugs do not activate as boosters');
assert.equal(cargoBooster.items[0].flag, 5);
assert.equal(importEFT('[Rifter, Cargo]\nStrong Exile Booster x2\nStrong Exile Booster x3', catalog).items[0].quantity, 5, 'Cargo booster stacks are combined');
const quafeFit = importEFT('[Rifter, Speed]\nQuafe Zero Classic', catalog);
const quafe = await calculate(quafeFit);
assert.ok(quafe.stats.maxVelocity > bare.stats.maxVelocity, 'Quafe changes speed');
assert.ok(quafe.boosterAffected.includes('maxVelocity'));
assert.ok(quafe.boosterAffected.includes('scanResolution'));
assert.ok(!quafe.boosterAffected.includes('shieldCapacity'), 'Unaffected stats do not get marked');
quafeFit.boosters[0].enabled = false;
const disabledBooster = await calculate(quafeFit);
assert.equal(disabledBooster.stats.maxVelocity, bare.stats.maxVelocity, 'Disabled boosters do not affect the fit');
assert.deepEqual(disabledBooster.boosterAffected, []);
await self.onmessage({ data: { id: 2, fit: { ...quafeFit, boosters: [{ type_id: byName('Quafe Zero Classic').id, slot: 2 }] }, simulate: true } });
assert.match(result.error, /Invalid or duplicate booster slot/);
await self.onmessage({ data: { id: 2, fit: { ...quafeFit, boosters: [quafeFit.boosters[0], quafeFit.boosters[0]] }, simulate: true } });
assert.match(result.error, /Invalid or duplicate booster slot/);
const rigged = await calculate(importEFT('[Rifter, Calibration]\nSmall Trimark Armor Pump I\nSmall Trimark Armor Pump I', catalog));
assert.equal(rigged.stats.upgradeLoad, 100, 'Calibration includes both fitted rigs');
const unskilled = await calculate(fit, 0);
assert.ok(unskilled.stats.cpuOutput < bare.stats.cpuOutput, 'All 0 must remove skill bonuses');
assert.equal(slotCount(racks[2], catalog[587], bare.stats), 3);
addModule(fit, catalog[438], catalog, bare.stats);
const active = await calculate(fit);
assert.ok(active.stats.maxVelocity > bare.stats.maxVelocity * 2);
fit.items[0].state = 'Online';
const online = await calculate(fit);
assert.equal(online.stats.maxVelocity, bare.stats.maxVelocity);
assert.ok(online.stats.cpuLoad > 0);
fit.items[0].state = 'Passive';
const offline = await calculate(fit);
assert.equal(offline.stats.cpuLoad, 0);
fit.items[0].state = 'Overload';
const hot = await calculate(fit);
assert.ok(hot.stats.maxVelocity > active.stats.maxVelocity);
assert.ok(compatibleCharge(catalog[3074], catalog[222]));
assert.ok(!compatibleCharge(catalog[3074], byName('Antimatter Charge M')));
assert.ok(!compatible(byName('Medium Trimark Armor Pump I'), catalog[587]));
addModule(fit, catalog[3074], catalog, bare.stats);
addModule(fit, catalog[3074], catalog, bare.stats);
addModule(fit, catalog[3074], catalog, bare.stats);
assert.throws(() => addModule(fit, catalog[3074], catalog, bare.stats), /No available/);
fit.items.push({ type_id: 222, flag: 27, quantity: 1 });
addModule(fit, byName('10MN Afterburner II'), catalog, bare.stats, 19);
const loaded = await calculate(fit);
assert.ok(loaded.stats.damagePerSecondWithoutReload > 0);
const spoolingFit = importEFT('[Nergal, Spool]\nLight Entropic Disintegrator II, Occult S', catalog);
const fullySpooled = await calculate(spoolingFit);
spoolingFit.items.find(item => item.type_id === byName('Light Entropic Disintegrator II').id).spoolBonus = 0;
const unspooled = await calculate(spoolingFit);
assert.ok(fullySpooled.stats.damagePerSecondWithoutReload > unspooled.stats.damagePerSecondWithoutReload, 'Spool level changes spooling module DPS');
assert.ok(warnings(fit, catalog, loaded.stats, loaded.character).includes('Powergrid capacity exceeded.'));
const text = exportEFT(fit, catalog);
const imported = importEFT(text, catalog);
assert.equal(exportEFT(imported, catalog), text);
const gapped = importEFT('[Tristan, Empty slots]\n[Empty High slot]\n150mm Railgun II, Antimatter Charge S\n\nHobgoblin I x2\nAntimatter Charge S x100', catalog);
assert.equal(gapped.items.find(item => item.type_id === 3074).flag, 28);
assert.equal(exportEFT(importEFT(exportEFT(gapped, catalog), catalog), catalog), exportEFT(gapped, catalog));
assert.throws(() => importEFT('[Rifter, Test]\nInvalid Item', catalog), /Unknown item/);
assert.throws(() => importEFT('[Rifter, Test]\n150mm Railgun II, Antimatter Charge M', catalog), /Incompatible charge/);
assert.throws(() => importEFT('[Rifter, Test]\nHobgoblin I x9999999', catalog), /quantities/);
assert.throws(() => importEFT('[Rifter, Test]\nMedium Trimark Armor Pump I', catalog), /Incompatible module/);
assert.throws(() => importEFT('[Rifter, Test]\n' + '150mm Railgun II\n'.repeat(9), catalog), /Too many/);
const droneFit = { ship_type_id: 626, name: 'Drones', items: [{ type_id: 2454, flag: 87, quantity: 5, active: 0 }] };
const bay = await calculate(droneFit);
droneFit.items[0].active = 5;
const deployed = await calculate(droneFit);
assert.ok(deployed.stats.damagePerSecondWithoutReload > bay.stats.damagePerSecondWithoutReload);
assert.ok(deployed.stats.droneBandwidthLoad > 0);
droneFit.items[0].quantity = 6;
droneFit.items[0].active = 6;
const tooMany = await calculate(droneFit);
assert.ok(warnings(droneFit, catalog, tooMany.stats, tooMany.character).includes('Too many deployed drones.'));
const legion = { ship_type_id: 29986, name: 'Subsystems', items: [] };
const legionBare = await calculate(legion);
addModule(legion, byName('Legion Core - Augmented Antimatter Reactor'), catalog, legionBare.stats);
const legionCore = await calculate(legion);
assert.ok(legionCore.stats.lowSlots > legionBare.stats.lowSlots);
assert.equal(legion.items[0].flag, 125);
assert.ok(!compatible(byName('Legion Core - Augmented Antimatter Reactor'), catalog[587]));
// Hardpoints are enforced from current items, even before another calculation finishes.
const drake = { ship_type_id: byName('Drake').id, name: 'Launcher limits', items: [] };
const drakeBare = await calculate(drake);
for (let i = 0; i < 6; i++) addModule(drake, byName('Heavy Missile Launcher II'), catalog, drakeBare.stats);
const sixLaunchers = JSON.stringify(drake);
assert.throws(() => addModule(drake, byName('Heavy Missile Launcher II'), catalog, drakeBare.stats), /launcher hardpoints/);
assert.equal(JSON.stringify(drake), sixLaunchers, 'Rejected additions must leave the fit intact');
assert.throws(() => addModule(drake, byName('Heavy Missile Launcher II'), catalog, drakeBare.stats, 33), /launcher hardpoints/);
addModule(drake, byName('Heavy Missile Launcher II'), catalog, drakeBare.stats, 27);
assert.equal(drake.items.length, 6, 'Replacing a launcher at the limit is allowed');
drake.items[0].state = 'Passive';
assert.throws(() => addModule(drake, byName('Heavy Missile Launcher II'), catalog, drakeBare.stats), /launcher hardpoints/, 'Offline weapons still occupy hardpoints');
assert.throws(() => addModule(drake, catalog[3074], catalog, drakeBare.stats), /turret hardpoints/);
assert.throws(() => importEFT('[Drake, Invalid]\n' + 'Heavy Missile Launcher II\n'.repeat(8), catalog), /launcher hardpoints/);
assert.equal(importEFT('[Drake, Valid]\n' + 'Heavy Missile Launcher II\n'.repeat(6), catalog).items.length, 6);
assert.throws(() => importEFT('[Rifter, Invalid mids]\n' + '1MN Afterburner II\n'.repeat(4), catalog), /Too many modules/);
addModule(legion, byName('Legion Offensive - Liquid Crystal Magnifiers'), catalog, legionCore.stats);
const legionArmed = await calculate(legion);
addModule(legion, catalog[3074], catalog, legionArmed.stats);
assert.ok(legion.items.some(item => item.type_id === 3074), 'Subsystem hardpoints must be available');
// Upstream behavior: react/src/components/ShipFit/Slot.tsx uses engine max_state;
// HardwareListing/HardwareListing.tsx filters capital modules; hooks/CleanImportFit.tsx merges stacks.
assert.equal(active.details.find(item => item.type_id === 438).maxState, 'Overload');
const passiveModule = await calculate({ ship_type_id: 587, items: [{ type_id: 2048, flag: 11, quantity: 1, state: 'Overload' }] });
assert.equal(passiveModule.details.find(item => item.type_id === 2048).maxState, 'Online');
assert.equal(passiveModule.details.find(item => item.type_id === 2048).state, 'Online');
assert.ok(!compatible(byName('Capital Armor Repairer I'), byName('Drake')));
assert.ok(compatible(byName('Capital Armor Repairer I'), byName('Revelation')));
const stacked = importEFT('[Vexor, Stacks]\nHobgoblin I x2\nHobgoblin I x3\nAntimatter Charge S x100\nAntimatter Charge S x200', catalog);
assert.equal(stacked.items.length, 2);
assert.equal(stacked.items.find(item => item.flag === 87).quantity, 5);
assert.equal(stacked.items.find(item => item.flag === 5).quantity, 300);
const carrierFit = importTypeIDFit('23913;158:40560:18;158:40560:6;158:40561:9', catalog);
assert.ok(hasFighterBay(carrierFit, catalog));
assert.equal(byName('Thanatos').attributes.fighterTubes, 4);
assert.equal(byName('Nyx').attributes.fighterTubes, 5);
assert.equal(carrierFit.items.find(item => item.type_id === 40560).quantity, 24);
assert.equal(carrierFit.items.reduce((sum, item) => sum + item.active, 0), 4);
validateFighters(carrierFit, catalog);
const carrier = await calculate(carrierFit);
assert.ok(carrier.stats.fighterDamagePerSecond > 0);
assert.equal(carrier.stats.fighterTubesUsed, 4);
assert.equal(carrier.stats.fighterCraftDeployed, 24);
assert.equal(carrier.stats.fighterCraftInBay, 9);
assert.equal(carrier.stats.fighterHeavySlotsUsed, 4);
assert.match(exportEFT(carrierFit, catalog), /Ametat II x24/);
const partialCarrier = { ...carrierFit, fighterTubes: carrierFit.fighterTubes.map(tube => ({ ...tube })) };
partialCarrier.fighterTubes[0].quantity--;
const partialFighters = await calculate(partialCarrier);
assert.equal(partialFighters.stats.fighterCraftDeployed, 23);
assert.ok(partialFighters.stats.fighterDamagePerSecond < carrier.stats.fighterDamagePerSecond);
carrierFit.items.forEach(item => { item.active = 0; });
carrierFit.fighterTubes = [];
validateFighters(carrierFit, catalog);
const inactiveCarrier = await calculate(carrierFit);
assert.equal(inactiveCarrier.stats.fighterDamagePerSecond || 0, 0);
assert.equal(inactiveCarrier.stats.fighterTubesUsed, 0);
const supercarrierFit = importTypeIDFit('23913;158:40556:12;158:40564:29;158:40565:12;158:40565:17', catalog);
validateFighters(supercarrierFit, catalog);
const supercarrier = await calculate(supercarrierFit);
assert.equal(supercarrier.stats.fighterTubesUsed, 5);
assert.equal(supercarrier.stats.fighterCapacityLoad, 75400);
assert.ok(supercarrier.stats.fighterCapacityLoad < supercarrier.stats.fighterCapacity);
validateFighters({ ...supercarrierFit, fighterTubes: [], items: supercarrierFit.items.map(item => ({ ...item, active: 0 })) }, catalog, supercarrier.stats.fighterCapacity);
assert.throws(() => validateFighters({ ...supercarrierFit, fighterTubes: [], items: supercarrierFit.items.map(item => ({ ...item, quantity: 1000, active: 0 })) }, catalog, supercarrier.stats.fighterCapacity), /space in the fighter bay/);
assert.throws(() => importTypeIDFit('587;158:40560:6', catalog), /no fighter bay/);
console.log('Simulator checks passed: catalog, skills, module states and heat, fitting limits, charges, EFT round trips and errors, drones, fighters, and subsystems.');

// Exercise page controls and SPA cleanup without a browser dependency.
class Element extends EventTarget {
    constructor() {
        super(); this.children = []; this.dataset = {}; this.style = {}; this.value = ''; this.id = '';
    }
    append(...children) { this.children.push(...children); }
    add(option) { this.append(option); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(name, value) { this[name] = value; }
    getAttribute(name) { return this[name]; }
    click() { this.dispatchEvent(new Event('click')); }
    querySelectorAll(selector) {
        assert.equal(selector, 'button[aria-expanded]');
        return this.children.flatMap(child => [...(child['aria-expanded'] !== undefined ? [child] : []), ...child.querySelectorAll(selector)]);
    }
    get lastChild() { return this.children.at(-1); }
    querySelector() { if (!this.children.length) this.append(new Element()); return this.children[0]; }
    focus() {}
    select() {}
}
const controls = new Map();
for (const name of ['tab-effects', 'effects-panel', 'effects-stats', 'effects', 'effect-search', 'effect-results', 'space-security', 'space-security-control', 'share', 'share-url', 'share-modal', 'share-copy', 'sort', 'eft-modal', 'eft-modal-text', 'eft-modal-status', 'equipment', 'mode-Defense', 'mode-Propulsion', 'mode-Sharpshooter', 'mode-control', 'status', 'controls', 'ship', 'ships', 'name', 'skills', 'new', 'category', 'search', 'results', 'hull', 'image', 'slots', 'stats', 'warnings', 'trash', 'undo', 'redo', 'wheel', 'Fitting_Panel', 'implant-Fitting_Panel', 'bigship', 'resource-cpu', 'resource-powergrid', 'ship-panel', 'capsule-panel', 'configure-panel', 'ship-stats', 'capsule-stats', 'implants', 'implant-search', 'implant-results', 'booster-search', 'booster-results', 'boosters', 'boosters-panel', 'boosters-stats', 'tab-boosters', 'tab-ship', 'tab-capsule', 'tab-configure', 'damage-em', 'damage-thermal', 'damage-kinetic', 'damage-explosive', 'damage-em-slider', 'damage-thermal-slider', 'damage-kinetic-slider', 'damage-explosive-slider', 'damage-status', 'damage-reset', 'security-status', 'security-status-value', 'security-status-minus10', 'security-status-minus5', 'security-status-zero', 'security-status-plus5', 'security-status-reset', 'security-status-source', 'security-status-character-image', 'security-status-character-name', 'security-status-detail']) {
    const control = new Element();
    control.id = 'simulate-' + name;
    controls.set(name, control);
}
controls.get('category').value = 'High';
controls.get('skills').value = '5';
controls.get('name').value = 'New fit';
for (const [prefix, count] of [['high', 8], ['mid', 8], ['low', 8], ['rig', 3], ['sub', 5]]) {
    for (let i = 1; i <= count; i++) {
        controls.set(prefix + i, new Element());
        if (['high', 'mid', 'low'].includes(prefix)) controls.set(prefix + i + 'l', new Element());
    }
}
const frames = new Map(racks.flatMap(rack => Array.from({ length: 8 }, (_, index) => ['.flag' + (rack.start + index), new Element()])));
controls.get('Fitting_Panel').querySelector = selector => frames.get(selector);
const root = new Element();
root.querySelector = selector => controls.get(selector.replace('#simulate-', ''));
globalThis.document = { getElementById: () => root, createElement: () => new Element() };
globalThis.Option = class extends Element {
    constructor(label, value) { super(); this.textContent = label; this.value = value; }
};
// The worker and page share this test process, so provide the page global here.
globalThis.window = globalThis;
window.location = { hash: '' };
window.characterID = 123456;
const toasts = [];
window.showToast = message => toasts.push(message);
const stored = new Map();
globalThis.localStorage = { getItem: key => stored.get(key) || null, setItem: (key, value) => stored.set(key, value) };
let pending = Promise.resolve();
let workerCount = 0;
let workerPosts = 0;
let terminated = 0;
globalThis.Worker = class {
    constructor(url) {
        assert.equal(url.search, '?test=worker-refresh', 'The worker follows the page module cache key');
        workerCount++;
    }
    terminate() { terminated++; }
    postMessage(request) {
        workerPosts++;
        pending = pending.then(async () => {
            await self.onmessage({ data: request });
            await this.onmessage({ data: result });
        });
    }
};
const settle = async () => { let previous; do { previous = pending; await previous; } while (previous !== pending); };
await import('../public/js/simulate.js?test=worker-refresh');
await settle();
assert.equal(controls.get('controls').disabled, false);
window.zkbInitSimulate();
assert.equal(workerCount, 2, 'Repeated initialization should reuse the ship and capsule workers');
function click(name) {
    const event = new Event('click');
    Object.defineProperty(event, 'target', { value: { id: 'simulate-' + name } });
    root.dispatchEvent(event);
}
function equipmentButtons(element) {
    return (element['aria-label']?.startsWith('Add ') ? [element] : []).concat(element.children.flatMap(equipmentButtons));
}
function treeText(element) {
    return (element.textContent || '') + ' ' + element.children.map(treeText).join(' ');
}
function findElements(element, predicate) {
    return (predicate(element) ? [element] : []).concat(element.children.flatMap(child => findElements(child, predicate)));
}
controls.get('ship').value = 'Confessor';
click('new');
await settle();
assert.equal(controls.get('tab-capsule').textContent, 'Implants (0)', 'The Implants tab shows the fitted implant count');
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, 4.2, 'New fits use the logged-in pilot security status from ESI');
assert.ok(characterLookups > 0, 'The simulator polls the versionless ESI character route when logged in');
assert.equal(controls.get('security-status-character-name').textContent, 'Test Pilot');
assert.equal(controls.get('security-status-detail').textContent, 'Security status: 4.2');
assert.equal(controls.get('security-status-character-image').src, 'https://images.evetech.net/characters/123456/portrait?size=64');
assert.doesNotMatch(controls.get('security-status-source').className, /\bd-none\b/, 'The ESI pilot identity becomes visible after loading');
assert.equal(controls.get('mode-control').hidden, false);
const defenseDisplay = JSON.stringify(controls.get('stats').children);
controls.get('mode-Propulsion').click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).mode, 'Propulsion');
assert.notEqual(JSON.stringify(controls.get('stats').children), defenseDisplay, 'Mode changes update the rendered statistics');
click('undo');
await settle();
assert.equal(controls.get('mode-Defense')['aria-pressed'], 'true');
click('redo');
await settle();
assert.equal(controls.get('mode-Propulsion')['aria-pressed'], 'true');
controls.get('eft-modal-text').value = '[Svipul, Mode speed test]\n1MN Afterburner II';
click('eft-modal-import');
await settle();
const displayedSpeed = () => {
    const rows = controls.get('stats').children[3].children[0].children[0].children[1].children;
    return rows[rows.findIndex(row => row.children[1]?.textContent === 'Speed') + 1].textContent;
};
const svipulDefenseSpeed = displayedSpeed();
controls.get('mode-Propulsion').click();
await settle();
const svipulPropulsionSpeed = displayedSpeed();
assert.notEqual(svipulPropulsionSpeed, svipulDefenseSpeed, 'Svipul displayed top speed changes in Propulsion mode');
controls.get('mode-Sharpshooter').click();
await settle();
assert.equal(displayedSpeed(), svipulDefenseSpeed, 'Svipul top speed returns when leaving Propulsion mode');
console.log('Svipul displayed speed: ' + svipulDefenseSpeed + ' → ' + svipulPropulsionSpeed + ' → ' + displayedSpeed());
for (const shipName of ['Proteus', 'Loki', 'Legion', 'Tengu', 'Dramiel']) {
    controls.get('ship').value = shipName;
    click('new');
    await settle();
    assert.equal(controls.get('mode-control').hidden, true, 'Only tactical destroyers show mode controls');
    const hull = byName(shipName);
    const subsystemCount = shipName === 'Dramiel' ? 0 : 4;
    assert.equal(slotCount(racks[4], hull, { maxSubSystems: 5 }, catalog), subsystemCount, 'Use actual subsystem slots instead of the hull maximum');
    for (let i = 1; i <= 5; i++) assert.equal(controls.get('sub' + i).hidden, i > subsystemCount, shipName + ' wheel slot ' + i);
    for (let i = 0; i < 5; i++) assert.equal(frames.get('.flag' + (125 + i)).style.display, i < subsystemCount ? '' : 'none', shipName + ' SVG subsystem frame ' + i);
    const expected = Object.values(catalog).filter(type => type.categoryID === 32 && type.attributes.fitsToShipType === hull.id).map(type => 'Add ' + type.name).sort();
    for (const category of ['All', 'SubSystem', 'Cargo']) {
        controls.get('search').value = '';
        controls.get('category').value = category;
        controls.get('category').dispatchEvent(new Event('change'));
        const subsystemNames = new Set(Object.values(catalog).filter(type => type.categoryID === 32).map(type => 'Add ' + type.name));
        const actual = equipmentButtons(controls.get('results')).map(button => button['aria-label']).filter(name => subsystemNames.has(name)).sort();
        assert.deepEqual(actual, expected, shipName + ' subsystem browsing must be hull-specific in ' + category);
    }
    controls.get('search').value = 'Loki';
    controls.get('search').dispatchEvent(new Event('input'));
    const lokiSubsystems = equipmentButtons(controls.get('results')).filter(button => button['aria-label'].startsWith('Add Loki '));
    assert.equal(lokiSubsystems.length, shipName === 'Loki' ? expected.length : 0, 'Search must preserve subsystem hull filtering');
}
for (const shipName of ['Tholos', 'Avatar']) {
    controls.get('ship').value = shipName;
    click('new');
    await settle();
    controls.get('search').value = 'Doomsday';
    for (const category of ['All', 'High']) {
        controls.get('category').value = category;
        controls.get('category').dispatchEvent(new Event('change'));
        const names = equipmentButtons(controls.get('results')).map(button => button['aria-label']);
        assert.deepEqual(names, shipName === 'Avatar' ? ["Add 'Judgment' Electromagnetic Doomsday"] : [], shipName + ' only lists hull-compatible doomsdays in ' + category);
    }
    controls.get('category').value = 'Cargo';
    controls.get('category').dispatchEvent(new Event('change'));
    assert.ok(equipmentButtons(controls.get('results')).length > 1, 'Cargo browsing allows carrying hull-incompatible modules');
}
await new Promise(resolve => setTimeout(resolve, 0));
assert.ok(priceFallbacks > 0, 'Missing daily capital prices fall back to the current item price');
controls.get('search').value = '';
controls.get('category').value = 'High';
controls.get('ship').value = 'Rifter';
click('new');
await settle();
assert.equal(controls.get('hull').textContent, 'Rifter');
assert.deepEqual(controls.get('slots').children.map(section => section.children[0].textContent), ['Cargo'], 'Ships without a drone bay hide the Drones section');
assert.match(treeText(controls.get('slots').children[0]), /Cargo.*0 \/ .* m³ used.*No items in cargo/, 'Empty cargo shows capacity in its heading');
assert.equal(controls.get('stats').children.length, 7, controls.get('status').textContent);
assert.match(treeText(controls.get('stats').children[4]), /Cargo.*Cargo capacity/);
assert.doesNotMatch(treeText(controls.get('stats').children[4]), /Drone|Fighter/, 'Ships without drone or fighter bays only mention Cargo');
const compactResource = value => value.toLocaleString(undefined, { maximumFractionDigits: 1 });
assert.equal(controls.get('resource-cpu').textContent, compactResource(result.stats.cpuOutput - result.stats.cpuLoad) + '/' + compactResource(result.stats.cpuOutput), 'CPU shows remaining/total on the fitting wheel');
assert.equal(controls.get('resource-powergrid').textContent, compactResource(result.stats.powerOutput - result.stats.powerLoad) + '/' + compactResource(result.stats.powerOutput), 'Power grid shows remaining/total on the fitting wheel');
const fittingStats = controls.get('stats').children[5].children[0].children[0].children[1];
assert.equal(fittingStats.children[0].children[0].src, '/img/simulate/icon-rigslot.png', 'Remaining fitting stats keep their EVE icons');
const capacitorBody = controls.get('stats').children[0].children[0].children[0];
assert.equal(capacitorBody.children[0].children[0].children[1].children[0].textContent, 'Stable', 'Capacitor stability is summarized in the header');
assert.equal(capacitorBody.children[1].children[0].children[0].src, '/img/simulate/capacitor.png', 'Compact capacitor summary uses the EVE icon');
assert.match(capacitorBody.children[1].children[0].children[1].children[1].textContent, /^Δ .+ GJ\/s \(.+%\)$/);
const offenseBody = controls.get('stats').children[1].children[0].children[0];
assert.match(offenseBody.children[0].children[0].children[1].children[0].textContent, /^(?:[\d,.]+|—) DPS$/, 'Total DPS is summarized in the Offense header');
const defenseStats = controls.get('stats').children[2].children[0].children[0].children[1];
const resistanceGrid = defenseStats.children[0];
assert.equal(resistanceGrid.children[0].textContent, 'HP', 'HP is the first defense column heading');
assert.match(resistanceGrid.children[0].className, /text-start/, 'HP heading is left-aligned over the HP values');
assert.equal(resistanceGrid.children[1].children[0].src, '/img/simulate/electromagnetic-resistance-32x32.png', 'Damage types use resistance icons');
assert.equal(resistanceGrid.children[5].children[0].src, '/img/simulate/shield-resistance-32x32.png', 'Resistances are grouped by defense layer');
assert.doesNotMatch(resistanceGrid.children[5].children[1].textContent, /HP$/, 'Layer values do not repeat the HP unit');
assert.match(resistanceGrid.children[6].textContent, /^\d+\.\d%$/);
assert.match(resistanceGrid.children[6].className, /simulate-resistance-value/);
assert.match(resistanceGrid.children[20].children[1].textContent, /^Shield recharge \d+\.\d+ EHP\/s · \d+\.\d+ s$/, 'Shield recharge rate and time share their own line below resistances');
const statsToggles = controls.get('stats').querySelectorAll('button[aria-expanded]');
for (const expanded of ['true', 'false']) {
    statsToggles[1].click();
    statsToggles[1].click();
    statsToggles[1].children[1].lastChild.dispatchEvent(new Event('dblclick'));
    assert.ok(statsToggles.every(toggle => toggle['aria-expanded'] === expanded), 'Double-clicking a section chevron toggles all sections');
}
assert.equal(controls.get('high1').children.length, 1, 'Available wheel slots are interactive');
assert.equal(controls.get('high4').hidden, true, 'Unavailable slots are hidden');
assert.equal(controls.get('bigship').children[0].alt, 'Rifter');
assert.equal(frames.get('.flag27').style.display, '', 'Available SVG slots are shown');
assert.equal(frames.get('.flag30').style.display, 'none', 'Unavailable SVG slots are hidden');
controls.get('high2').children[0].dispatchEvent(new Event('click'));
assert.equal(controls.get('high2').children[0]['aria-pressed'], 'true');
controls.get('search').value = '150mm Railgun II';
controls.get('search').dispatchEvent(new Event('input'));
const add = controls.get('results').children.find(row => row.lastChild?.['aria-label']?.startsWith('Add ')).lastChild;
add.dispatchEvent(new Event('click'));
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.some(item => item.type_id === 3074 && item.flag === 28), 'Equipment goes into the selected wheel slot');
assert.match(controls.get('high2').children[0].children[0].src, /3074\/icon/);
const drop = new Event('drop');
Object.defineProperty(drop, 'dataTransfer', { value: { getData: key => key === 'application/x-zkb-module' ? '3074' : '' } });
controls.get('high1').children[0].dispatchEvent(drop);
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.some(item => item.type_id === 3074 && item.flag === 27), 'Dropping equipment fits the target slot');
const invalidDrop = new Event('drop');
Object.defineProperty(invalidDrop, 'dataTransfer', { value: { getData: key => key === 'application/x-zkb-module' ? '438' : '' } });
const beforeDrop = stored.get('zkb:simulate');
controls.get('high1').children[0].dispatchEvent(invalidDrop);
assert.equal(stored.get('zkb:simulate'), beforeDrop, 'Dropping into an incompatible slot preserves the fit');
controls.get('eft-modal-text').value = '[Vexor, Imported drone fit]\nHobgoblin I x5';
click('eft-modal-import');
await settle();
assert.equal(controls.get('hull').textContent, 'Vexor');
click('eft-open');
assert.match(controls.get('eft-modal-text').value, /Hobgoblin I x5/);
const beforeError = stored.get('zkb:simulate');
controls.get('eft-modal-text').value = '[Vexor, Invalid]\nUnknown Module';
click('eft-modal-import');
assert.equal(stored.get('zkb:simulate'), beforeError, 'Failed import must preserve the existing fit');
window.zkbPageCleanup();
assert.equal(terminated, 2);
window.zkbInitSimulate();
await settle();
assert.equal(workerCount, 4);
assert.equal(controls.get('hull').textContent, 'Vexor', 'Returning to the page restores the saved fit');
window.zkbPageCleanup();
console.log('Simulator page checks passed: initialization, ship choice, equipment search and fitting, stats rendering, atomic imports, export, persistence, and SPA cleanup.');

window.location.hash = '#eft=' + encodeURIComponent('[Rifter, Linked fit]\n150mm Railgun II, Antimatter Charge S');
window.zkbInitSimulate();
await settle();
assert.equal(controls.get('hull').textContent, 'Rifter', 'Linked EFT takes precedence over the saved fit');
assert.equal(controls.get('name').value, 'Linked fit');
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.some(item => item.type_id === 222 && item.flag === 27));
window.zkbPageCleanup();
const savedBeforeBadLink = stored.get('zkb:simulate');
window.location.hash = '#eft=' + encodeURIComponent('[Rifter, Bad link]\nUnknown module');
window.zkbInitSimulate();
await settle();
assert.match(controls.get('status').textContent, /Unable to load this fit/);
assert.equal(stored.get('zkb:simulate'), savedBeforeBadLink);
window.zkbPageCleanup();
console.log('Linked fit checks passed: automatic import, charges, precedence, and invalid-link preservation.');

window.location.hash = '#fit=587%3B28%3A3074%3B28%3A230';
window.zkbInitSimulate();
await settle();
const linkedIDFit = JSON.parse(stored.get('zkb:simulate'));
assert.equal(linkedIDFit.ship_type_id, 587);
assert.ok(linkedIDFit.items.some(item => item.type_id === 3074 && item.flag === 28), 'Type ID links preserve the module slot');
assert.ok(linkedIDFit.items.some(item => item.type_id === 230 && item.flag === 28), 'Type ID links preserve the charge slot');
window.zkbPageCleanup();
console.log('Type ID link checks passed: ship, module, charge, and exact slots.');

window.location.hash = '#fit=587&capsule=138883655';
window.zkbInitSimulate();
await new Promise(resolve => setTimeout(resolve, 0));
await settle();
const linkedCapsuleFit = JSON.parse(stored.get('zkb:simulate'));
assert.deepEqual(linkedCapsuleFit.implants, [{ type_id: 3084, slot: 7 }], 'Related capsule implants load through the killmail API');
assert.equal(controls.get('tab-capsule').textContent, 'Implants (1)', 'The Implants tab count updates when implants load');
window.zkbPageCleanup();
console.log('Related capsule API check passed.');

window.location.hash = '#fit=23913%3B158%3A40560%3A18%3B158%3A40560%3A6%3B158%3A40561%3A9';
window.zkbInitSimulate();
await settle();
const linkedCarrier = JSON.parse(stored.get('zkb:simulate'));
assert.equal(linkedCarrier.items.length, 2, 'Linked fighter stacks of one type are merged');
assert.match(treeText(controls.get('stats').children[1]), /Fighter DPS \(24 deployed\)/);
assert.match(treeText(controls.get('stats').children[4]), /Fighter bay \(9 stored\).*Fighter tubes.*Heavy fighter tubes/);
assert.match(treeText(controls.get('stats').children[4]), /Fighters & Cargo/);
assert.doesNotMatch(treeText(controls.get('stats').children[4]), /Drone/, 'A fighter-only ship does not mention drones');
assert.match(treeText(controls.get('slots')), /Fighters.*Ametat II.*Malleus II/);
const fighterBayHeading = findElements(controls.get('slots'), element => element.textContent === 'Fighter bay')[0];
assert.equal(fighterBayHeading.className, 'h6 mx-0 border-bottom pb-2', 'Fighter Bay uses the Cargo title styling');
let fighterTubeSelectors = findElements(controls.get('slots'), element => /^Fighter tube \d/.test(element['aria-label'] || ''));
assert.equal(fighterTubeSelectors.length, 5);
assert.match(treeText(controls.get('slots')), /24 deployed fighters.*included in Fighter DPS.*9 fighters stored in bay.*Heavy 4 \/ 4.*6.*Ametat II.*Malleus II.*Open.*18 deployed · 6 in bay · max 68 in empty bay/);
assert.doesNotMatch(treeText(controls.get('slots')), /Simulated|fighters in DPS/);
assert.match(treeText(controls.get('slots')), /Open.*Empty/);
const fighterDials = findElements(controls.get('slots'), element => element.title?.includes('fighters deployed in tube'));
assert.equal(fighterDials.length, 4);
assert.match(fighterDials[0].style.background, /conic-gradient/);
assert.equal(findElements(controls.get('slots'), element => element.style.gridTemplateColumns === '4rem 32px minmax(0, 1fr) auto').length, 2, 'Each fighter type uses one compact inventory row');
findElements(controls.get('slots'), element => element['aria-label']?.startsWith('Remove Ametat II'))[0].click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).fighterTubes[0].quantity, 5);
assert.equal(result.stats.fighterCraftDeployed, 23);
findElements(controls.get('slots'), element => element['aria-label']?.startsWith('Add Ametat II'))[0].click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).fighterTubes[0].quantity, 6);
assert.equal(result.stats.fighterCraftDeployed, 24);
fighterTubeSelectors = findElements(controls.get('slots'), element => /^Fighter tube \d/.test(element['aria-label'] || ''));
assert.ok(findElements(controls.get('slots'), element => element['aria-label']?.endsWith(' quantity')).every(input => Number(input.max) < 1000), 'Fighter quantities use available bay capacity');
const postsBeforeRapidFighterChanges = workerPosts;
for (let index = 0; index < 20; index++) {
    fighterTubeSelectors[0].value = String(index % 2 ? 40560 : 0);
    fighterTubeSelectors[0].dispatchEvent(new Event('change'));
}
for (const selector of fighterTubeSelectors) {
    selector.value = '0';
    selector.dispatchEvent(new Event('change'));
}
await settle();
assert.ok(workerPosts - postsBeforeRapidFighterChanges <= 2, 'Rapid fighter changes do not recalculate the independent capsule');
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.every(item => item.flag !== 158 || item.active === 0));
assert.equal(result.stats.fighterDamagePerSecond || 0, 0);
window.zkbPageCleanup();
console.log('Fighter link checks passed: import, Offense, fighter statistics, bay inventory, and deployment controls.');

window.location.hash = '#eft=' + encodeURIComponent('[Rifter, Trash test]\n150mm Railgun II, Antimatter Charge S');
window.zkbInitSimulate();
await settle();
const stableModuleImage = controls.get('high1').children[0].children[0];
const stableShipImage = controls.get('bigship').children[0];
for (const state of ['Overload', 'Passive', 'Active']) {
    controls.get('high1').children[0].dispatchEvent(new Event('click'));
    assert.equal(JSON.parse(stored.get('zkb:simulate')).items.find(item => item.type_id === 3074).state, state, 'Single click cycles module state');
    assert.equal(controls.get('status').textContent, '', 'Module toggles do not insert a status line above the equipment panel');
    await settle();
    assert.equal(controls.get('high1').children[0].children[0], stableModuleImage, 'State changes reuse the module image');
    assert.equal(controls.get('bigship').children[0], stableShipImage, 'State changes reuse the ship image');
    const classes = controls.get('high1').children[0].className;
    assert.ok(!classes.includes('btn-primary'));
    assert.ok(classes.includes('border-0'), 'Module buttons have no square border');
    assert.equal(frames.get('.flag27').querySelector('.fitted path').style.stroke, { Overload: 'var(--bs-danger)', Passive: '#808080', Active: 'var(--bs-success)' }[state], 'State colors follow the SVG slot outline');
}
const dragStart = new Event('dragstart');
const removalTransfer = new Map();
let dragPreviewUses = 0;
Object.defineProperty(dragStart, 'dataTransfer', { value: {
    setData: (key, value) => removalTransfer.set(key, value),
    setDragImage: () => dragPreviewUses++
} });
const trashDrop = new Event('drop');
Object.defineProperty(trashDrop, 'dataTransfer', { value: { getData: key => removalTransfer.get(key) || '' } });
controls.get('high1').children[0].children[0].dispatchEvent(dragStart);
assert.equal(dragPreviewUses, 1, 'Fitted items use a small dedicated drag preview');
const fitBeforeBrowse = stored.get('zkb:simulate');
controls.get('equipment').dispatchEvent(trashDrop);
assert.equal(stored.get('zkb:simulate'), fitBeforeBrowse, 'Browsing a dragged module preserves the fit');
assert.deepEqual(equipmentButtons(controls.get('results')).map(button => button['aria-label']).sort(),
    Object.values(catalog).filter(type => type.id === 3074 || compatibleCharge(catalog[3074], type)).map(type => 'Add ' + type.name).sort(),
    'Dropped module browsing includes only that module and its compatible charges');
controls.get('search').value = 'Shield';
controls.get('search').dispatchEvent(new Event('input'));
assert.ok(equipmentButtons(controls.get('results')).every(button => button['aria-label'].includes('Shield')), 'Typing replaces the dropped module filter');

controls.get('trash').dispatchEvent(trashDrop);
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.length, 0, 'Dropping a module on trash also removes its charge');
assert.equal(controls.get('high1').children[0].textContent, '+');
assert.equal(frames.get('.flag27').querySelector('.fitted path').style.stroke, '#808080', 'Removing a module clears its slot color');
window.zkbPageCleanup();
console.log('Fitting trash check passed.');

window.location.hash = '#eft=' + encodeURIComponent('[Rifter, History]\n150mm Railgun II, Antimatter Charge S');
window.zkbInitSimulate();
await settle();
assert.equal(controls.get('undo').disabled, true);
assert.equal(controls.get('redo').disabled, true);
const originalFit = stored.get('zkb:simulate');
controls.get('high1').children[0].dispatchEvent(new Event('click'));
await settle();
const heatedFit = stored.get('zkb:simulate');
click('undo');
await settle();
assert.equal(stored.get('zkb:simulate'), originalFit, 'Undo restores the fit and charge before the state change');
click('redo');
await settle();
assert.equal(stored.get('zkb:simulate'), heatedFit);
controls.get('high1').children[0].children[0].dispatchEvent(dragStart);
controls.get('trash').dispatchEvent(trashDrop);
await settle();
click('undo');
await settle();
assert.equal(stored.get('zkb:simulate'), heatedFit, 'Undo restores the removed module, charge, and heat state');
controls.get('name').value = 'Branched history';
controls.get('name').dispatchEvent(new Event('change'));
await settle();
assert.equal(controls.get('redo').disabled, true, 'An edit after undo discards redo history');
for (let i = 1; i <= 35; i++) {
    controls.get('name').value = 'Edit ' + i;
    controls.get('name').dispatchEvent(new Event('change'));
    await settle();
}
for (let i = 0; i < 30; i++) { click('undo'); await settle(); }
assert.equal(controls.get('name').value, 'Edit 5');
assert.equal(controls.get('undo').disabled, true, 'Retain at most 30 undo steps');
click('undo');
assert.equal(controls.get('name').value, 'Edit 5');
for (let i = 0; i < 30; i++) { click('redo'); await settle(); }
assert.equal(controls.get('name').value, 'Edit 35');
assert.equal(controls.get('redo').disabled, true);
window.zkbPageCleanup();
console.log('Undo/redo checks passed: state changes, removal, branching, and the 30-step limit.');

assert.throws(() => importEFT('[Rifter, No bay]\nHobgoblin I x1', catalog), /no drone bay/);
const proteus = { ship_type_id: byName('Proteus').id, items: [] };
assert.equal(hasDroneBay(proteus, catalog), false);
proteus.items.push({ type_id: byName('Proteus Offensive - Drone Synthesis Projector').id, flag: 127, quantity: 1 });
assert.equal(hasDroneBay(proteus, catalog), true);
window.location.hash = '#eft=' + encodeURIComponent('[Rifter, No bay]');
window.zkbInitSimulate();
await settle();
controls.get('search').value = 'Hobgoblin I';
controls.get('search').dispatchEvent(new Event('input'));
const droneAdd = equipmentButtons(controls.get('results')).find(button => button['aria-label'] === 'Add Hobgoblin I');
assert.equal(droneAdd.disabled, true);
const noBayFit = stored.get('zkb:simulate');
droneAdd.dispatchEvent(new Event('click'));
assert.equal(stored.get('zkb:simulate'), noBayFit, 'The mutation guard must reject drones even if the button handler is invoked');
assert.match(controls.get('status').textContent, /no drone bay/);
window.zkbPageCleanup();
console.log('No-drone-bay checks passed: imports, subsystem capacity, disabled controls, and mutation guard.');

assert.throws(() => importEFT('[Tristan, Overfull]\nHobgoblin I x9', catalog), /space in the drone bay/);
const fullBay = importEFT('[Tristan, Full bay]\nHobgoblin I x8', catalog);
validateDrones(fullBay, catalog);
fullBay.items[0].active = 6;
assert.throws(() => validateDrones(fullBay, catalog), /Too many deployed/);
const bandwidthFit = importEFT('[Tristan, Bandwidth]\nOgre I x1\nHobgoblin I x1', catalog);
bandwidthFit.items.forEach(item => { item.active = 1; });
assert.throws(() => validateDrones(bandwidthFit, catalog), /bandwidth/);
window.location.hash = '#eft=' + encodeURIComponent('[Tristan, Full bay]\nHobgoblin I x8');
window.zkbInitSimulate();
await settle();
controls.get('search').value = 'Hobgoblin I';
controls.get('search').dispatchEvent(new Event('input'));
const fullBayBefore = stored.get('zkb:simulate');
equipmentButtons(controls.get('results')).find(button => button['aria-label'] === 'Add Hobgoblin I').dispatchEvent(new Event('click'));
assert.equal(stored.get('zkb:simulate'), fullBayBefore);
assert.match(controls.get('status').textContent, /space in the drone bay/);
window.zkbPageCleanup();
console.log('Drone capacity checks passed: full bay, overfill, imports, deployment count, and bandwidth.');

window.location.hash = '#eft=' + encodeURIComponent(await readFile(new URL('./fixtures/simulate-131882701.eft', import.meta.url), 'utf8'));
window.zkbInitSimulate();
await settle();
assert.equal(controls.get('hull').textContent, 'Tholos');
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.length, 25);
assert.equal(controls.get('stats').children.length, 7);
assert.equal(controls.get('status').textContent, '', 'Successful simulations clear the status message');
window.zkbPageCleanup();
for (const template of ['detail.pug', 'fits_detail.pug']) {
    const source = await readFile(new URL('../templates/' + template, import.meta.url), 'utf8');
    assert.match(source, /href=\('\/simulate\/#fit='[^\n]+data-spa="off"\) Simulate/);
}
const idFit = importTypeIDFit('593;28:3074;28:230;87:2454:2;5:999999999:20', catalog);
assert.equal(idFit.ship_type_id, 593);
assert.equal(idFit.name, 'Tristan loss');
assert.deepEqual(idFit.items.map(item => [item.flag, item.type_id, item.quantity]), [[28, 3074, 1], [28, 230, 1], [87, 2454, 2]]);
assert.equal(importTypeIDFit('593:Jane%20Doe%27s%20Rifter', catalog).name, "Jane Doe's Rifter");
await calculate(idFit);
assert.throws(() => importTypeIDFit('587;28:999999999', catalog), /Unknown item type ID/);
console.log('Reported Tholos killmail loads and calculates; Simulate links preserve slot and type IDs.');

window.location.hash = '#eft=' + encodeURIComponent('[Rifter, Charge drop]\n150mm Railgun II');
window.zkbInitSimulate();
await settle();
controls.get('search').value = 'Antimatter Charge S';
controls.get('search').dispatchEvent(new Event('input'));
const chargeRow = controls.get('results').children.find(row => row.lastChild?.['aria-label'] === 'Add Antimatter Charge S');
assert.equal(chargeRow.draggable, true);
const transfer = new Map();
const chargeDrag = new Event('dragstart');
Object.defineProperty(chargeDrag, 'dataTransfer', { value: { setData: (key, value) => transfer.set(key, value) } });
chargeRow.dispatchEvent(chargeDrag);
const chargeDrop = new Event('drop');
Object.defineProperty(chargeDrop, 'dataTransfer', { value: { getData: key => transfer.get(key) || '' } });
controls.get('high1').children[0].dispatchEvent(chargeDrop);
await settle();
const chargedFit = stored.get('zkb:simulate');
assert.ok(JSON.parse(chargedFit).items.some(item => item.type_id === 222 && item.flag === 27));
assert.match(controls.get('high1l').children[0].src, /222\/icon/);
controls.get('high2').children[0].dispatchEvent(chargeDrop);
assert.equal(stored.get('zkb:simulate'), chargedFit, 'An empty slot must reject a charge');
transfer.set('application/x-zkb-module', String(byName('Antimatter Charge M').id));
controls.get('high1').children[0].dispatchEvent(chargeDrop);
assert.equal(stored.get('zkb:simulate'), chargedFit, 'An incompatible charge must preserve the loaded charge');
click('undo');
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.length, 1, 'Charge drops are undoable');
click('redo');
await settle();
controls.get('high1l').children[0].dispatchEvent(dragStart);
const trashOver = new Event('dragover', { cancelable: true });
Object.defineProperty(trashOver, 'dataTransfer', { value: { get types() { throw new Error('dragover must not inspect DataTransfer types'); } } });
controls.get('trash').dispatchEvent(trashOver);
assert.ok(trashOver.defaultPrevented, 'Trash accepts fitted-item drags');
controls.get('trash').dispatchEvent(trashDrop);
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.length, 1, 'Trashing a charge preserves its module');
assert.equal(controls.get('high1l').children.length, 0);
click('undo');
await settle();
assert.equal(stored.get('zkb:simulate'), chargedFit, 'Undo restores a trashed charge');

controls.get('eft-modal-text').value = '[Tristan, Bay drops]';
click('eft-modal-import');
await settle();
assert.match(treeText(controls.get('stats').children[4]), /Drones & Cargo/);
assert.doesNotMatch(treeText(controls.get('stats').children[4]), /Fighter/, 'A drone-only ship does not mention fighters');
const bayDrop = new Event('drop');
let bayType = byName('Hobgoblin I').id;
Object.defineProperty(bayDrop, 'dataTransfer', { value: { getData: key => key === 'application/x-zkb-module' ? String(bayType) : '' } });
for (let i = 0; i < 8; i++) {
    controls.get('slots').children[0].dispatchEvent(bayDrop);
    await settle();
}
const fullDroppedBay = stored.get('zkb:simulate');
assert.equal(JSON.parse(fullDroppedBay).items[0].quantity, 8);
controls.get('slots').children[0].dispatchEvent(bayDrop);
assert.equal(stored.get('zkb:simulate'), fullDroppedBay, 'Drone drop rejects a full bay');
assert.match(toasts.at(-1), /drone bay/i, 'Rejected drops use the shared toast');
bayType = 222;
controls.get('slots').children[0].dispatchEvent(bayDrop);
assert.equal(stored.get('zkb:simulate'), fullDroppedBay, 'Drone bay rejects charges');
controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo').dispatchEvent(bayDrop);
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.some(item => item.flag === 5 && item.type_id === 222), 'Charge drops into cargo');
click('undo');
await settle();
assert.equal(stored.get('zkb:simulate'), fullDroppedBay, 'Bay drops are undoable');
const cargoDropSection = controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo');
bayType = 3074;
cargoDropSection.dispatchEvent(bayDrop);
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.some(item => item.flag === 5 && item.type_id === 3074), 'Equipment modules drop into cargo');
click('undo');
await settle();
controls.get('slots').children.find(section => section.children[0].textContent === 'Drones').children[1].dispatchEvent(dragStart);
cargoDropSection.dispatchEvent(trashDrop);
await settle();
assert.ok(!JSON.parse(stored.get('zkb:simulate')).items.some(item => item.flag === 87), 'Dragging a drone stack to cargo removes it from the drone bay');
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.find(item => item.flag === 5 && item.type_id === byName('Hobgoblin I').id).quantity, 8, 'The complete drone stack moves into cargo');
click('undo');
await settle();
controls.get('eft-modal-text').value = '[Rifter, Move fitted module]\n150mm Railgun II, Antimatter Charge S';
click('eft-modal-import');
await settle();
controls.get('high1').children[0].children[0].dispatchEvent(dragStart);
controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo').dispatchEvent(trashDrop);
await settle();
const movedModuleFit = JSON.parse(stored.get('zkb:simulate'));
assert.ok(movedModuleFit.items.some(item => item.flag === 5 && item.type_id === 3074), 'A fitted module moves into cargo');
assert.ok(movedModuleFit.items.some(item => item.flag === 5 && item.type_id === 222), 'Its loaded charge moves into cargo with it');
assert.ok(!movedModuleFit.items.some(item => item.flag === 27), 'Moving a fitted module empties its slot');
let reverseCargoSection = controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo');
assert.match(treeText(reverseCargoSection), /Cargo.*m³ used.*m³ total.*m³ each/, 'Cargo shows heading capacity and compact inventory rows with volume details');
assert.doesNotMatch(treeText(reverseCargoSection), /Cargo inventory/i, 'Cargo does not repeat an inventory subheading');
assert.equal(findElements(reverseCargoSection, element => element.style.gridTemplateColumns === '4rem 32px minmax(0, 1fr) auto').length, 2, 'Each cargo item type uses one compact inventory row');
reverseCargoSection.children.find(row => treeText(row).includes('150mm Railgun II')).dispatchEvent(dragStart);
controls.get('high1').children[0].dispatchEvent(trashDrop);
await settle();
let reversedFit = JSON.parse(stored.get('zkb:simulate'));
assert.ok(reversedFit.items.some(item => item.flag === 27 && item.type_id === 3074), 'A module in cargo can be dragged back into a compatible slot');
assert.ok(!reversedFit.items.some(item => item.flag === 5 && item.type_id === 3074), 'Fitting a cargo module decrements its cargo stack');
reverseCargoSection = controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo');
reverseCargoSection.children.find(row => treeText(row).includes('Antimatter Charge S')).dispatchEvent(dragStart);
controls.get('high1').children[0].dispatchEvent(trashDrop);
await settle();
reversedFit = JSON.parse(stored.get('zkb:simulate'));
assert.ok(reversedFit.items.some(item => item.flag === 27 && item.type_id === 222), 'A charge in cargo can be dragged back into a compatible module');
assert.ok(!reversedFit.items.some(item => item.flag === 5 && item.type_id === 222), 'Loading a cargo charge decrements its cargo stack');
controls.get('eft-modal-text').value = '[Rifter, Full cargo]\n\nAntimatter Charge S x100000';
click('eft-modal-import');
await settle();
const fullCargoFit = stored.get('zkb:simulate');
bayType = 3074;
controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo').dispatchEvent(bayDrop);
assert.equal(stored.get('zkb:simulate'), fullCargoFit, 'Cargo rejects an item when its volume does not fit');
assert.match(toasts.at(-1), /cargo space/i);
console.log('Bay drop checks passed: bay stacking, cargo moves for charges, modules and drones, capacity, and undo.');
controls.get('eft-modal-text').value = '[Rifter, Charge suggestions]';
click('eft-modal-import');
await settle();
controls.get('search').value = '150mm Railgun II';
controls.get('search').dispatchEvent(new Event('input'));
const suggestedModule = controls.get('results').children.find(row => row.lastChild?.['aria-label'] === 'Add 150mm Railgun II');
suggestedModule.lastChild.click();
await settle();
const suggestions = suggestedModule.lastChild.children.filter(row => row.draggable);
const chargeHeaders = suggestedModule.lastChild.children.filter(row => !row.draggable).map(row => row.textContent);
assert.ok(chargeHeaders.some(label => label.startsWith('Tech I · Meta ')));
assert.ok(chargeHeaders.some(label => label.startsWith('Faction · Meta ')));
assert.ok(chargeHeaders.some(label => label.startsWith('Tech II · Meta ')));
const chargeLevels = suggestions.map(row => byName(row.children[0].textContent.replace(/\u00a0/g, ' ')).attributes.metaLevelOld || 0);
assert.deepEqual(chargeLevels, [...chargeLevels].sort((a, b) => a - b), 'Charges are ordered by ascending meta level');

assert.deepEqual(suggestions.map(row => row.children[0].textContent.replace(/\u00a0/g, ' ')).sort(), Object.values(catalog).filter(type => compatibleCharge(catalog[3074], type)).map(type => type.name).sort());
const suggestedCharge = suggestions.find(row => row.lastChild['aria-label'] === 'Load Antimatter Charge S');
suggestedCharge.dispatchEvent(dragStart);
assert.equal(removalTransfer.get('application/x-zkb-module'), '222', 'Suggested charge drags use the charge type');
suggestedCharge.lastChild.click();
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.some(item => item.type_id === 222 && item.flag === 27), 'Suggested charge loads into the added module');
controls.get('eft-modal-text').value = 'Unimported EFT draft';
const reloadToggles = controls.get('stats').querySelectorAll('button[aria-expanded]');
reloadToggles[2].click();
const expectedExpanded = reloadToggles.map(toggle => toggle['aria-expanded']);
const exactSavedFit = stored.get('zkb:simulate');
window.zkbPageCleanup();
window.location.hash = '';
controls.get('search').value = '';
controls.get('eft-modal-text').value = '';
window.zkbInitSimulate();
await settle();
assert.equal(stored.get('zkb:simulate'), exactSavedFit, 'Reload preserves the exact fit');
assert.equal(controls.get('search').value, '150mm Railgun II');
assert.equal(controls.get('eft-modal-text').value, 'Unimported EFT draft');
assert.deepEqual(controls.get('stats').querySelectorAll('button[aria-expanded]').map(toggle => toggle['aria-expanded']), expectedExpanded);
const restoredModuleRow = controls.get('results').children.find(row => row.children[0]?.textContent === '150mm Railgun II');
assert.ok(restoredModuleRow.lastChild.children.some(row => row.lastChild?.['aria-label'] === 'Load Antimatter Charge S'), 'Reload restores charge suggestions');
click('undo');
await settle();
assert.ok(!JSON.parse(stored.get('zkb:simulate')).items.some(item => item.type_id === 222), 'Undo history survives reload');
click('redo');
await settle();
assert.equal(stored.get('zkb:simulate'), exactSavedFit);
console.log('Reload checks passed: exact fit, search, charge suggestions, EFT draft, expanded sections, and undo/redo.');
controls.get('search').value = 'Iridium Charge S';
controls.get('search').dispatchEvent(new Event('input'));
equipmentButtons(controls.get('results')).find(button => button['aria-label'] === 'Add Iridium Charge S').click();
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.some(item => item.type_id === byName('Iridium Charge S').id && item.flag === 27), 'Charge Add loads a compatible module');
controls.get('category').value = 'Cargo';
controls.get('category').dispatchEvent(new Event('change'));
equipmentButtons(controls.get('results')).find(button => button['aria-label'] === 'Add Iridium Charge S').click();
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).items.some(item => item.type_id === byName('Iridium Charge S').id && item.flag === 5), 'Explicit Cargo adds charges to the hold');
const beforeCargoMove = stored.get('zkb:simulate');
controls.get('high1l').children[0].dispatchEvent(dragStart);
controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo').dispatchEvent(trashDrop);
await settle();
const afterCargoMove = JSON.parse(stored.get('zkb:simulate'));
assert.equal(afterCargoMove.items.find(item => item.type_id === byName('Iridium Charge S').id && item.flag === 5).quantity, Math.floor(catalog[3074].capacity / byName('Iridium Charge S').volume), 'Wheel charge provides a full reload in cargo');
assert.equal(controls.get('high1l').children.length, 1, 'Copying a charge to cargo keeps the module loaded');
assert.ok(afterCargoMove.items.some(item => item.type_id === 3074 && item.flag === 27), 'Moving a charge preserves the module');
click('undo');
await settle();
assert.equal(stored.get('zkb:simulate'), beforeCargoMove, 'Charge-to-cargo move is one undo step');
controls.get('eft-modal-text').value = '[Rifter, Reload reserve]\n150mm Railgun II, Antimatter Charge S\n150mm Railgun II, Antimatter Charge S';
click('eft-modal-import');
await settle();
controls.get('high1l').children[0].dispatchEvent(dragStart);
controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo').dispatchEvent(trashDrop);
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.find(item => item.flag === 5).quantity, 2 * Math.floor(catalog[3074].capacity / catalog[222].volume), 'Cargo reserves a full reload for all modules using the charge');
let savedToESI;
window.saveFitting = (id, exported) => { assert.equal(id, null); savedToESI = exported; };
controls.get('name').value = 'My edited reload fit';
click('save');
assert.equal(savedToESI.name, 'My edited reload fit');
assert.equal(savedToESI.ship_type_id, byName('Rifter').id);
assert.ok(savedToESI.items.some(item => item.flag === 'HiSlot0' && item.type_id === 3074));
assert.ok(savedToESI.items.some(item => item.flag === 'HiSlot1' && item.type_id === 3074));
assert.ok(savedToESI.items.some(item => item.flag === 'Cargo' && item.type_id === 222));
assert.ok(savedToESI.items.every(item => !('state' in item)), 'ESI payload contains fitting items rather than simulator states');
let eftModalHidden = false;
window.bootstrap = { Modal: { getInstance: modal => { assert.equal(modal, controls.get('eft-modal')); return { hide: () => { eftModalHidden = true; } }; } } };
click('eft-open');
assert.ok(controls.get('eft-modal-text').value.startsWith('[Rifter, My edited reload fit]'), 'EFT modal exports current fit and name');
const beforeModalError = stored.get('zkb:simulate');
controls.get('eft-modal-text').value = 'invalid fit';
click('eft-modal-import');
assert.equal(stored.get('zkb:simulate'), beforeModalError, 'Invalid modal import preserves fit');
assert.ok(controls.get('eft-modal-status').textContent);
assert.equal(eftModalHidden, false, 'Invalid import keeps the modal open');
controls.get('eft-modal-text').value = '[Tristan, Modal import]\nHobgoblin I x2';
click('eft-modal-import');
await settle();
assert.equal(controls.get('hull').textContent, 'Tristan');
assert.equal(controls.get('eft-modal-status').textContent, 'Fit imported.');
assert.equal(eftModalHidden, true, 'Successful import closes the modal to reveal the fit');
controls.get('eft-modal-text').value = '[Rifter, Charge drop targets]\n150mm Railgun II, Antimatter Charge S\n150mm Railgun II';
click('eft-modal-import');
await settle();
const flag28Stroke = frames.get('.flag28').querySelector('.fitted path').style.stroke;
controls.get('high1l').children[0].dispatchEvent(dragStart);
assert.equal(frames.get('.flag28').querySelector('.fitted path').style.stroke, '#0dcaf0', 'Compatible module outlines highlight while dragging');
assert.equal(controls.get('trash').style.outline, '', 'Trash does not use a drag border');
assert.match(controls.get('trash').className, /\btext-danger\b/, 'Trash turns red for a loaded charge drag');
assert.doesNotMatch(controls.get('trash').className, /\btext-white\b/, 'The white utility cannot override the red drag state');
assert.notEqual(frames.get('.flag29').querySelector('.fitted path').style.stroke, '#0dcaf0', 'Empty slots are not highlighted for charges');
frames.get('.flag28').ondrop(trashDrop);
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.filter(item => item.type_id === 222).length, 2, 'Dropping on a slot frame loads the destination and preserves the source charge');
root.dispatchEvent(new Event('dragend'));
assert.equal(frames.get('.flag28').querySelector('.fitted path').style.stroke, flag28Stroke, 'Drag highlights clear after drag ends');
assert.equal(controls.get('trash').style.outline, '', 'Trash highlight clears after drag ends');
assert.match(controls.get('trash').className, /\btext-white\b/, 'Trash returns to its normal color after drag ends');
assert.doesNotMatch(controls.get('trash').className, /\btext-danger\b/, 'Trash clears its red drag state after drag ends');
controls.get('high1').children[0].children[0].dispatchEvent(dragStart);
assert.notEqual(frames.get('.flag27').querySelector('.fitted path').style.stroke, '#0dcaf0', 'Occupied slots are not highlighted for modules');
assert.notEqual(frames.get('.flag28').querySelector('.fitted path').style.stroke, '#0dcaf0', 'Other occupied slots are not highlighted for modules');
assert.equal(frames.get('.flag29').querySelector('.fitted path').style.stroke, '#0dcaf0', 'Empty compatible slots still highlight for modules');
root.dispatchEvent(new Event('dragend'));
const restoredFlag29Stroke = frames.get('.flag29').querySelector('.fitted path').style.stroke;
for (let index = 0; index < 50; index++) {
    controls.get('high1').children[0].children[0].dispatchEvent(dragStart);
    root.dispatchEvent(new Event('dragend'));
}
assert.equal(frames.get('.flag29').querySelector('.fitted path').style.stroke, restoredFlag29Stroke, 'Repeated drags restore slot styling without accumulating effects');
assert.ok([...frames.values()].every(frame => !frame.style.filter), 'Drag highlighting does not use expensive SVG filters');
controls.get('eft-modal-text').value = '[Rifter, Cargo browsing]\n\nAntimatter Charge S x100';
click('eft-modal-import');
await settle();
const beforeCargoBrowse = stored.get('zkb:simulate');
const cargoSection = controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo');
cargoSection.children.find(row => treeText(row).includes('Antimatter Charge S')).dispatchEvent(dragStart);
controls.get('equipment').dispatchEvent(trashDrop);
assert.equal(controls.get('search').value, 'Antimatter Charge S');
assert.deepEqual(equipmentButtons(controls.get('results')).map(button => button['aria-label']), ['Add Antimatter Charge S']);
assert.equal(stored.get('zkb:simulate'), beforeCargoBrowse, 'Cargo browsing does not remove or alter the cargo stack');
controls.get('eft-modal-text').value = '[Rifter, Equipment reload]\n150mm Railgun II\n150mm Railgun II';
click('eft-modal-import');
await settle();
bayType = 222;
controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo').dispatchEvent(bayDrop);
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.find(item => item.flag === 5).quantity, 2 * Math.floor(catalog[3074].capacity / catalog[222].volume), 'Equipment charge drops reserve a full reload for compatible modules');
controls.get('search').value = 'Railgun';
controls.get('search').dispatchEvent(new Event('input'));
click('sort');
assert.equal(controls.get('sort').textContent, 'Alpha');
const alphaNames = equipmentButtons(controls.get('results')).map(button => button['aria-label'].slice(4));
assert.deepEqual(alphaNames, [...alphaNames].sort((a, b) => a.localeCompare(b)), 'Alpha orders equipment by name within its rack');
click('sort');
assert.equal(controls.get('sort').textContent, 'Meta');
const metaLevels = equipmentButtons(controls.get('results')).map(button => byName(button['aria-label'].slice(4)).attributes.metaLevelOld || 0);
assert.deepEqual(metaLevels, [...metaLevels].sort((a, b) => a - b), 'Meta orders modules by meta level');
controls.get('high1').children[0].children[0].dispatchEvent(dragStart);
controls.get('equipment').dispatchEvent(trashDrop);
assert.ok(controls.get('results').children.some(row => row.textContent === 'Valid charges'), 'Compatible charges use the Valid charges heading');
click('sort');
window.zkbPageCleanup();
window.location.hash = '';
window.zkbInitSimulate();
await settle();
assert.equal(controls.get('sort').textContent, 'Alpha', 'Sort preference survives reload');
await new Promise(resolve => setImmediate(resolve));
const pricingCard = controls.get('stats').children.at(-1).children[0].children[0];
assert.equal(pricingCard.children[0].children[0].children[0].textContent, 'Pricing', 'Pricing is the final section in the right stats column');
const pricing = pricingCard.children[1].children[0];
const currentFit = JSON.parse(stored.get('zkb:simulate'));
assert.equal(treeText(pricing.children.find(row => row.children[0]?.textContent === 'Total').children[1]).trim(),
    ((1 + currentFit.items.reduce((sum, item) => sum + (item.quantity || 1), 0)) * 100).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' ISK', 'Pricing includes hull and all item quantities');
assert.ok(pricing.children.filter(row => row.children.length === 2).every(row => treeText(row.children[1]).trim() !== '0 ISK' || row.hidden), 'Zero-ISK pricing rows are hidden');
assert.ok(pricing.children.filter(row => row.children[1] && treeText(row.children[1]).trim() === '0 ISK').every(row => row.className.includes('d-none')), 'Flex pricing rows use d-none so zero values stay hidden in the browser');
assert.equal(pricing.children.find(row => row.children[0]?.textContent === 'Hull').hidden, false, 'Non-zero pricing rows remain visible');
const lookupCount = priceLookups.length;
controls.get('high1').children[0].click();
await settle();
assert.equal(priceLookups.length, lookupCount, 'Module state changes reuse cached prices');
assert.equal(priceLookups[0], '/api/prices/date/' + new Date(Date.now() - 86400000).toISOString().slice(0, 10) + '/', 'Pricing uses the previous UTC date snapshot');
assert.equal(priceLookups[1], rateLimitedPrice, 'Rate-limited snapshot lookup retries');
window.zkbPageCleanup();
let autocompleteData;
let menusRemoved = 0;
globalThis.jQuery = window.jQuery = () => ({
    zz_search() { autocompleteData = { data: { menu: { remove() { menusRemoved++; } } } }; },
    data() { return autocompleteData; },
    off() { return this; },
    removeData() { autocompleteData = undefined; }
});
jQuery.fn = { zz_search: true };
window.zkbInitSimulate();
await settle();
const fitBeforeNavigation = stored.get('zkb:simulate');
window.zkbPageCleanup();
assert.equal(menusRemoved, 1, 'Navigation removes the autocomplete menu');
assert.doesNotThrow(() => window.zkbInitSimulate(), 'Returning after cleanup initializes without accessing removed autocomplete data');
await settle();
assert.equal(root.dataset.initialized, 'true');
assert.equal(stored.get('zkb:simulate'), fitBeforeNavigation, 'Returning restores the current fit');
assert.equal(controls.get('stats').children.length, 7, 'Returning renders fitting stats');
controls.get('damage-em').value = 35;
controls.get('damage-em').dispatchEvent(new Event('change'));
await settle();
let damageProfile = JSON.parse(stored.get('zkb:simulate')).damageProfile;
assert.deepEqual(damageProfile, { em: 35, thermal: 15, kinetic: 25, explosive: 25 }, 'Increasing damage removes the difference from the next type');
controls.get('damage-em').value = 100;
controls.get('damage-em').dispatchEvent(new Event('change'));
await settle();
damageProfile = JSON.parse(stored.get('zkb:simulate')).damageProfile;
assert.deepEqual(damageProfile, { em: 100, thermal: 0, kinetic: 0, explosive: 0 }, 'Increasing damage drains each following type as necessary');
controls.get('damage-em').value = 25;
controls.get('damage-em').dispatchEvent(new Event('change'));
await settle();
damageProfile = JSON.parse(stored.get('zkb:simulate')).damageProfile;
assert.deepEqual(damageProfile, { em: 25, thermal: 75, kinetic: 0, explosive: 0 }, 'Decreasing damage adds the difference to following types');
controls.get('damage-kinetic-slider').value = 100;
controls.get('damage-kinetic-slider').dispatchEvent(new Event('input'));
await settle();
damageProfile = JSON.parse(stored.get('zkb:simulate')).damageProfile;
assert.deepEqual(damageProfile, { em: 0, thermal: 0, kinetic: 100, explosive: 0 }, 'Damage sliders update and redistribute the profile');
assert.equal(controls.get('damage-kinetic').value, 100, 'Damage sliders update their number boxes');
assert.equal(controls.get('damage-em-slider').value, 0, 'Redistribution updates the other damage sliders');
controls.get('damage-reset').click();
await settle();
damageProfile = JSON.parse(stored.get('zkb:simulate')).damageProfile;
assert.deepEqual(damageProfile, { em: 25, thermal: 25, kinetic: 25, explosive: 25 }, 'Damage reset restores the uniform profile');
assert.equal(controls.get('damage-kinetic-slider').value, 25, 'Damage reset updates the sliders');
controls.get('security-status-minus10').click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, -10, 'The -10 security shortcut updates the fit');
controls.get('security-status-minus5').click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, -5, 'The -5.0 security shortcut updates the fit');
controls.get('security-status-zero').click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, 0, 'The 0.0 security shortcut updates the fit');
controls.get('security-status-plus5').click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, 5, 'The 5.0 security shortcut updates the fit');
assert.equal(controls.get('security-status-value').textContent, '5.0', 'Pilot security status displays one decimal place');
assert.equal(controls.get('security-status-detail').textContent, 'Security status: 4.2', 'The character row continues to show the ESI value');
controls.get('security-status-reset').click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, 4.2, 'Security reset restores the logged-in character value');
window.zkbPageCleanup();
window.zkbPageCleanup();
assert.equal(menusRemoved, 2, 'Repeated cleanup removes each autocomplete menu only once');
delete window.jQuery;
delete globalThis.jQuery;
console.log('Charge drag/drop checks passed: equipment drag, compatible loading, invalid targets, and undo.');

window.zkbInitSimulate();
await settle();
controls.get('ship').value = 'Rifter';
click('new');
await settle();
controls.get('category').value = 'Cargo';
controls.get('search').value = 'Strong Exile';
controls.get('category').dispatchEvent(new Event('change'));
assert.ok(equipmentButtons(controls.get('results')).some(button => button['aria-label'] === 'Add Strong Exile Booster'), 'Boosters can still be browsed as cargo');
click('tab-boosters');
assert.equal(controls.get('boosters-panel').hidden, false, 'The Boosters tab opens');
assert.equal(controls.get('ship-panel').hidden, true);
assert.equal(controls.get('tab-boosters')['aria-selected'], 'true');
controls.get('booster-search').value = 'quafe classic';
controls.get('booster-search').dispatchEvent(new Event('input'));
assert.deepEqual(equipmentButtons(controls.get('booster-results')).map(button => button['aria-label']), ['Add Quafe Zero Classic'], 'Booster search matches all search words');
equipmentButtons(controls.get('booster-results'))[0].click();
await settle();
assert.equal(controls.get('tab-boosters').textContent, 'Boosters (1)');
assert.match(treeText(controls.get('boosters')), /Quafe Zero Classic/);
click('boosters-eft-open');
assert.match(controls.get('eft-modal-text').value, /Quafe Zero Classic/, 'The Boosters EFT button exports the current ship and boosters');
let boosterSavedFit;
window.saveFitting = (id, exported) => { boosterSavedFit = exported; };
click('boosters-save');
assert.equal(boosterSavedFit.ship_type_id, byName('Rifter').id, 'The Boosters Save Fit button uses the current ship fit');
let boosterMarkers = findElements(controls.get('stats'), element => element['aria-label'] === 'Adjusted by boosters');
assert.equal(boosterMarkers.length, 2, 'Only speed and scan resolution receive booster markers for Quafe');
assert.ok(boosterMarkers.every(marker => marker.textContent === '*' && marker['data-bs-toggle'] === 'popover'), 'Booster markers use the existing star popovers');
let boosterToggle = findElements(controls.get('boosters'), element => element.type === 'checkbox')[0];
boosterToggle.checked = false;
boosterToggle.dispatchEvent(new Event('change'));
await settle();
assert.equal(findElements(controls.get('stats'), element => element['aria-label'] === 'Adjusted by boosters').length, 0, 'Disabling boosters removes the stat markers');
window.zkbPageCleanup();
window.zkbInitSimulate();
await settle();
assert.equal(controls.get('boosters-panel').hidden, false, 'Reload restores the Boosters tab');
assert.equal(controls.get('booster-search').value, 'quafe classic', 'Reload restores the booster search');
assert.equal(JSON.parse(stored.get('zkb:simulate')).boosters[0].enabled, false, 'Reload preserves disabled boosters');
boosterToggle = findElements(controls.get('boosters'), element => element.type === 'checkbox')[0];
boosterToggle.checked = true;
boosterToggle.dispatchEvent(new Event('change'));
await settle();
click('tab-ship');
assert.equal(findElements(controls.get('stats'), element => element['aria-label'] === 'Adjusted by boosters').length, 2, 'Booster effects are marked on the Ship tab too');
click('tab-boosters');
controls.get('booster-search').value = 'Strong Exile';
controls.get('booster-search').dispatchEvent(new Event('input'));
equipmentButtons(controls.get('booster-results')).find(button => button['aria-label'] === 'Add Strong Exile Booster').click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).boosters.length, 1, 'Adding a booster replaces the existing booster in its slot');
assert.equal(findElements(controls.get('stats'), element => element['aria-label'] === 'Adjusted by boosters').length, 0, 'Repair boosters do not mark stats on a ship with no repair modules');
findElements(controls.get('boosters'), element => element['aria-label'] === 'Remove Strong Exile Booster')[0].click();
await settle();
assert.equal(controls.get('tab-boosters').textContent, 'Boosters (0)');
click('undo');
await settle();
assert.equal(controls.get('tab-boosters').textContent, 'Boosters (1)', 'Undo restores a removed booster');
click('redo');
await settle();
assert.equal(controls.get('tab-boosters').textContent, 'Boosters (0)', 'Redo removes the booster again');
window.zkbPageCleanup();
console.log('Booster checks passed: search, slot replacement, enable/remove, affected-stat popovers, persistence, and undo/redo.');

// Cross-tab regressions: search visibility, configured pilot values, and capsule refresh.
const keyboardEvents = new EventTarget();
globalThis.addEventListener = keyboardEvents.addEventListener.bind(keyboardEvents);
document.querySelector = () => null;
controls.get('tab-configure').matches = () => false;
controls.get('tab-configure').addEventListener('click', () => click('tab-configure'));
window.zkbInitSimulate();
await settle();
for (const [kind, query, slot] of [['implant', 'Shield Management SM-706', 7], ['booster', 'Quafe Zero Classic', 1]]) {
    controls.get(kind + '-search').value = '';
    controls.get(kind + '-search').dispatchEvent(new Event('input'));
    const group = controls.get(kind + '-results').children.find(group => group.children[0]?.textContent === 'Slot ' + slot);
    group.open = false;
    group.dispatchEvent(new Event('toggle'));
    for (const meta of group.children[1].children) {
        meta.open = false;
        meta.dispatchEvent(new Event('toggle'));
    }
    controls.get(kind + '-search').value = query;
    controls.get(kind + '-search').dispatchEvent(new Event('input'));
    const matches = controls.get(kind + '-results').children[0];
    assert.equal(matches.open, true, kind + ' searches reveal matching slots even when browsing groups were collapsed');
    assert.ok(matches.children[1].children.every(meta => meta.open), kind + ' searches reveal matching meta groups');
    matches.dispatchEvent(new Event('toggle'));
    controls.get(kind + '-search').value = '';
    controls.get(kind + '-search').dispatchEvent(new Event('input'));
    assert.equal(controls.get(kind + '-results').children.find(group => group.children[0]?.textContent === 'Slot ' + slot).open, false, 'Clearing search restores the collapsed browse group');
}
const configureShortcut = new Event('keydown', { cancelable: true });
Object.assign(configureShortcut, { key: 'c', altKey: true });
keyboardEvents.dispatchEvent(configureShortcut);
assert.equal(configureShortcut.defaultPrevented, true);
assert.equal(controls.get('configure-panel').hidden, false, 'Alt+C opens Configure');
controls.get('security-status-minus5').click();
await settle();
window.zkbPageCleanup();
window.zkbInitSimulate();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, -5, 'Reload preserves configured security status instead of replacing it with ESI');
assert.equal(controls.get('security-status-detail').textContent, 'Security status: 4.2', 'The pilot identity still updates when the fit uses a different security status');
window.zkbPageCleanup();
const fetchBeforeDelay = globalThis.fetch;
let finishPilotLookup;
globalThis.fetch = url => String(url).startsWith('https://esi.evetech.net/characters/')
    ? new Promise(resolve => { finishPilotLookup = resolve; }) : fetchBeforeDelay(url);
window.zkbInitSimulate();
await settle();
controls.get('ship').value = 'Rifter';
click('new');
await settle();
controls.get('security-status-minus10').click();
await settle();
finishPilotLookup(new Response(JSON.stringify({ name: 'Test Pilot', security_status: 4.2 })));
await new Promise(resolve => setImmediate(resolve));
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, -10, 'A delayed pilot lookup does not overwrite a manual setting');
controls.get('security-status-reset').click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).securityStatus, 4.2, 'Reset explicitly applies the fetched pilot security status');
globalThis.fetch = fetchBeforeDelay;
click('tab-ship');
controls.get('eft-modal-text').value = "[Rifter, Capsule refresh]\nZainou 'Gnome' Shield Management SM-706";
click('eft-modal-import');
await settle();
click('tab-capsule');
await settle();
assert.equal(controls.get('stats').children.length, 4);
click('tab-ship');
click('eft-modal-import');
await settle();
click('tab-capsule');
await settle();
assert.equal(controls.get('stats').children.length, 4, 'Reloading the same implant set still refreshes capsule stats after returning from Ship');
assert.ok(findElements(controls.get('stats'), element => element.textContent === 'Capsule').length, 'The Implants tab displays capsule pricing, not stale ship pricing');
click('tab-ship');
controls.get('eft-modal-text').value = '[Rifter, Booster prices]\nQuafe Zero Classic';
click('eft-modal-import');
await settle();
await new Promise(resolve => setImmediate(resolve));
let boosterPriceRow = findElements(controls.get('stats'), element => element.children[0]?.textContent === 'Boosters')[0];
assert.match(treeText(boosterPriceRow), /100 ISK/, 'Pricing includes a separate booster subtotal');
assert.match(treeText(findElements(controls.get('stats'), element => element.children[0]?.textContent === 'Total')[0]), /200 ISK/, 'Ship total includes the selected booster');
const boosterPriceLookups = priceLookups.length;
click('tab-boosters');
const priceToggle = findElements(controls.get('boosters'), element => element.type === 'checkbox')[0];
priceToggle.checked = false;
priceToggle.dispatchEvent(new Event('change'));
await settle();
boosterPriceRow = findElements(controls.get('stats'), element => element.children[0]?.textContent === 'Boosters')[0];
assert.match(treeText(boosterPriceRow), /100 ISK/, 'Disabling a selected booster does not remove its purchase cost');
assert.equal(priceLookups.length, boosterPriceLookups, 'Booster toggles reuse the existing price snapshot');
window.zkbPageCleanup();
console.log('Cross-tab checks passed: search visibility, Configure shortcut, saved/manual pilot settings, capsule refresh, and booster pricing.');

// Ship summaries share a row and toggle each group without removing fitted items.
window.zkbInitSimulate();
await settle();
click('tab-ship');
controls.get('eft-modal-text').value = "[Rifter, Group toggles]\nZainou 'Gnome' Shield Management SM-706\nInherent Implants 'Squire' Capacitor Management EM-805\nQuafe Zero Classic\nAgency 'Hardshell' TB9 Dose IV";
click('eft-modal-import');
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).implants.length, 2);
assert.equal(JSON.parse(stored.get('zkb:simulate')).boosters.length, 2);
const affectedGroups = controls.get('slots').children.at(-1);
assert.match(affectedGroups.className, /\brow\b/);
assert.equal(affectedGroups.children.length, 2, 'Implant and booster summaries share the same row');
assert.ok(affectedGroups.children.every(section => section.className.includes('col-md-6')), 'Summaries use equal columns on wider screens');
assert.equal(findElements(affectedGroups, element => element['aria-label']?.startsWith('Remove ')).length, 0, 'Ship summaries do not have removal buttons');
findElements(controls.get('slots'), element => element['aria-label'] === 'Deselect all implants')[0].click();
await settle();
let groupFit = JSON.parse(stored.get('zkb:simulate'));
assert.ok(groupFit.implants.every(item => item.enabled === false));
assert.ok(groupFit.boosters.every(item => item.enabled !== false), 'Deselecting implants leaves boosters enabled');
click('undo');
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).implants.every(item => item.enabled !== false), 'Bulk deselection is one undo step');
click('redo');
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).implants.every(item => item.enabled === false));
findElements(controls.get('slots'), element => element['aria-label'] === 'Select all implants')[0].click();
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).implants.every(item => item.enabled === true));
findElements(controls.get('slots'), element => element['aria-label'] === 'Deselect all boosters')[0].click();
await settle();
groupFit = JSON.parse(stored.get('zkb:simulate'));
assert.ok(groupFit.boosters.every(item => item.enabled === false));
assert.ok(groupFit.implants.every(item => item.enabled === true), 'Deselecting boosters leaves implants enabled');
click('tab-boosters');
assert.equal(findElements(controls.get('boosters'), element => element['aria-label']?.startsWith('Remove ')).length, 2, 'Removal remains available on the Boosters tab');
findElements(controls.get('boosters'), element => element['aria-label'] === 'Select all boosters')[0].click();
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).boosters.every(item => item.enabled === true));
const partialToggle = findElements(controls.get('boosters'), element => element.type === 'checkbox')[0];
partialToggle.checked = false;
partialToggle.dispatchEvent(new Event('change'));
await settle();
assert.equal(findElements(controls.get('boosters'), element => element['aria-label'] === 'Select all boosters').length, 1, 'A partially selected group offers Select all');
window.zkbPageCleanup();
console.log('Group toggle checks passed: responsive columns, removal scope, select/deselect all, independent groups, and undo/redo.');

// Bulk shortcuts work from any simulator tab and share button/undo behavior.
window.zkbInitSimulate();
await settle();
click('tab-configure');
function bulkShortcut(key, repeat = false) {
    const event = new Event('keydown', { cancelable: true });
    Object.assign(event, { key, altKey: true, repeat });
    keyboardEvents.dispatchEvent(event);
    return event;
}
const boostersBeforeShortcut = JSON.parse(stored.get('zkb:simulate')).boosters;
assert.equal(bulkShortcut('i').defaultPrevented, true);
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).implants.every(item => item.enabled === false), 'Alt+I deselects all implants from Configure');
assert.deepEqual(JSON.parse(stored.get('zkb:simulate')).boosters, boostersBeforeShortcut, 'Alt+I leaves boosters unchanged');
assert.equal(controls.get('configure-panel').hidden, false, 'Bulk shortcuts do not switch tabs');
const beforeRepeat = stored.get('zkb:simulate');
bulkShortcut('i', true);
await settle();
assert.equal(stored.get('zkb:simulate'), beforeRepeat, 'Holding a bulk shortcut does not repeatedly toggle the group');
assert.equal(bulkShortcut('b').defaultPrevented, true);
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).boosters.every(item => item.enabled === true), 'Alt+B selects all boosters from a partial selection');
assert.ok(JSON.parse(stored.get('zkb:simulate')).implants.every(item => item.enabled === false));
bulkShortcut('b');
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).boosters.every(item => item.enabled === false), 'Alt+B deselects a fully selected group');
bulkShortcut('i');
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).implants.every(item => item.enabled === true), 'Alt+I selects all disabled implants');
click('undo');
await settle();
assert.ok(JSON.parse(stored.get('zkb:simulate')).implants.every(item => item.enabled === false), 'A bulk shortcut is one undo step');
const beforeDisabledShortcut = stored.get('zkb:simulate');
stored.set('zkb-keyboard-shortcuts', 'off');
assert.equal(bulkShortcut('i').defaultPrevented, false);
await settle();
assert.equal(stored.get('zkb:simulate'), beforeDisabledShortcut, 'Bulk shortcuts respect the keyboard-shortcuts setting');
stored.delete('zkb-keyboard-shortcuts');
keyboardEvents.closest = () => ({});
assert.equal(bulkShortcut('b').defaultPrevented, false, 'Bulk shortcuts are ignored while editing a field');
delete keyboardEvents.closest;
window.zkbPageCleanup();
console.log('Bulk shortcut checks passed: independent groups, all tabs, partial selection, undo, key repeat, and keyboard guards.');

// Environmental effects work across tabs, history, local saves and shortcuts.
stored.set('zkb:simulate', JSON.stringify({ ship_type_id: 587, name: 'Effects test', items: [] }));
window.zkbInitSimulate();
await settle();
click('tab-effects');
assert.equal(controls.get('effects-panel').hidden, false);
assert.equal(controls.get('effects-stats').children.includes(controls.get('stats')), true, 'Effects tab displays live ship statistics');
controls.get('effect-search').value = 'class 1 pulsar';
controls.get('effect-search').dispatchEvent(new Event('input'));
const selectPulsar = findElements(controls.get('effect-results'), element => element['aria-label'] === 'Select Class 1 Pulsar Effects')[0];
assert.ok(selectPulsar, 'Environmental search matches all words');
selectPulsar.click();
await settle();
assert.deepEqual(JSON.parse(stored.get('zkb:simulate')).environmentEffect, { type_id: pulsar.id, enabled: true });
assert.equal(controls.get('space-security').value, 'wormhole');
assert.ok(findElements(controls.get('stats'), element => element['aria-label'] === 'Adjusted by environment').length, 'Environmental changes use the existing star popovers');
const disableEnvironment = findElements(controls.get('effects'), element => element.type === 'checkbox')[0];
disableEnvironment.checked = false;
disableEnvironment.dispatchEvent(new Event('change'));
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).environmentEffect.enabled, false);
assert.equal(findElements(controls.get('stats'), element => element['aria-label'] === 'Adjusted by environment').length, 0);
click('undo');
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).environmentEffect.enabled, true, 'Undo restores the environmental effect');
assert.equal(findElements(controls.get('effects'), element => element.type === 'checkbox')[0]['aria-keyshortcuts'], 'Alt+e');
for (const tab of ['ship', 'effects']) {
    click('tab-' + tab);
    assert.equal(bulkShortcut('e').defaultPrevented, true);
    await settle();
    assert.equal(JSON.parse(stored.get('zkb:simulate')).environmentEffect.enabled, false, 'Alt+E disables the environment from either tab');
    bulkShortcut('e', true);
    await settle();
    assert.equal(JSON.parse(stored.get('zkb:simulate')).environmentEffect.enabled, false, 'Holding Alt+E does not toggle repeatedly');
    bulkShortcut('e');
    await settle();
    assert.equal(JSON.parse(stored.get('zkb:simulate')).environmentEffect.enabled, true, 'Alt+E re-enables the selected effect');
}

controls.get('space-security').value = 'null_sec';
controls.get('space-security').dispatchEvent(new Event('change'));
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).spaceSecurity, 'null_sec');
click('tab-ship');
assert.ok(treeText(controls.get('slots')).includes('Class 1 Pulsar Effects'), 'Ship tab identifies the active environment');
assert.equal(findElements(controls.get('slots'), element => element['aria-label'] === 'Remove Class 1 Pulsar Effects').length, 0, 'Ship summary has no trash button');
controls.get('search').value = 'pulsar';
controls.get('search').dispatchEvent(new Event('input'));
assert.equal(equipmentButtons(controls.get('results')).some(element => element['aria-label'] === 'Add Class 1 Pulsar Effects'), false, 'Environmental beacons are not cargo');
window.zkbPageCleanup();
window.zkbInitSimulate();
await settle();
assert.deepEqual(JSON.parse(stored.get('zkb:simulate')).environmentEffect, { type_id: pulsar.id, enabled: true }, 'Local saves preserve the selected environment');
assert.equal(controls.get('space-security').value, 'null_sec');
click('tab-effects');
findElements(controls.get('effects'), element => element['aria-label'] === 'Remove Class 1 Pulsar Effects')[0].click();
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).environmentEffect, undefined);
assert.match(treeText(controls.get('effects')), /No environmental effect/);
window.zkbPageCleanup();
console.log('Environment UI checks passed: search, selection, markers, toggle, history, local saves, Ship summary, security and removal.');

// Share links preserve the simulation state that EFT cannot represent.
const sharedSimulation = importEFT("[Tristan, Share test]\n[Empty High slot]\n150mm Railgun II, Antimatter Charge S\nHobgoblin I x3\nAntimatter Charge S x100\nZainou 'Gnome' Shield Management SM-706\nQuafe Zero Classic", catalog);
sharedSimulation.items.find(item => item.type_id === byName('150mm Railgun II').id).state = 'Overload';
sharedSimulation.items.find(item => item.flag === 87).active = 2;
sharedSimulation.implants[0].enabled = false;
sharedSimulation.boosters[0].enabled = false;
sharedSimulation.skillLevel = 0;
sharedSimulation.securityStatus = -5;
sharedSimulation.damageProfile = { em: 40, thermal: 30, kinetic: 20, explosive: 10 };
sharedSimulation.fighterTubes = [];
sharedSimulation.environmentEffect = { type_id: pulsar.id, enabled: true };
sharedSimulation.spaceSecurity = 'wormhole';
assert.deepEqual(importSimulation(JSON.stringify(sharedSimulation), catalog), sharedSimulation, 'Simulation import preserves exact slots, states, charges, cargo, drones, implants, boosters, skills and Configure settings');
assert.deepEqual(importSimulation(JSON.stringify(partialCarrier), catalog), partialCarrier, 'Simulation import preserves partial fighter tube assignments');
assert.deepEqual(importSimulation(JSON.stringify({ ...spoolingFit, mode: 'Defense' }), catalog), { ...spoolingFit, mode: 'Defense' }, 'Simulation import preserves mode and spool settings');
for (const invalid of [null, { ...sharedSimulation, items: [null] }, { ...sharedSimulation, ship_type_id: 99999999 },
    { ...sharedSimulation, skillLevel: 7 }, { ...sharedSimulation, securityStatus: -11 }, { ...sharedSimulation, damageProfile: { em: 25, thermal: 25, kinetic: 25, explosive: 0 } },
    { ...sharedSimulation, implants: [{ ...sharedSimulation.implants[0], enabled: 'false' }] },
    { ...sharedSimulation, boosters: [{ ...sharedSimulation.boosters[0], slot: 2 }] },
    { ...sharedSimulation, items: [{ ...sharedSimulation.items[0], state: 'invalid' }] },
    { ...sharedSimulation, items: [{ ...sharedSimulation.items[0], flag: 87 }] },
    { ...sharedSimulation, items: [sharedSimulation.items[0], sharedSimulation.items[0]] },
    { ...sharedSimulation, fighterTubes: [{ type_id: byName('Hobgoblin I').id, quantity: 1 }] }
]) assert.throws(() => importSimulation(JSON.stringify(invalid), catalog), 'Invalid shared data is rejected before changing the saved fit');
assert.throws(() => importSimulation('x'.repeat(50001), catalog), /too large/);
window.location.origin = 'http://localhost';
window.location.hash = '';
stored.set('zkb:simulate', JSON.stringify(sharedSimulation));
window.zkbInitSimulate();
await settle();
assert.equal(controls.get('share').disabled, false, 'Share becomes available for a loaded fit');
controls.get('name').value = 'Pilot 🚀 & fleet / fit';
click('share');
for (let attempt = 0; attempt < 200 && !controls.get('share-url').value; attempt++) await new Promise(resolve => setTimeout(resolve, 5));
const sharedURL = controls.get('share-url').value;
assert.ok(sharedURL.startsWith('http://localhost/simulate/#state='), 'Share uses the current host and the /simulate/ fragment');
const sharedPayload = new URLSearchParams(new URL(sharedURL).hash.slice(1)).get('state');
assert.match(sharedPayload, /^[A-Za-z0-9_-]+$/, 'Compressed payload uses URL-safe Base64');
const sharedState = JSON.parse(gunzipSync(Buffer.from(sharedPayload, 'base64url')).toString('utf8'));
const uncompressedURL = window.location.origin + '/simulate/#' + new URLSearchParams({ state: JSON.stringify(sharedState) });
assert.ok(sharedURL.length < uncompressedURL.length / 2, 'Compression more than halves the representative share link');
console.log(`Share link compressed from ${uncompressedURL.length} to ${sharedURL.length} characters.`);
assert.deepEqual(sharedState, { ...JSON.parse(stored.get('zkb:simulate')), name: 'Pilot 🚀 & fleet / fit' });
const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
let copiedShare;
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { writeText: async text => { copiedShare = text; } } } });
const previousBootstrap = window.bootstrap;
let shareModalClosed = 0;
window.bootstrap = { Modal: { getInstance: modal => {
    assert.equal(modal, controls.get('share-modal'));
    return { hide: () => { shareModalClosed++; } };
} } };
for (const target of ['share-copy', 'share-url']) {
    copiedShare = null;
    click(target);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(copiedShare, sharedURL, 'Both share controls copy the URL');
    assert.equal(toasts.at(-1), 'URL copied to clipboard.');
}
assert.equal(shareModalClosed, 2, 'Both share controls close the modal after copying');
let selectedShare = false;
controls.get('share-url').select = () => { selectedShare = true; };
navigator.clipboard.writeText = async () => { throw new Error('Clipboard denied'); };
click('share-copy');
await new Promise(resolve => setImmediate(resolve));
assert.equal(selectedShare, true, 'Clipboard failure selects the link for manual copying');
assert.equal(toasts.at(-1), 'Unable to copy. Copy the selected link manually.');
assert.equal(shareModalClosed, 2, 'Clipboard failure keeps the selected URL available');
window.bootstrap = previousBootstrap;
if (previousNavigator) Object.defineProperty(globalThis, 'navigator', previousNavigator);
else delete globalThis.navigator;
window.zkbPageCleanup();
window.location.hash = new URL(sharedURL).hash;
stored.set('zkb:simulate', JSON.stringify({ ship_type_id: 587, name: 'Old fit', items: [] }));
window.zkbInitSimulate();
await settle();
assert.deepEqual(JSON.parse(stored.get('zkb:simulate')), sharedState, 'Opening a share link restores all settings and overrides the old saved fit');
assert.equal(controls.get('ship-panel').hidden, false, 'Shared fits open on the Ship tab');
assert.equal(controls.get('skills').value, '0');
assert.equal(controls.get('security-status-value').textContent, '-5.0');
assert.equal(controls.get('space-security').value, 'wormhole', 'Share links restore space security');
assert.deepEqual((await calculate(JSON.parse(stored.get('zkb:simulate')), 0)).stats, (await calculate(sharedSimulation, 0)).stats, 'Shared fit statistics match the original');
window.zkbPageCleanup();
const savedBeforeBadShare = stored.get('zkb:simulate');
for (const payload of [
    gzipSync(JSON.stringify({ ...sharedState, damageProfile: { em: 0, thermal: 0, kinetic: 0, explosive: 0 } })).toString('base64url'),
    'invalid!', Buffer.from('not gzip').toString('base64url'), gzipSync('x'.repeat(200001)).toString('base64url')
]) {
    window.location.hash = '#state=' + payload;
    window.zkbInitSimulate();
    await settle();
    assert.equal(stored.get('zkb:simulate'), savedBeforeBadShare, 'An invalid share link leaves the saved fit untouched');
    assert.equal(controls.get('share').disabled, true, 'An invalid incoming fit cannot share stale data');
    assert.match(controls.get('status').textContent, /Unable to load this fit/);
    window.zkbPageCleanup();
}
window.location.hash = '';
console.log('Share checks passed: full-state links, validation, Unicode names, copy/fallback, exact restore, and invalid-link preservation.');
