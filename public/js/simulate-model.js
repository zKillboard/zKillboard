export const racks = [
    { name: 'Low', label: 'Low slots', start: 11, attribute: 'lowSlots', effect: 'loPower' },
    { name: 'Medium', label: 'Mid slots', start: 19, attribute: 'medSlots', effect: 'medPower' },
    { name: 'High', label: 'High slots', start: 27, attribute: 'hiSlots', effect: 'hiPower' },
    { name: 'Rig', label: 'Rigs', start: 92, attribute: 'rigSlots', effect: 'rigSlot' },
    { name: 'SubSystem', label: 'Subsystems', start: 125, attribute: 'maxSubSystems', effect: 'subSystem' }
];

export function rackFor(type) {
    return racks.find(rack => type.effects.includes(rack.effect));
}

export function slotCount(rack, hull, stats, catalog) {
    if (rack.name === 'SubSystem') {
        return new Set(Object.values(catalog).filter(type => type.categoryID === 32 && type.attributes.fitsToShipType === hull.id).map(type => type.attributes.subSystemSlot)).size;
    }
    return Math.min(8, Math.max(0, Math.round(stats?.[rack.attribute] ?? hull.attributes[rack.attribute] ?? 0)));
}

export function compatible(type, hull) {
    const a = type.attributes;
    if (['High', 'Medium', 'Low'].includes(rackFor(type)?.name) && type.volume >= 3500 && !hull.attributes.isCapitalSize) return false;
    if (a.fitsToShipType && a.fitsToShipType !== hull.id) return false;
    if (a.rigSize && a.rigSize !== hull.attributes.rigSize) return false;
    const restrictions = Object.entries(a).filter(([name, value]) => value && /^canFitShip(Type|Group)/.test(name));
    return !restrictions.length || restrictions.some(([name, value]) => value === (name.includes('Group') ? hull.groupID : hull.id));
}

export function compatibleCharge(module, charge) {
    if (charge.categoryID !== 8) return false;
    const groups = Object.entries(module.attributes).filter(([name]) => /^chargeGroup\d+$/.test(name)).map(([, value]) => value);
    return groups.includes(charge.groupID)
        && (!module.attributes.chargeSize || module.attributes.chargeSize === charge.attributes.chargeSize)
        && (!module.capacity || charge.volume <= module.capacity + 0.000001);
}

export function droneBayCapacity(fit, catalog) {
    const capacity = catalog[fit.ship_type_id].attributes.droneCapacity || 0;
    return capacity + fit.items.filter(item => item.flag >= 125 && item.flag <= 132 && catalog[item.type_id].categoryID === 32)
        .reduce((sum, item) => sum + (catalog[item.type_id].attributes.droneCapacity || 0), 0);
}

export function hasDroneBay(fit, catalog) {
    return droneBayCapacity(fit, catalog) > 0;
}

export function validateDrones(fit, catalog, maxActiveDrones = fit.skillLevel === 0 ? 0 : 5) {
    const drones = fit.items.filter(item => item.flag === 87);
    if (!drones.length) return;
    const capacity = droneBayCapacity(fit, catalog);
    if (capacity <= 0) throw new Error('This ship has no drone bay.');
    const volume = drones.reduce((sum, item) => sum + catalog[item.type_id].volume * item.quantity, 0);
    if (volume > capacity + 0.001) throw new Error('Not enough space in the drone bay.');
    const active = drones.reduce((sum, item) => sum + (item.active || 0), 0);
    if (active > maxActiveDrones) throw new Error('Too many deployed drones.');
    const bandwidth = (catalog[fit.ship_type_id].attributes.droneBandwidth || 0) + fit.items
        .filter(item => item.flag >= 125 && item.flag <= 132 && catalog[item.type_id].categoryID === 32)
        .reduce((sum, item) => sum + (catalog[item.type_id].attributes.droneBandwidth || 0), 0);
    const used = drones.reduce((sum, item) => sum + (catalog[item.type_id].attributes.droneBandwidthUsed || 0) * (item.active || 0), 0);
    if (used > bandwidth) throw new Error('Not enough drone bandwidth.');
}

function validateSlots(items, hull, catalog) {
    const fitted = items.filter(item => ![5, 87].includes(item.flag) && catalog[item.type_id].categoryID !== 8);
    const subsystems = fitted.filter(item => catalog[item.type_id].categoryID === 32);
    for (const [effect, attribute, modifier, label] of [
        ['launcherFitted', 'launcherSlotsLeft', 'launcherHardPointModifier', 'launcher'],
        ['turretFitted', 'turretSlotsLeft', 'turretHardPointModifier', 'turret']
    ]) {
        const capacity = (hull.attributes[attribute] || 0) + subsystems.reduce((sum, item) => sum + (catalog[item.type_id].attributes[modifier] || 0), 0);
        if (fitted.filter(item => catalog[item.type_id].effects.includes(effect)).length > capacity) {
            throw new Error('No available ' + label + ' hardpoints.');
        }
    }
    for (const rack of racks) {
        const modifier = { High: 'hiSlotModifier', Medium: 'medSlotModifier', Low: 'lowSlotModifier' }[rack.name];
        const capacity = slotCount(rack, hull, null, catalog) + subsystems.reduce((sum, item) => sum + (catalog[item.type_id].attributes[modifier] || 0), 0);
        if (fitted.some(item => item.flag >= rack.start && item.flag < rack.start + 8 && item.flag >= rack.start + capacity)) {
            throw new Error('Too many modules in ' + rack.label.toLowerCase() + '.');
        }
    }
}

