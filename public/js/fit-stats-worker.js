import * as engine from '../vendor/eveshipfit/esf_dogma_engine_bg.js';

let ready;

async function loadData() {
    const response = await fetch(new URL('../vendor/eveshipfit/data.json.gz', import.meta.url), { cache: 'no-store' });
    if (response.status === 404) throw new Error('Fitting data is unavailable. Please try again later.');
    if (!response.ok) throw new Error('Unable to load fitting data.');
    const snapshot = await new Response(response.body.pipeThrough(new DecompressionStream('gzip'))).json();
    const data = snapshot.data;

    const attributes = {};
    const skills = {};
    for (const [id, attribute] of Object.entries(data.dogmaAttributes)) attributes[attribute.name] = Number(id);
    // These subsystem effects have no modifierInfo in the SDE; supply their additive slot modifiers.
    for (const [effectID, modifiers] of [
        [3773, [['turretSlotsLeft', 'turretHardPointModifier'], ['launcherSlotsLeft', 'launcherHardPointModifier']]],
        [3774, [['hiSlots', 'hiSlotModifier'], ['medSlots', 'medSlotModifier'], ['lowSlots', 'lowSlotModifier']]]
    ]) {
        data.dogmaEffects[effectID].modifierInfo = modifiers.map(([target, source]) => ({
            domain: 1, func: 0, operation: 2,
            modifiedAttributeID: attributes[target], modifyingAttributeID: attributes[source]
        }));
    }
    for (const [id, type] of Object.entries(data.types)) {
        if (type.categoryID === 16) skills[id] = 5;
    }

    // The upstream WASM bindings use window callbacks; here they stay inside the worker.
    globalThis.window = {
        get_dogma_attributes: id => data.typeDogma[id].dogmaAttributes,
        get_dogma_attribute: id => data.dogmaAttributes[id],
        get_dogma_effects: id => data.typeDogma[id].dogmaEffects,
        get_dogma_effect: id => data.dogmaEffects[id],
        get_type: id => data.types[id],
        attribute_name_to_id: name => attributes[name]
    };
    const wasmResponse = await fetch(new URL('../vendor/eveshipfit/esf_dogma_engine_bg.wasm', import.meta.url));
    if (!wasmResponse.ok) throw new Error('Unable to load fitting engine.');
    const { instance } = await WebAssembly.instantiate(await wasmResponse.arrayBuffer(), {
        './esf_dogma_engine_bg.js': engine
    });
    engine.__wbg_set_wasm(instance.exports);
    instance.exports.__wbindgen_start();
    engine.init();
    return { data, skills };
}

