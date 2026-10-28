import initEngine, * as engine from '../vendor/eveshipfit/esf_dogma_engine_bg.js?v=13.1.0';
const fleetOptionsReady = import(new URL('./simulate-model.js' + new URL(import.meta.url).search, import.meta.url));

let metadataReady;
let engineReady;
const moduleProjections = new Map();
const fleetSkills = { Armor: 'Armored Command Specialist', Shield: 'Shield Command Specialist', Information: 'Information Command Specialist', Skirmish: 'Skirmish Command Specialist', Mining: 'Mining Director', Expedition: 'Expedition Command Specialist' };

function fleetFamily(type) {
    return Object.keys(fleetSkills).find(family => type.name.includes(family));
}

function mergeProjections(...projections) {
    return { buffs: projections.flatMap(projection => projection.buffs || []), effects: projections.flatMap(projection => projection.effects || []) };
}

function environmentType(type) {
    return type && ((type.groupID === 920 && type.published) || /^(darkness|electric_storm|caustic_toxin|xenon_gas|infernal)_weather_[123]$/.test(type.name));
}

async function loadMetadata() {
    const metadataResponse = await fetch(new URL('../vendor/eveshipfit/data.json.gz', import.meta.url), { cache: 'no-cache' });
    if (!metadataResponse.ok) throw new Error(metadataResponse.status === 404 ? 'Fitting data is unavailable. Please try again later.' : 'Unable to load fitting data.');
    const snapshot = await new Response(metadataResponse.body.pipeThrough(new DecompressionStream('gzip'))).json();
    return { snapshot, data: snapshot.data };
}

async function loadEngine(snapshot) {
    const assetVersion = '?v=13.1.0-' + snapshot.release;
    const [sdeResponse, wasmResponse] = await Promise.all([
        fetch(new URL('../vendor/eveshipfit/sde.dat.gz' + assetVersion, import.meta.url), { cache: 'force-cache' }),
        fetch(new URL('../vendor/eveshipfit/esf_dogma_engine_bg.wasm' + assetVersion, import.meta.url), { cache: 'force-cache' })
    ]);
    if (sdeResponse.status === 404) throw new Error('Fitting data is unavailable. Please try again later.');
    if (!sdeResponse.ok) throw new Error('Unable to load fitting data.');
    if (!wasmResponse.ok) throw new Error('Unable to load fitting engine.');
    const [sde, wasm] = await Promise.all([
        new Response(sdeResponse.body.pipeThrough(new DecompressionStream('gzip'))).arrayBuffer(),
        wasmResponse.arrayBuffer()
    ]);
    await initEngine({ module_or_path: wasm });
    if (engine.load_sde(new Uint8Array(sde)) !== snapshot.build) throw new Error('Fitting data files do not match.');
}