export function addModule(fit, type, catalog, stats, flag) {
    const hull = catalog[fit.ship_type_id];
    const rack = rackFor(type);
    if (!rack || !compatible(type, hull)) throw new Error('This module cannot be fitted to this hull.');
    const modules = fit.items.filter(item => catalog[item.type_id].categoryID !== 8 && item.flag >= rack.start && item.flag < rack.start + 8);
    const count = slotCount(rack, hull, stats, catalog);
    if (rack.name === 'SubSystem') flag = type.attributes.subSystemSlot;
    if (flag == null) {
        flag = Array.from({ length: count }, (_, i) => rack.start + i).find(slot => !modules.some(item => item.flag === slot));
    }
    if (!Number.isInteger(flag) || flag < rack.start || flag >= rack.start + count) throw new Error('No available ' + rack.label.toLowerCase() + '.');
    const others = fit.items.filter(item => item.flag !== flag && item.flag !== 5 && item.flag !== 87 && catalog[item.type_id].categoryID !== 8);
    for (const [attribute, field] of [['maxGroupFitted', 'groupID'], ['maxTypeFitted', 'id']]) {
        if (type.attributes[attribute] && others.filter(item => catalog[item.type_id][field] === type[field]).length >= type.attributes[attribute]) {
            throw new Error('The fitting limit for this module has been reached.');
        }
    }
    const items = fit.items.filter(item => item.flag !== flag);
    items.push({ type_id: type.id, flag, quantity: 1, state: 'Active' });
    validateSlots(items, hull, catalog);
    fit.items = items;
}

export function exportEFT(fit, catalog) {
    const lines = ['[' + catalog[fit.ship_type_id].name + ', ' + fit.name.replace(/[\r\n\]]/g, ' ') + ']'];
    for (const rack of racks) {
        const modules = fit.items.filter(item => item.flag >= rack.start && item.flag < rack.start + 8 && catalog[item.type_id].categoryID !== 8).sort((a, b) => a.flag - b.flag);
        let next = rack.start;
        for (const item of modules) {
            while (next++ < item.flag) lines.push('[Empty ' + (rack.name === 'Medium' ? 'Med' : rack.name) + ' slot]');
            const charge = fit.items.find(other => other.flag === item.flag && catalog[other.type_id].categoryID === 8);
            lines.push(catalog[item.type_id].name + (charge ? ', ' + catalog[charge.type_id].name : ''));
        }
        lines.push('');
    }
    for (const flag of [87, 5]) {
        for (const item of fit.items.filter(item => item.flag === flag)) lines.push(catalog[item.type_id].name + ' x' + item.quantity);
        lines.push('');
    }
    return lines.join('\n').trim();
}

export function importEFT(text, catalog) {
    if (text.length > 50000) throw new Error('This EFT fit is too large.');
    const lines = text.trim().split(/\r?\n/);
    const header = lines.shift()?.match(/^\[([^,]+),\s*(.*)\]$/);
    if (!header) throw new Error('Start the EFT fit with [Ship, Fit name].');
    const names = new Map(Object.values(catalog).map(type => [type.name.toLowerCase(), type]));
    const hull = names.get(header[1].trim().toLowerCase());
    if (hull?.categoryID !== 6) throw new Error('Unknown ship: ' + header[1]);
    const fit = { ship_type_id: hull.id, name: header[2].trim() || 'Imported fit', items: [] };
    const positions = Object.fromEntries(racks.map(rack => [rack.name, rack.start]));
    for (const raw of lines) {
        const line = raw.trim();
        if (!line) continue;
        const empty = line.match(/^\[Empty (Low|Med|Medium|High|Rig|SubSystem) slot\]$/i);
        if (empty) {
            const rack = racks.find(rack => rack.name.toLowerCase() === empty[1].toLowerCase() || (rack.name === 'Medium' && empty[1].toLowerCase() === 'med'));
            positions[rack.name]++;
            continue;
        }
        const parts = line.replace(/\s+\/offline$/i, '').split(',').map(part => part.trim());
        const stack = parts[0].match(/^(.*?) x(\d+)$/);
        const type = names.get((stack ? stack[1] : parts[0]).toLowerCase());
        if (!type) throw new Error('Unknown item: ' + parts[0]);
        const quantity = stack ? Number(stack[2]) : 1;
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > (type.categoryID === 18 ? 1000 : 1000000)) throw new Error('Item quantities are out of range.');
        if (type.categoryID === 18 || stack || type.categoryID === 8) {
            if (parts.length !== 1) throw new Error('Unexpected charge on a cargo or drone entry.');
            const flag = type.categoryID === 18 ? 87 : 5;
            const existing = fit.items.find(item => item.flag === flag && item.type_id === type.id);
            if (existing) existing.quantity += quantity;
            else fit.items.push({ type_id: type.id, flag, quantity, active: 0 });
            continue;
        }
        const rack = rackFor(type);
        if (!rack || !compatible(type, hull)) throw new Error('Incompatible module: ' + type.name);
        const flag = rack.name === 'SubSystem' ? type.attributes.subSystemSlot : positions[rack.name]++;
        if (!Number.isInteger(flag) || flag < rack.start || flag >= rack.start + 8 || fit.items.some(item => item.flag === flag)) throw new Error('Too many modules or duplicate subsystem: ' + type.name);
        fit.items.push({ type_id: type.id, flag, quantity: 1, state: /\s\/offline$/i.test(line) ? 'Passive' : 'Active' });
        if (parts.length > 2) throw new Error('Unexpected EFT fields: ' + line);
        if (parts[1]) {
            const charge = names.get(parts[1].toLowerCase());
            if (!charge || !compatibleCharge(type, charge)) throw new Error('Incompatible charge: ' + parts[1]);
            fit.items.push({ type_id: charge.id, flag, quantity: 1 });
        }
    }
    if (fit.items.length > 300 || fit.items.filter(item => item.flag === 87).reduce((sum, item) => sum + item.quantity, 0) > 1000) throw new Error('This fit contains too many items.');
    validateSlots(fit.items, hull, catalog);
    validateDrones(fit, catalog);
    return fit;
}

