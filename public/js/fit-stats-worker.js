import * as engine from '../vendor/eveshipfit/esf_dogma_engine_bg.js?v=10.4.0';

let ready;

async function loadData() {
    const assetVersion = '?v=10.4.0-3.3503375.1';
    const [metadataResponse, sdeResponse, wasmResponse] = await Promise.all([
        fetch(new URL('../vendor/eveshipfit/data.json.gz' + assetVersion, import.meta.url), { cache: 'force-cache' }),
        fetch(new URL('../vendor/eveshipfit/sde.dat.gz' + assetVersion, import.meta.url), { cache: 'force-cache' }),
        fetch(new URL('../vendor/eveshipfit/esf_dogma_engine_bg.wasm' + assetVersion, import.meta.url), { cache: 'force-cache' })
    ]);
    if (metadataResponse.status === 404 || sdeResponse.status === 404) throw new Error('Fitting data is unavailable. Please try again later.');
    if (!metadataResponse.ok || !sdeResponse.ok) throw new Error('Unable to load fitting data.');
    if (!wasmResponse.ok) throw new Error('Unable to load fitting engine.');
    const [snapshot, sde, wasm] = await Promise.all([
        new Response(metadataResponse.body.pipeThrough(new DecompressionStream('gzip'))).json(),
        new Response(sdeResponse.body.pipeThrough(new DecompressionStream('gzip'))).arrayBuffer(),
        wasmResponse.arrayBuffer()
    ]);
    const data = snapshot.data;

    const attributes = {};
    const skills = {};
    for (const [id, attribute] of Object.entries(data.dogmaAttributes)) attributes[attribute.name] = Number(id);
    for (const [id, type] of Object.entries(data.types)) {
        if (type.categoryID === 16) skills[id] = 5;
    }

    const { instance } = await WebAssembly.instantiate(wasm, {
        './esf_dogma_engine_bg.js': engine
    });
    engine.__wbg_set_wasm(instance.exports);
    instance.exports.__wbindgen_start();
    engine.init();
    if (engine.load_sde(new Uint8Array(sde)) !== snapshot.build) throw new Error('Fitting data files do not match.');
    return { data, attributes, skills };
}

