// Integration checks against the real vendored WASM and compressed game data.
// Run: node tests/fit-stats.mjs
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const results = new Map();
const fetches = [];
globalThis.self = { postMessage: result => results.set(result.id, result) };
globalThis.fetch = async url => {
    assert.equal(url.protocol, 'file:', 'Tests must not contact external services');
    fetches.push(url.pathname);
    return new Response(await readFile(process.argv[2] && url.pathname.endsWith('/data.json.gz') ? process.argv[2] : url));
};
await import('../public/js/fit-stats-worker.js');

let requestID = 0;
async function calculate(items = [], ship = 587) {
    const id = ++requestID;
    await self.onmessage({ data: { id, fit: { ship_type_id: ship, items } } });
    return results.get(id);
}
function close(actual, expected) {
    assert.ok(Math.abs(actual - expected) < 0.001, actual + ' != ' + expected);
}

const [bare, afterburner] = await Promise.all([
    calculate(),
    calculate([{ type_id: 438, flag: 19, quantity: 1 }])
]);
assert.ok(!bare.error, bare.error);
assert.ok(!afterburner.error, afterburner.error);
assert.equal(fetches.length, 2, 'Concurrent fits share one data/engine load');

// Rifter base stats receive the all-V engineering, navigation and HP bonuses.
const base = Object.fromEntries(window.get_dogma_attributes(587).map(attribute => [attribute.attributeID, attribute.value]));
close(bare.stats.cpuOutput, base[48] * 1.25);
close(bare.stats.powerOutput, base[11] * 1.25);
close(bare.stats.maxVelocity, base[37] * 1.25);
close(bare.stats.shieldCapacity, base[263] * 1.25);
assert.equal(bare.stats.cpuLoad, 0);
assert.equal(bare.stats.powerLoad, 0);
assert.ok(bare.stats.capacitorDepletesIn < 0);
close(bare.stats.ehp, bare.stats.shieldEhp + bare.stats.armorEhp + bare.stats.hullEhp);

// A fitted prop module consumes fitting resources and actually changes speed.
close(afterburner.stats.cpuLoad, 15);
close(afterburner.stats.powerLoad, 11);
assert.ok(afterburner.stats.maxVelocity > bare.stats.maxVelocity * 2);
const tank = await calculate([{ type_id: 2048, flag: 11, quantity: 1 }]);
assert.ok(tank.stats.ehp > bare.stats.ehp);
close(tank.stats.powerLoad, 1);

// Cargo and nested container contents must never become fitted modules.
const cargo = await calculate([{ type_id: 438, flag: 5, quantity: 1, items: [{ type_id: 2048, flag: 11, quantity: 1 }] }]);
assert.deepEqual(cargo.stats, bare.stats);

// A loaded charge can appear before its gun and its stack size is not a module count.
const gun = { type_id: 3074, flag: 27, quantity: 1 };
const charge = { type_id: 222, flag: 27, quantity: 80 };
const loaded = await calculate([charge, gun]);
const reversed = await calculate([gun, charge]);
assert.ok(!loaded.error, loaded.error);
assert.deepEqual(loaded.stats, reversed.stats);
assert.ok(loaded.stats.damagePerSecondWithoutReload > 0);
assert.ok(loaded.details.some(item => item.name === '150mm Railgun II'));
assert.ok(loaded.details.some(item => item.name === 'Antimatter Charge S'));
assert.ok(loaded.details.some(item => item.name === 'Pilot (all skills V)'));
const drones = await calculate([{ type_id: 2454, flag: 87, quantity: 5 }], 626);
assert.ok(!drones.error, drones.error);
close(drones.stats.droneCapacityLoad, 25);
assert.equal(drones.details.filter(item => item.slot?.type === 'DroneBay').length, 1);
assert.ok(!drones.stats.droneDamagePerSecond, 'Bay drones must not contribute active DPS');
const mining = await calculate([
    { type_id: 5245, flag: 27, quantity: 1 },
    { type_id: 5245, flag: 28, quantity: 1 },
    { type_id: 22542, flag: 11, quantity: 1 }
], 32880);
assert.ok(!mining.error, mining.error);

assert.match((await calculate([gun, gun])).error, /Multiple modules/);
assert.match((await calculate([charge])).error, /no recorded module/);
assert.match((await calculate([{ type_id: 999999999, flag: 19, quantity: 1 }])).error, /missing/);
assert.match((await calculate([], 34)).error, /not supported/);
assert.equal(fetches.length, 2, 'Later calculations reuse the loaded data');
console.log('Fit statistics checks passed: all-V bonuses, module effects, charges, cargo, invalid fits, and shared loading.');

