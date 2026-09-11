// Focused simulator checks using the shipped game data and real WASM engine.
// Run: node tests/simulate.mjs
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { racks, slotCount, compatible, compatibleCharge, hasDroneBay, validateDrones, addModule, importEFT, exportEFT, warnings } from '../public/js/simulate-model.js';

let result;
globalThis.self = { postMessage: value => { result = value; } };
const priceLookups = [];
let rateLimitedPrice;
globalThis.fetch = async url => {
    if (String(url).startsWith('/api/prices/')) {
        priceLookups.push(url);
        if (!rateLimitedPrice) {
            rateLimitedPrice = url;
            return new Response('', { status: 429, headers: { 'Retry-After': '0' } });
        }
        return new Response(JSON.stringify(Object.fromEntries(Object.keys(catalog).map(typeID => [typeID, 100]))));
    }
    return new Response(await readFile(url));
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
const bare = await calculate(fit);
assert.equal(bare.stats.upgradeLoad, 0, 'An unrigged hull uses zero calibration');
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
console.log('Simulator checks passed: catalog, skills, module states and heat, fitting limits, charges, EFT round trips and errors, drones, and subsystems.');

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
for (const name of ['sort', 'eft-modal', 'eft-modal-text', 'eft-modal-status', 'equipment', 'mode-Defense', 'mode-Propulsion', 'mode-Sharpshooter', 'mode-control', 'status', 'controls', 'ship', 'ships', 'name', 'skills', 'new', 'category', 'search', 'results', 'hull', 'image', 'slots', 'stats', 'warnings', 'trash', 'undo', 'redo', 'wheel', 'Fitting_Panel', 'bigship']) {
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
// Keep the engine callbacks: the worker and page share this test process.
window.location = { hash: '' };
const toasts = [];
window.showToast = message => toasts.push(message);
const stored = new Map();
globalThis.localStorage = { getItem: key => stored.get(key) || null, setItem: (key, value) => stored.set(key, value) };
let pending = Promise.resolve();
let workerCount = 0;
let terminated = 0;
globalThis.Worker = class {
    constructor(url) {
        assert.equal(url.search, '?test=worker-refresh', 'The worker follows the page module cache key');
        workerCount++;
    }
    terminate() { terminated++; }
    postMessage(request) {
        pending = pending.then(async () => {
            await self.onmessage({ data: request });
            this.onmessage({ data: result });
        });
    }
};
const settle = async () => { let previous; do { previous = pending; await previous; } while (previous !== pending); };
await import('../public/js/simulate.js?test=worker-refresh');
await settle();
assert.equal(controls.get('controls').disabled, false);
window.zkbInitSimulate();
assert.equal(workerCount, 1, 'Repeated initialization should reuse this page');
function click(name) {
    const event = new Event('click');
    Object.defineProperty(event, 'target', { value: { id: 'simulate-' + name } });
    root.dispatchEvent(event);
}
function equipmentButtons(element) {
    return (element['aria-label']?.startsWith('Add ') ? [element] : []).concat(element.children.flatMap(equipmentButtons));
}
controls.get('ship').value = 'Confessor';
click('new');
await settle();
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
    const rows = controls.get('stats').children[4].children[0].children[0].children[1].children;
    return rows[rows.findIndex(row => row.textContent === 'Speed') + 1].textContent;
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
controls.get('search').value = '';
controls.get('category').value = 'High';
controls.get('ship').value = 'Rifter';
click('new');
await settle();
assert.equal(controls.get('hull').textContent, 'Rifter');
assert.deepEqual(controls.get('slots').children.map(section => section.children[0].textContent), ['Cargo'], 'Ships without a drone bay hide the Drones section');
assert.equal(controls.get('stats').children.length, 7, controls.get('status').textContent);
const statsToggles = controls.get('stats').querySelectorAll('button[aria-expanded]');
for (const expanded of ['true', 'false']) {
    statsToggles[1].click();
    statsToggles[1].click();
    statsToggles[1].lastChild.dispatchEvent(new Event('dblclick'));
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
assert.equal(terminated, 1);
window.zkbInitSimulate();
await settle();
assert.equal(workerCount, 2);
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
Object.defineProperty(dragStart, 'dataTransfer', { value: { setData: (key, value) => removalTransfer.set(key, value) } });
const trashDrop = new Event('drop');
Object.defineProperty(trashDrop, 'dataTransfer', { value: { getData: key => removalTransfer.get(key) || '' } });
controls.get('high1').children[0].children[0].dispatchEvent(dragStart);
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
    assert.match(source, /href=\('\/simulate\/#eft='[^\n]+data-spa="off"\) Simulate/);
}
console.log('Reported Tholos killmail loads and calculates; Simulate links use same-tab document navigation.');

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
Object.defineProperty(trashOver, 'dataTransfer', { value: { types: [...removalTransfer.keys()] } });
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
console.log('Bay drop checks passed: drone stacking, full bay, wrong item type, cargo charges, and undo.');
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
controls.get('high1l').children[0].dispatchEvent(dragStart);
assert.ok(frames.get('.flag28').style.filter.includes('drop-shadow'), 'Compatible module outlines highlight while dragging');
assert.ok(controls.get('trash').style.filter.includes('drop-shadow'), 'Trash highlights for a loaded charge');
assert.equal(frames.get('.flag29').style.filter, '', 'Empty slots are not highlighted for charges');
frames.get('.flag28').ondrop(trashDrop);
await settle();
assert.equal(JSON.parse(stored.get('zkb:simulate')).items.filter(item => item.type_id === 222).length, 2, 'Dropping on a slot frame loads the destination and preserves the source charge');
root.dispatchEvent(new Event('dragend'));
assert.equal(frames.get('.flag28').style.filter, '', 'Drag highlights clear after drag ends');
assert.equal(controls.get('trash').style.filter, '', 'Trash highlight clears after drag ends');
controls.get('high1').children[0].children[0].dispatchEvent(dragStart);
assert.equal(frames.get('.flag27').style.filter, '', 'Occupied slots are not highlighted for modules');
assert.equal(frames.get('.flag28').style.filter, '', 'Other occupied slots are not highlighted for modules');
assert.ok(frames.get('.flag29').style.filter.includes('drop-shadow'), 'Empty compatible slots still highlight for modules');
root.dispatchEvent(new Event('dragend'));
controls.get('eft-modal-text').value = '[Rifter, Cargo browsing]\n\nAntimatter Charge S x100';
click('eft-modal-import');
await settle();
const beforeCargoBrowse = stored.get('zkb:simulate');
const cargoSection = controls.get('slots').children.find(section => section.children[0].textContent === 'Cargo');
cargoSection.children[1].dispatchEvent(dragStart);
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
assert.equal(pricing.children.find(row => row.children[0]?.textContent === 'Total').children[1].textContent,
    ((1 + currentFit.items.reduce((sum, item) => sum + (item.quantity || 1), 0)) * 100).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' ISK', 'Pricing includes hull and all item quantities');
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
window.zkbPageCleanup();
window.zkbPageCleanup();
assert.equal(menusRemoved, 2, 'Repeated cleanup removes each autocomplete menu only once');
delete window.jQuery;
delete globalThis.jQuery;
console.log('Charge drag/drop checks passed: equipment drag, compatible loading, invalid targets, and undo.');