self.onmessage = async ({ data: request }) => {
    try {
        if (!ready) ready = loadData().catch(error => {
            ready = null;
            throw error;
        });
        const { data, attributes, skills } = await ready;
        if (request.catalog) {
            const catalog = {};
            for (const [id, type] of Object.entries(data.types)) {
                if (!type.published || ![6, 7, 8, 18, 32, 87].includes(type.categoryID) || !data.typeDogma[id]) continue;
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
        const baseAttribute = (typeID, name) => data.typeDogma[typeID]?.dogmaAttributes.find(attribute => attribute.attributeID === attributes[name])?.value || 0;
        const fit = { ship: { type_id: request.fit.ship_type_id }, items: [], character: { skills: request.simulate && request.skillLevel === 0 ? {} : skills } };
        if (![6, 65].includes(data.types[fit.ship.type_id]?.categoryID) || !data.typeDogma[fit.ship.type_id]) {
            throw new Error('This hull is not supported by the fitting data.');
        }

        if (request.simulate && data.types[fit.ship.type_id].groupID === 1305) {
            const mode = request.fit.mode || 'Defense';
            if (!['Defense', 'Propulsion', 'Sharpshooter'].includes(mode)) throw new Error('Invalid tactical destroyer mode.');
            const modeType = Object.entries(data.types).find(([, type]) => type.groupID === 1306 && type.name === data.types[fit.ship.type_id].name + ' ' + mode + ' Mode');
            if (!modeType) throw new Error('Tactical destroyer mode data is unavailable.');
            fit.ship.mode = Number(modeType[0]);
        }

        const slots = new Map();
        const fighterBay = new Map();
        const fighterTubes = new Set();
        for (const item of request.fit.items) {
            if (item.flag === 87) {
                if (data.types[item.type_id]?.categoryID !== 18 || !data.typeDogma[item.type_id]) {
                    throw new Error('This fit contains an unsupported drone.');
                }
                if (!Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > 1000) throw new Error('Invalid drone quantity.');
                const active = request.simulate ? Math.min(item.quantity, item.active || 0) : 0;
                if (active) fit.items.push({ type_id: item.type_id, quantity: active, slot: { type: 'drone_bay' }, state: 'active' });
                if (active < item.quantity) fit.items.push({ type_id: item.type_id, quantity: item.quantity - active, slot: { type: 'drone_bay' }, state: 'offline' });
            }
            if (item.flag === 158 || (item.flag >= 159 && item.flag <= 163)) {
                if (data.types[item.type_id]?.categoryID !== 87 || !data.typeDogma[item.type_id]) {
                    throw new Error('This fit contains an unsupported fighter.');
                }
                if (!Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > 1000) throw new Error('Invalid fighter quantity.');
                if (item.flag === 158) {
                    const fighter = fighterBay.get(item.type_id) || { quantity: 0, active: request.simulate && Number.isInteger(item.active) ? 0 : undefined };
                    fighter.quantity += item.quantity;
                    if (fighter.active !== undefined && Number.isInteger(item.active)) fighter.active += item.active;
                    else fighter.active = undefined;
                    fighterBay.set(item.type_id, fighter);
                } else {
                    if (fighterTubes.has(item.flag)) throw new Error('Multiple fighter squadrons were recorded in one tube.');
                    fighterTubes.add(item.flag);
                    fit.items.push({ type_id: item.type_id, quantity: item.quantity, slot: { type: 'fighter_tube', index: item.flag - 159 }, state: 'active' });
                }
                continue;
            }
            let slot;
            for (const [start, end, type] of [[11, 18, 'low'], [19, 26, 'medium'], [27, 34, 'high'], [92, 99, 'rig'], [125, 132, 'subsystem'], [164, 171, 'service']]) {
                if (item.flag >= start && item.flag <= end) slot = { type, index: item.flag - start };
            }
            if (!slot) continue;
            const type = data.types[item.type_id];
            if (!type || !data.typeDogma[item.type_id]) throw new Error('This fit contains an item missing from the fitting data.');
            if (!slots.has(item.flag)) slots.set(item.flag, { slot });
            const entry = slots.get(item.flag);
            if (type.categoryID === 8) {
                if (entry.charge) throw new Error('Multiple charges were recorded in one slot.');
                entry.charge = { type_id: item.type_id };
            } else if ([7, 32, 66].includes(type.categoryID)) {
                if (entry.type_id || item.quantity !== 1) throw new Error('Multiple modules were recorded in one slot.');
                entry.type_id = item.type_id;
                entry.state = request.simulate && ['Passive', 'Online', 'Active', 'Overload'].includes(item.state)
                    ? { Passive: 'offline', Online: 'online', Active: 'active', Overload: 'overload' }[item.state]
                    : 'active';
            } else {
                throw new Error('This fit contains an unsupported fitted item.');
            }
        }
        if (request.simulate && Array.isArray(request.fit.fighterTubes)) {
            for (let index = 0; index < request.fit.fighterTubes.length; index++) {
                const tube = request.fit.fighterTubes[index];
                if (!tube) continue;
                const fighter = fighterBay.get(tube.type_id);
                const maximum = baseAttribute(tube.type_id, 'fighterSquadronMaxSize');
                if (!fighter || !Number.isInteger(tube.quantity) || tube.quantity < 1 || tube.quantity > maximum || tube.quantity > fighter.quantity) throw new Error('Invalid fighter tube assignment.');
                if (index >= baseAttribute(fit.ship.type_id, 'fighterTubes')) throw new Error('Too many fighter squadrons deployed.');
                fit.items.push({ type_id: tube.type_id, quantity: tube.quantity, slot: { type: 'fighter_tube', index }, state: 'active' });
                fighterTubes.add(159 + index);
                fighter.quantity -= tube.quantity;
                fighter.active = 0;
            }
        }
        if (!fighterTubes.size && fighterBay.size) {
            const limits = {
                light: baseAttribute(fit.ship.type_id, 'fighterLightSlots'),
                support: baseAttribute(fit.ship.type_id, 'fighterSupportSlots'),
                heavy: baseAttribute(fit.ship.type_id, 'fighterHeavySlots')
            };
            const used = { light: 0, support: 0, heavy: 0 };
            const tubes = baseAttribute(fit.ship.type_id, 'fighterTubes');
            while (fighterTubes.size < tubes) {
                let launched = false;
                for (const [typeID, fighter] of fighterBay) {
                    if (!fighter.quantity || fighter.active === 0) continue;
                    const role = [['light', 'fighterSquadronIsLight'], ['support', 'fighterSquadronIsSupport'], ['heavy', 'fighterSquadronIsHeavy']].find(([, attribute]) => baseAttribute(typeID, attribute));
                    if (!role || used[role[0]] >= limits[role[0]]) continue;
                    const quantity = baseAttribute(typeID, 'fighterSquadronMaxSize') || fighter.quantity;
                    if (fighter.quantity < quantity) continue;
                    fit.items.push({ type_id: typeID, quantity, slot: { type: 'fighter_tube', index: fighterTubes.size }, state: 'active' });
                    fighterTubes.add(159 + fighterTubes.size);
                    fighter.quantity -= quantity;
                    if (fighter.active !== undefined) fighter.active--;
                    used[role[0]]++;
                    launched = true;
                    if (fighterTubes.size >= tubes) break;
                }
                if (!launched) break;
            }
        }
        if ([...fighterBay.values()].some(fighter => fighter.active > 0)) throw new Error('Too many fighter squadrons deployed.');
        for (const [typeID, fighter] of fighterBay) {
            if (fighter.quantity) fit.items.push({ type_id: typeID, quantity: fighter.quantity, slot: { type: 'fighter_bay' }, state: 'offline' });
        }
        for (const entry of slots.values()) {
            if (!entry.type_id) throw new Error('A loaded charge has no recorded module.');
            fit.items.push(entry);
        }

        const result = engine.calculate(fit);
        const stats = {};
        for (const [id, attribute] of result.ship.attributes) {
            const name = data.dogmaAttributes[id]?.name;
            if (name) stats[name] = attribute.value;
        }
        for (const [target, source] of [['turretSlotsLeft', 'turretHardPointModifier'], ['launcherSlotsLeft', 'launcherHardPointModifier'], ['hiSlots', 'hiSlotModifier'], ['medSlots', 'medSlotModifier'], ['lowSlots', 'lowSlotModifier']]) {
            stats[target] = baseAttribute(fit.ship.type_id, target) + baseAttribute(fit.ship.mode, source) + fit.items.reduce((total, item) => total + baseAttribute(item.type_id, source) * (item.quantity || 1), 0);
        }
        const fighters = fit.items.filter(item => ['fighter_bay', 'fighter_tube'].includes(item.slot.type));
        stats.fighterCapacityLoad = fighters.filter(item => item.slot.type === 'fighter_bay').reduce((total, item) => total + (data.types[item.type_id]?.volume || 0) * item.quantity, 0);
        stats.fighterCraftInBay = fighters.filter(item => item.slot.type === 'fighter_bay').reduce((total, item) => total + item.quantity, 0);
        stats.fighterCraftDeployed = fighters.filter(item => item.slot.type === 'fighter_tube').reduce((total, item) => total + item.quantity, 0);
        if (!Number.isFinite(stats.fighterTubes)) stats.fighterTubes = baseAttribute(fit.ship.type_id, 'fighterTubes');
        for (const [role, attribute] of [['Light', 'fighterSquadronIsLight'], ['Support', 'fighterSquadronIsSupport'], ['Heavy', 'fighterSquadronIsHeavy']]) {
            stats['fighter' + role + 'Slots'] = baseAttribute(fit.ship.type_id, 'fighter' + role + 'Slots');
            stats['fighter' + role + 'SlotsUsed'] = fighters.filter(item => item.slot.type === 'fighter_tube' && baseAttribute(item.type_id, attribute)).length;
        }
        if (!Number.isFinite(stats.upgradeLoad)) {
            stats.upgradeLoad = result.items.reduce((total, item) => total + (item.attributes.get(1153)?.value || 0), 0);
        }
        const character = {};
        for (const [id, attribute] of result.character.attributes) {
            const name = data.dogmaAttributes[id]?.name;
            if (name) character[name] = attribute.value;
        }
        const calculatedItems = result.items.map((item, index) => ({
            ...item,
            type_id: fit.items[index].type_id,
            slot: fit.items[index].slot,
            charge: item.charge && { ...item.charge, type_id: fit.items[index].charge.type_id }
        }));
        const calculatedMode = result.mode && { ...result.mode, type_id: fit.ship.mode };
        const details = [];
        const droneTypes = new Set();
        for (const item of [{ ...result.ship, type_id: fit.ship.type_id }, calculatedMode, result.character, ...calculatedItems].filter(Boolean)) {
            if (item.slot?.type === 'drone_bay') {
                if (droneTypes.has(item.type_id)) continue;
                droneTypes.add(item.type_id);
            }
            for (const entry of [item, item.charge].filter(Boolean)) {
                const slot = entry.slot && {
                    type: { high: 'High', medium: 'Medium', low: 'Low', rig: 'Rig', subsystem: 'SubSystem', service: 'Service', fighter_tube: 'FighterTube', fighter_bay: 'FighterBay', drone_bay: 'DroneBay', cargo: 'Cargo' }[entry.slot.type],
                    ...('index' in entry.slot ? { index: entry.slot.index + 1 } : {})
                };
                const state = { offline: 'Passive', online: 'Online', active: 'Active', overload: 'Overload' };
                details.push({
                    name: entry === result.character ? 'Pilot (all skills V)' : (data.types[entry.type_id]?.name || 'Item'),
                    slot,
                    ...((slot && entry === item) ? { quantity: item.quantity || 1 } : {}),
                    type_id: entry.type_id,
                    state: state[entry.state] || entry.state,
                    maxState: state[entry.max_state] || entry.max_state,
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