// Exercise the shared panel with real calculation output and a small DOM stub.
class Element {
    constructor() { this.children = []; this.textContent = ''; this.classList = { add() {} }; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; this.textContent = ''; }
}
const listeners = new Set();
const output = new Element();
output.hidden = true;
output.id = 'kill-1-stats';
output.dataset = {};
const notice = { hidden: true };
const panel = { hidden: true, querySelector: selector => selector === '[data-zkb-fit-stats-notice]' ? notice : output, getAttribute: () => JSON.stringify({ ship_type_id: 587, items: [] }) };
const button = new Element();
button.closest = () => null;
panel.contains = element => element === button && button.closest() === panel;
const buttonAttributes = { 'data-zkb-calculate-fit': 'kill-1-stats-panel', 'aria-controls': output.id, 'aria-expanded': 'false' };
button.getAttribute = name => buttonAttributes[name];
button.setAttribute = (name, value) => buttonAttributes[name] = value;
const iconClasses = new Set(['fa-chevron-down']);
button.querySelector = () => ({ classList: { toggle: (name, enabled) => enabled ? iconClasses.add(name) : iconClasses.delete(name) } });
let calculations = 0;
let worker;
const context = {
    window: {},
    document: {
        querySelectorAll: () => [button],
        getElementById: id => id === 'kill-1-stats-panel' ? panel : null,
        createElement: () => new Element(),
        addEventListener: (name, callback) => listeners.add(callback),
        removeEventListener: (name, callback) => listeners.delete(callback)
    },
    setTimeout: () => 1,
    clearTimeout() {},
    Worker: class {
        constructor() { worker = this; }
        postMessage(request) { calculations++; this.request = request; }
        terminate() { this.terminated = true; }
    }
};
runInNewContext(await readFile(new URL('../public/js/fit-stats.js', import.meta.url), 'utf8'), context);
context.window.zkbInitFitStats();
context.window.zkbInitFitStats();
assert.equal(listeners.size, 1, 'Repeated initialization must not duplicate click handlers');
[...listeners][0]({ target: { closest: () => button } });
assert.equal(panel.hidden, false);
assert.equal(notice.hidden, false, 'Show the notice only after Calculate Stats is clicked');
assert.equal(buttonAttributes['aria-expanded'], 'true');
assert.ok(iconClasses.has('fa-chevron-up'));
const click = () => [...listeners][0]({ target: { closest: () => button } });
click();
assert.equal(output.hidden, true, 'Stats can be collapsed while calculating');
assert.equal(notice.hidden, true);
click();
assert.equal(calculations, 1, 'Reopening during loading must not start another calculation');
worker.onmessage({ data: { ...loaded, id: worker.request.id } });
function text(element) { return element.textContent + ' ' + element.children.map(text).join(' '); }
for (const heading of ['Fitting', 'Offense', 'Defense', 'Capacitor', 'Targeting', 'Navigation', 'Drones', 'All calculated attributes', '150mm Railgun II', 'Antimatter Charge S']) {
    assert.ok(text(output).includes(heading), 'Missing panel content: ' + heading);
}
assert.ok(!text(output.children[0]).includes('Mining'), 'Combat fits should not show a mining section');
click();
assert.equal(output.hidden, true);
assert.equal(buttonAttributes['aria-expanded'], 'false');
assert.ok(iconClasses.has('fa-chevron-down'));
click();
assert.equal(output.hidden, false);
assert.equal(notice.hidden, false);
assert.equal(calculations, 1, 'Reopening must reuse calculated results');
// Pug renders a valueless data attribute as its own name on inferred-fit buttons.
buttonAttributes['data-zkb-calculate-fit'] = 'data-zkb-calculate-fit';
button.closest = () => panel;
click();
assert.equal(output.hidden, true);
assert.equal(panel.hidden, false, 'The inferred-fit button must remain visible when collapsed');
assert.equal(buttonAttributes['aria-expanded'], 'false');
assert.ok(iconClasses.has('fa-chevron-down'));
click();
assert.equal(output.hidden, false);
assert.equal(notice.hidden, false);
assert.equal(buttonAttributes['aria-expanded'], 'true');
assert.ok(iconClasses.has('fa-chevron-up'));
assert.equal(calculations, 1, 'Inferred-fit toggles must reuse the existing calculation');
click();
output.dataset.loaded = 'false';
click();
worker.onmessage({ data: { ...mining, id: worker.request.id } });
const sections = output.children[0].children;
assert.equal(sections[6].children[0].textContent, 'Drones (remain in bay)');
assert.equal(sections[7].children[0].textContent, 'Mining (excluding critical bonuses)');
for (const value of ['426.56 m³/min', '5,000 m³', '53.32 m³/cycle', '15 s / 11 km', 'High 1:', 'High 2:']) {
    assert.ok(text(sections[7]).includes(value), 'Missing mining statistic: ' + value);
}
context.window.zkbCleanupFitStats();
assert.ok(worker.terminated);
assert.equal(listeners.size, 0, 'Navigation cleanup must remove the shared handler');
console.log('Shared statistics panel rendering and navigation cleanup checks passed.');