export function warnings(fit, catalog, stats, character) {
    const messages = [];
    for (const [load, output, label] of [['cpuLoad', 'cpuOutput', 'CPU'], ['powerLoad', 'powerOutput', 'Powergrid'], ['upgradeLoad', 'upgradeCapacity', 'Calibration'], ['droneCapacityLoad', 'droneCapacity', 'Drone bay'], ['droneBandwidthLoad', 'droneBandwidth', 'Drone bandwidth']]) {
        if (stats[load] > stats[output] + 0.001) messages.push(label + ' capacity exceeded.');
    }
    for (const key of ['turretSlotsLeft', 'launcherSlotsLeft']) if (stats[key] < 0) messages.push('Not enough ' + (key === 'turretSlotsLeft' ? 'turret' : 'launcher') + ' hardpoints.');
    const hull = catalog[fit.ship_type_id];
    for (const rack of racks) {
        if (fit.items.some(item => item.flag >= rack.start + slotCount(rack, hull, stats, catalog) && item.flag < rack.start + 8)) messages.push('Too many modules in ' + rack.label.toLowerCase() + '.');
    }
    const active = fit.items.filter(item => item.flag === 87).reduce((sum, item) => sum + (item.active || 0), 0);
    if (active > (character.maxActiveDrones || 0)) messages.push('Too many deployed drones.');
    const cargo = fit.items.filter(item => item.flag === 5).reduce((sum, item) => sum + catalog[item.type_id].volume * item.quantity, 0);
    if (cargo > stats.capacity) messages.push('Cargo capacity exceeded.');
    for (const item of fit.items.filter(item => ![5, 87].includes(item.flag) && catalog[item.type_id].categoryID !== 8)) {
        const type = catalog[item.type_id];
        for (const [attribute, field] of [['maxGroupFitted', 'groupID'], ['maxTypeFitted', 'id'], ['maxGroupActive', 'groupID'], ['maxGroupOnline', 'groupID']]) {
            const count = fit.items.filter(other => ![5, 87].includes(other.flag) && catalog[other.type_id][field] === type[field]
                && (!attribute.endsWith('Active') || ['Active', 'Overload'].includes(other.state))
                && (!attribute.endsWith('Online') || other.state !== 'Passive')).length;
            if (type.attributes[attribute] && count > type.attributes[attribute]) messages.push(type.name + ': ' + attribute + ' limit exceeded.');
        }
    }
    return [...new Set(messages)];
}

export function exportESIFit(fit, catalog) {
    const items = new Map();
    for (const item of fit.items) {
        let flag = item.flag === 87 ? 'DroneBay' : 'Cargo';
        if (catalog[item.type_id].categoryID !== 8) {
            const rack = racks.find(rack => item.flag >= rack.start && item.flag < rack.start + 8);
            if (rack) flag = { High: 'HiSlot', Medium: 'MedSlot', Low: 'LoSlot', Rig: 'RigSlot', SubSystem: 'SubSystemSlot' }[rack.name] + (item.flag - rack.start);
        }
        const key = flag + ':' + item.type_id;
        if (items.has(key)) items.get(key).quantity += item.quantity;
        else items.set(key, { flag, type_id: item.type_id, quantity: item.quantity });
    }
    return { name: fit.name.slice(0, 50), description: 'Saved from https://zkillboard.com/simulate/', ship_type_id: fit.ship_type_id, items: [...items.values()] };
}