self.onmessage = async ({ data: request }) => {
    try {
        if (!ready) ready = loadData().catch(error => {
            ready = null;
            throw error;
        });
        const { data, skills } = await ready;
        if (request.catalog) {
            const catalog = {};
            for (const [id, type] of Object.entries(data.types)) {
                if (!type.published || ![6, 7, 8, 18, 32].includes(type.categoryID) || !data.typeDogma[id]) continue;
                const dogma = data.typeDogma[id];
                catalog[id] = {
                    ...type, id: Number(id),
                    attributes: Object.fromEntries(dogma.dogmaAttributes.map(attribute => [data.dogmaAttributes[attribute.attributeID]?.name, attribute.value])),
                    effects: dogma.dogmaEffects.map(effect => data.dogmaEffects[effect.effectID]?.name)
                };
            }
            self.postMessage({ id: request.id, catalog });
            return;
        }
        const fit = { ship_type_id: request.fit.ship_type_id, modules: [], drones: [] };
        if (data.types[fit.ship_type_id]?.categoryID !== 6 || !data.typeDogma[fit.ship_type_id]) {
            throw new Error('This hull is not supported by the fitting data.');
        }

        if (request.simulate && data.types[fit.ship_type_id].groupID === 1305) {
            const mode = request.fit.mode || 'Defense';
            if (!['Defense', 'Propulsion', 'Sharpshooter'].includes(mode)) throw new Error('Invalid tactical destroyer mode.');
            const modeType = Object.entries(data.types).find(([, type]) => type.groupID === 1306 && type.name === data.types[fit.ship_type_id].name + ' ' + mode + ' Mode');
            if (!modeType) throw new Error('Tactical destroyer mode data is unavailable.');
            fit.modules.push({ type_id: Number(modeType[0]), slot: { type: 'SubSystem', index: 1 }, state: 'Active' });
        }

        const slots = new Map();
        for (const item of request.fit.items) {
            if (item.flag === 87) {
                if (data.types[item.type_id]?.categoryID !== 18 || !data.typeDogma[item.type_id]) {
                    throw new Error('This fit contains an unsupported drone.');
                }
                if (!Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > 1000) throw new Error('Invalid drone quantity.');
                for (let i = 0; i < item.quantity; i++) fit.drones.push({ type_id: item.type_id, state: request.simulate && i < item.active ? 'Active' : 'Passive' });
            }
            let slot;
            for (const [start, end, type] of [[11, 18, 'Low'], [19, 26, 'Medium'], [27, 34, 'High'], [92, 99, 'Rig'], [125, 132, 'SubSystem']]) {
                if (item.flag >= start && item.flag <= end) slot = { type, index: item.flag - start + 1 };
            }
            if (!slot) continue;
            const type = data.types[item.type_id];
            if (!type || !data.typeDogma[item.type_id]) throw new Error('This fit contains an item missing from the fitting data.');
            if (!slots.has(item.flag)) slots.set(item.flag, { slot });
            const entry = slots.get(item.flag);
            if (type.categoryID === 8) {
                if (entry.charge) throw new Error('Multiple charges were recorded in one slot.');
                entry.charge = { type_id: item.type_id };
            } else if (type.categoryID === 7 || type.categoryID === 32) {
                if (entry.type_id || item.quantity !== 1) throw new Error('Multiple modules were recorded in one slot.');
                entry.type_id = item.type_id;
                entry.state = request.simulate && ['Passive', 'Online', 'Active', 'Overload'].includes(item.state) ? item.state : 'Active';
            } else {
                throw new Error('This fit contains an unsupported fitted item.');
            }
        }
        for (const entry of slots.values()) {
            if (!entry.type_id) throw new Error('A loaded charge has no recorded module.');
            fit.modules.push(entry);
        }

        const result = engine.calculate(fit, request.simulate && request.skillLevel === 0 ? {} : skills);
        const stats = {};
        for (const [id, attribute] of result.hull.attributes) {
            const name = data.dogmaAttributes[id]?.name;
            if (name) stats[name] = attribute.value;
        }
        if (!Number.isFinite(stats.upgradeLoad)) {
            stats.upgradeLoad = result.items.reduce((total, item) => total + (item.attributes.get(1153)?.value || 0), 0);
        }
        const character = {};
        for (const [id, attribute] of result.char.attributes) {
            const name = data.dogmaAttributes[id]?.name;
            if (name) character[name] = attribute.value;
        }
        const details = [];
        const droneTypes = new Set();
        for (const item of [result.hull, result.char, ...result.items]) {
            if (item.slot?.type === 'DroneBay') {
                if (droneTypes.has(item.type_id)) continue;
                droneTypes.add(item.type_id);
            }
            for (const entry of [item, item.charge].filter(Boolean)) {
                details.push({
                    name: entry === result.char ? 'Pilot (all skills V)' : (data.types[entry.type_id]?.name || 'Item'),
                    slot: entry.slot,
                    type_id: entry.type_id,
                    state: entry.state,
                    maxState: entry.max_state,
                    attributes: Array.from(entry.attributes, ([id, attribute]) => ({
                        name: data.dogmaAttributes[id]?.name || String(id),
                        label: data.dogmaAttributes[id]?.displayName || data.dogmaAttributes[id]?.name || String(id),
                        value: attribute.value
                    }))
                });
            }
        }
        self.postMessage({ id: request.id, stats, character, details });
    } catch (error) {
        self.postMessage({ id: request.id, error: error instanceof WebAssembly.RuntimeError ? 'This fit could not be simulated.' : error.message });
    }
};