self.onmessage = async ({ data: request }) => {
    try {
        const { validateFleetBoostOptions } = await fleetOptionsReady;
        if (!metadataReady) metadataReady = loadMetadata().catch(error => {
            metadataReady = null;
            throw error;
        });
        const { snapshot, data } = await metadataReady;
        if (request.catalog) {
            const catalog = {};
            for (const [id, type] of Object.entries(data.types)) {
                const environmental = environmentType(type);
                if ((!environmental && (!type.published || ![6, 7, 8, 18, 20, 32, 87].includes(type.categoryID)))
                    || !data.typeDogma[id] || (environmental && !data.typeDogma[id].dogmaEffects.length)) continue;
                const dogma = data.typeDogma[id];
                const weather = type.name.match(/^(darkness|electric_storm|caustic_toxin|xenon_gas|infernal)_weather_([123])$/);
                catalog[id] = {
                    ...type, id: Number(id),
                    ...(type.categoryID === 7 && type.groupID === 1770 ? { fleetModule: true, fleetFamily: fleetFamily(type), fleetSkillName: fleetSkills[fleetFamily(type)] } : {}),
                    ...(type.categoryID === 7 && dogma.dogmaEffects.some(effect => data.dogmaEffects[effect.effectID]?.projected) ? { projectedModule: true } : {}),
                    ...(environmental ? {
                        environmental: true,
                        environmentGroup: weather ? 'Abyssal weather' : /^Class [1-6] /.test(type.name) ? 'Wormholes' : 'System effects',
                        name: weather ? 'Abyssal ' + ({ darkness: 'Dark Matter Field', electric_storm: 'Electrical Storm', caustic_toxin: 'Exotic Particle Storm', xenon_gas: 'Gamma-Ray Afterglow', infernal: 'Plasma Firestorm' }[weather[1]]) + ' — Strength ' + weather[2] : type.name
                    } : {}),
                    attributes: Object.fromEntries(dogma.dogmaAttributes.map(attribute => [data.dogmaAttributes[attribute.attributeID]?.name, attribute.value])),
                    effects: dogma.dogmaEffects.map(effect => data.dogmaEffects[effect.effectID]?.name)
                };
            }
            self.postMessage({ id: request.id, catalog });
            return;
        }
        if (!engineReady) engineReady = loadEngine(snapshot).catch(error => {
            engineReady = null;
            throw error;
        });
        await engineReady;
        const attributes = {};
        const skills = {};
        const skillTypeIDs = {};
        for (const [id, attribute] of Object.entries(data.dogmaAttributes)) attributes[attribute.name] = Number(id);
        for (const [id, type] of Object.entries(data.types)) {
            if (type.categoryID === 16) {
                skills[id] = 5;
                skillTypeIDs[type.name] = Number(id);
            }
        }
        const baseAttribute = (typeID, name) => data.typeDogma[typeID]?.dogmaAttributes.find(attribute => attribute.attributeID === attributes[name])?.value || 0;
        const fit = { ship: { type_id: request.fit.ship_type_id }, items: [], character: { skills: request.simulate && request.skillLevel === 0 ? {} : skills } };
        fit.character.security_status = Math.max(-10, Math.min(5, Number(request.fit.securityStatus) || 0));
        if (request.fit.damageProfile) {
            fit.environment = { damage_profile: Object.fromEntries(Object.entries(request.fit.damageProfile).map(([damage, value]) => [damage, value / 100])) };
        }
        if (request.fit.spaceSecurity !== undefined) {
            if (!['high_sec', 'low_sec', 'null_sec', 'wormhole'].includes(request.fit.spaceSecurity)) throw new Error('Invalid space security.');
            fit.environment = { ...fit.environment, security: request.fit.spaceSecurity };
        }
        if (request.fit.environmentEffect !== undefined) {
            const effect = request.fit.environmentEffect;
            if (!effect || !Number.isInteger(effect.type_id) || !environmentType(data.types[effect.type_id])
                || (effect.enabled !== undefined && typeof effect.enabled !== 'boolean')) throw new Error('Invalid environmental effect.');
            if (request.simulate && effect.enabled !== false) fit.incoming = engine.beacon(effect.type_id);
        }
        if (![6, 65].includes(data.types[fit.ship.type_id]?.categoryID) || !data.typeDogma[fit.ship.type_id]) {
            throw new Error('This hull is not supported by the fitting data.');
        }
        for (const implant of (request.fit.implants || []).filter(implant => implant.enabled !== false)) {
            const type = data.types[implant.type_id];
            const slot = baseAttribute(implant.type_id, 'implantness');
            if (type?.categoryID !== 20 || !data.typeDogma[implant.type_id] || !Number.isInteger(implant.slot) || implant.slot !== slot) throw new Error('Invalid implant.');
            fit.items.push({ type_id: implant.type_id, slot: { type: 'implant', index: implant.slot }, state: 'online' });
        }
        const boosterSlots = new Set();
        for (const booster of request.fit.boosters || []) {
            const slot = baseAttribute(booster.type_id, 'boosterness');
            if (data.types[booster.type_id]?.categoryID !== 20 || !Number.isInteger(slot) || slot < 1 || booster.slot !== slot || boosterSlots.has(slot)) throw new Error('Invalid or duplicate booster slot.');
            boosterSlots.add(slot);
            if (booster.enabled !== false) fit.items.push({ type_id: booster.type_id, slot: { type: 'booster', index: slot }, state: 'online' });
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
                if (request.simulate && Number.isFinite(item.spoolBonus)) {
                    const maximum = baseAttribute(item.type_id, 'damageMultiplierBonusMax') || baseAttribute(item.type_id, 'repairMultiplierBonusMax');
                    if (maximum) entry.spool = { multiplier_bonus: Math.max(0, Math.min(maximum, item.spoolBonus)) };
                }
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

        const environment = fit.incoming || {};
        const fleet = { buffs: [] };
        const projected = { effects: [] };
        const moduleKeys = new Set();
        for (const [collection, outgoingKey, incoming] of [['fleetBoosts', 'buffs', fleet], ['projectedEffects', 'effects', projected]]) {
            const modules = request.fit[collection] ?? [];
            if (!Array.isArray(modules) || modules.length > 32) throw new Error('Use at most 32 modules per effect group.');
            for (const module of modules) {
                const type = data.types[module?.type_id];
                const dogma = data.typeDogma[module?.type_id];
                if (!module || !Number.isInteger(module.type_id) || type?.categoryID !== 7 || !type.published || !dogma
                    || !Number.isInteger(module.quantity) || module.quantity < 1 || module.quantity > (collection === 'fleetBoosts' ? 1 : 20)
                    || typeof module.enabled !== 'boolean'
                    || (collection === 'fleetBoosts' ? type.groupID !== 1770 : !dogma.dogmaEffects.some(effect => data.dogmaEffects[effect.effectID]?.projected))) throw new Error('Invalid effect module.');
                if (module.charge_type_id !== undefined) {
                    const charge = data.types[module.charge_type_id];
                    if (!Number.isInteger(module.charge_type_id) || charge?.categoryID !== 8
                        || !dogma.dogmaAttributes.some(attribute => /^chargeGroup\d+$/.test(data.dogmaAttributes[attribute.attributeID]?.name) && attribute.value === charge.groupID)
                        || (baseAttribute(module.type_id, 'chargeSize') && baseAttribute(module.type_id, 'chargeSize') !== baseAttribute(module.charge_type_id, 'chargeSize'))
                        || (type.capacity && charge.volume > type.capacity + 0.000001)) throw new Error('Invalid effect module charge.');
                } else if (collection === 'fleetBoosts') throw new Error('Choose a command burst charge.');
                const family = collection === 'fleetBoosts' ? fleetFamily(type) : undefined;
                if (collection === 'fleetBoosts') validateFleetBoostOptions(module, { fleetFamily: family });
                const key = JSON.stringify([module.type_id, module.charge_type_id, fit.environment?.security, request.fit.environmentEffect,
                    collection === 'fleetBoosts' ? [module.specialistLevel ?? 5, module.shipBonus || 0, Boolean(module.mindlink), module.industrialCoreBonus || 0] : null]);
                moduleKeys.add(key);
                if (!moduleProjections.has(key)) {
                    const entry = { type_id: module.type_id, slot: { type: dogma.dogmaEffects.some(effect => data.dogmaEffects[effect.effectID]?.name === 'medPower') ? 'medium' : 'high', index: 0 }, state: 'active' };
                    if (module.charge_type_id !== undefined) entry.charge = { type_id: module.charge_type_id };
                    const sourceFit = { ship: { type_id: 0 }, character: { skills }, items: [entry], incoming: environment };
                    if (collection === 'fleetBoosts') {
                        sourceFit.character.skills = { ...skills, [skillTypeIDs[fleetSkills[family]]]: module.specialistLevel ?? 5 };
                        if (module.mindlink) {
                            const name = ({ Armor: 'Armored Command Mindlink', Mining: 'Mining Foreman Mindlink' }[family]) || family + ' Command Mindlink';
                            const mindlink = Object.entries(data.types).find(([, type]) => type.name === name);
                            if (!mindlink) throw new Error('This command mindlink is missing from the fitting data.');
                            sourceFit.items.push({ type_id: Number(mindlink[0]), slot: { type: 'implant', index: 10 }, state: 'online' });
                        }
                    }
                    const outgoing = engine.calculate(sourceFit).outgoing || {};
                    if (collection === 'fleetBoosts') {
                        const multiplier = (1 + (module.shipBonus || 0) / 100) * (1 + (module.industrialCoreBonus || 0) / 100);
                        outgoing.buffs = (outgoing.buffs || []).map(buff => ({ ...buff, value: buff.value * multiplier }));
                    }
                    moduleProjections.set(key, outgoing);
                }
                const outgoing = moduleProjections.get(key);
                if (request.simulate && module.enabled) {
                    for (let index = 0; index < module.quantity; index++) incoming[outgoingKey].push(...(outgoing[outgoingKey] || []));
                }
            }
        }
        for (const key of moduleProjections.keys()) if (!moduleKeys.has(key)) moduleProjections.delete(key);
        fit.incoming = mergeProjections(environment, fleet, projected);

        const result = engine.calculate(fit);
        const boosterAffected = [];
        if (fit.items.some(item => item.slot.type === 'booster')) {
            const withoutBoosters = engine.calculate({ ...fit, items: fit.items.filter(item => item.slot.type !== 'booster') });
            for (const [id, attribute] of result.ship.attributes) {
                const baseline = withoutBoosters.ship.attributes.get(id)?.value;
                if (Number.isFinite(baseline) && Number.isFinite(attribute.value) && Math.abs(attribute.value - baseline) > 1e-8 * Math.max(1, Math.abs(baseline))) boosterAffected.push(data.dogmaAttributes[id]?.name);
            }
        }
        const environmentAffected = [];
        const fleetAffected = [];
        const projectedAffected = [];
        for (const [active, incoming, affected] of [
            [Boolean(environment.buffs?.length || environment.effects?.length), mergeProjections(fleet, projected), environmentAffected],
            [fleet.buffs.length > 0, mergeProjections(environment, projected), fleetAffected],
            [projected.effects.length > 0, mergeProjections(environment, fleet), projectedAffected]
        ]) {
            if (!active) continue;
            const withoutEffect = engine.calculate({ ...fit, incoming });
            for (const [id, attribute] of result.ship.attributes) {
                const baseline = withoutEffect.ship.attributes.get(id)?.value ?? 0;
                if (Number.isFinite(baseline) && Number.isFinite(attribute.value) && Math.abs(attribute.value - baseline) > 1e-8 * Math.max(1, Math.abs(baseline))) affected.push(data.dogmaAttributes[id]?.name);
            }
        }
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
        self.postMessage({ id: request.id, stats, character, details, boosterAffected, environmentAffected, fleetAffected, projectedAffected });
    } catch (error) {
        self.postMessage({ id: request.id, error: error instanceof WebAssembly.RuntimeError ? 'This fit could not be simulated.' : error.message });
    }
};
