const { racks, rackFor, slotCount, compatible, compatibleCharge, hasDroneBay, hasFighterBay, validateDrones, validateFighters, addModule, importEFT, importTypeIDFit, exportEFT, exportESIFit, warnings } = await import(new URL('./simulate-model.js' + new URL(import.meta.url).search, import.meta.url));

let cleanup;

window.zkbInitSimulate = function() {
    const root = document.getElementById('simulate');
    if (!root || root.dataset.initialized) return;
    if (cleanup) cleanup();
    root.dataset.initialized = 'true';
    const element = name => root.querySelector('#simulate-' + name);
    let worker;
    let shipSearch;
    let catalog;
    let types;
    let fit;
    let stats;
    let pilotStats;
    let calculatedItems = [];
    let selected;
    let equipmentModule;
    let dragType;
    let dragFromFit = false;
    const dropTargets = new Map();
    const fitHistory = [];
    let historyIndex = -1;
    let sequence = 0;
    let timer;
    let calculationPending = false;
    let queuedCalculation;
    let alive = true;
    const events = new AbortController();
    const fittingImages = new Map();
    const prices = new Map();
    let priceRequest;
    const expandedSections = new Set(['Capacitor', 'Pricing']);
    const wheel = element('Fitting_Panel');
    let savedUI;
    try { savedUI = JSON.parse(localStorage.getItem('zkb:simulate-ui')); } catch (error) { /* Invalid or unavailable storage. */ }
    let equipmentSort = savedUI?.sort === 'Alpha' ? 'Alpha' : 'Meta';
    let restoring = true;
    const equipmentGroups = new Map();
    const revealedCharges = new Map();
    function saveState() {
        if (restoring || !alive) return;
        try {
            localStorage.setItem('zkb:simulate-ui', JSON.stringify({
                search: element('search').value, category: element('category').value, sort: equipmentSort,
                ship: element('ship').value, name: element('name').value,
                eft: element('eft-modal-text').value,
                expanded: [...expandedSections], groups: [...equipmentGroups], charges: [...revealedCharges],
                selected, equipmentModule: equipmentModule?.id, history: fitHistory, historyIndex,
                resultsScroll: element('results').scrollTop, pageScroll: window.scrollY || 0
            }));
        } catch (error) { /* Storage may be disabled or full. */ }
    }
    for (const event of ['input', 'change', 'click', 'toggle', 'scroll', 'drop']) {
        root.addEventListener(event, () => queueMicrotask(saveState), { capture: true, signal: events.signal });
    }
    globalThis.addEventListener?.('pagehide', saveState, { signal: events.signal });

    cleanup = function() {
        if (!alive) return;
        saveState();
        alive = false;
        clearTimeout(timer);
        calculationPending = false;
        queuedCalculation = null;
        worker?.terminate();
        events.abort();
        if (shipSearch) {
            const autocomplete = shipSearch.data('zz_search');
            if (autocomplete) {
                clearTimeout(autocomplete.data.throttle);
                autocomplete.data.menu.remove();
            }
            shipSearch.off().removeData('zz_search');
        }
        delete root.dataset.initialized;
    };
    window.zkbPageCleanup = cleanup;

    function fighterItemsForTubes(items, tubes) {
        return items.map(item => item.flag === 158 ? { ...item, active: tubes.filter(tube => tube?.type_id === item.type_id).length } : item);
    }

    function deployedFighters(typeID, tubes = fit.fighterTubes || []) {
        return tubes.filter(tube => tube?.type_id === typeID).reduce((total, tube) => total + tube.quantity, 0);
    }

    function removeItem(item) {
        const wholeSlot = ![5, 87, 158].includes(item.flag) && catalog[item.type_id].categoryID !== 8;
        fit.items = fit.items.filter(other => wholeSlot ? other.flag !== item.flag : other !== item);
        if (item.flag === 158) {
            fit.fighterTubes = (fit.fighterTubes || []).map(tube => tube?.type_id === item.type_id ? null : tube);
            fit.items = fighterItemsForTubes(fit.items, fit.fighterTubes);
        }
        selected = null;
        changed();
    }

    function highlightDropTargets(type, fromFit = false) {
        dragType = type;
        dragFromFit = fromFit;
        element('trash').style.filter = type && fromFit ? 'drop-shadow(0 0 4px #0dcaf0)' : '';
        for (const [target, accepts] of dropTargets) {
            target.style.filter = type && accepts(type) ? 'drop-shadow(0 0 4px #0dcaf0)' : '';
            if (target.dataset.bay) target.style.borderColor = type && accepts(type) ? '#0dcaf0' : 'transparent';
        }
    }
    root.addEventListener('dragend', () => highlightDropTargets(null), { capture: true, signal: events.signal });
    root.addEventListener('drop', () => queueMicrotask(() => highlightDropTargets(null)), { capture: true, signal: events.signal });

    function removalDrag(target, item) {
        target.draggable = true;
        if (target.zkbRemovalDrag) target.removeEventListener('dragstart', target.zkbRemovalDrag);
        target.zkbRemovalDrag = event => {
            event.stopPropagation();
            event.dataTransfer.setData('application/x-zkb-fitted-item', JSON.stringify({ flag: item.flag, type_id: item.type_id }));
            event.dataTransfer.effectAllowed = catalog[item.type_id].categoryID === 8 ? 'copyMove' : 'move';
            highlightDropTargets(catalog[item.type_id], true);
        };
        target.addEventListener('dragstart', target.zkbRemovalDrag);
    }

    element('trash').addEventListener('dragover', event => {
        if (!Array.from(event.dataTransfer.types).includes('application/x-zkb-fitted-item')) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }, { signal: events.signal });
    element('trash').addEventListener('drop', event => {
        event.preventDefault();
        const payload = event.dataTransfer.getData('application/x-zkb-fitted-item');
        if (!payload || !fit) return;
        try {
            const dropped = JSON.parse(payload);
            const item = fit.items.find(item => item.flag === dropped.flag && item.type_id === dropped.type_id);
            if (item) removeItem(item);
        } catch (error) { showStatus('Unable to remove the dragged item.'); }
    }, { signal: events.signal });

    element('equipment').addEventListener('dragover', event => {
        if (!Array.from(event.dataTransfer.types).includes('application/x-zkb-fitted-item')) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }, { signal: events.signal });
    element('equipment').addEventListener('drop', event => {
        const payload = event.dataTransfer.getData('application/x-zkb-fitted-item');
        if (!payload || !fit) return;
        event.preventDefault();
        try {
            const dropped = JSON.parse(payload);
            const item = fit.items.find(item => item.flag === dropped.flag && item.type_id === dropped.type_id);
            if (!item || !catalog[item.type_id]) return;
            const rack = rackFor(catalog[item.type_id]);
            selected = rack && ![5, 87].includes(item.flag) ? { rack: rack.name, flag: item.flag } : null;
            equipmentModule = catalog[item.type_id];
            element('search').value = equipmentModule.name;
            element('category').value = 'All';
            search();
        } catch (error) { showStatus('Unable to browse the dragged item.'); }
    }, { signal: events.signal });

    function showStatus(message) {
        element('status').textContent = message;
        window.showToast(message, 5000);
    }

    function node(tag, className, text) {
        const result = document.createElement(tag);
        result.className = className;
        if (text != null) result.textContent = text;
        return result;
    }

    function fittingImage(key, className) {
        if (!fittingImages.has(key)) fittingImages.set(key, node('img', className));
        return fittingImages.get(key);
    }

    function button(text, action, className = 'btn btn-sm btn-outline-secondary') {
        const result = node('button', className, text);
        result.type = 'button';
        result.addEventListener('click', () => {
            try { action(); } catch (error) { showStatus(error.message); }
        });
        return result;
    }

    function select(options, value, label, action) {
        const result = node('select', 'form-select form-select-sm w-auto');
        result.setAttribute('aria-label', label);
        options.forEach(([id, name]) => result.add(new Option(name, id)));
        result.value = value;
        result.addEventListener('change', () => action(result.value));
        return result;
    }

    function moduleStates(maxState) {
        if (maxState === 'Passive') return [['Passive', 'Passive']];
        const states = [[maxState === 'Online' ? 'Online' : 'Active', 'Online']];
        if (maxState === 'Overload') states.push(['Overload', 'Overheated']);
        states.push(['Passive', 'Offline']);
        return states;
    }

    function setCharge(flag, typeID) {
        const module = fit.items.find(item => item.flag === flag && catalog[item.type_id].categoryID !== 8);
        if (typeID && (!module || !compatibleCharge(catalog[module.type_id], catalog[typeID]))) throw new Error('This charge does not fit that module.');
        fit.items = fit.items.filter(item => item.flag !== flag || catalog[item.type_id].categoryID !== 8);
        if (typeID) fit.items.push({ type_id: typeID, flag, quantity: 1 });
    }

    function addCargoCharge(type, minimum = 1) {
        const reload = fit.items.filter(item => ![5, 87].includes(item.flag) && rackFor(catalog[item.type_id]) && compatibleCharge(catalog[item.type_id], type)).reduce((total, item) => {
            const capacity = catalog[item.type_id].capacity || 0;
            return total + Math.max(1, type.volume > 0 ? Math.floor((capacity + 0.000001) / type.volume) : 1);
        }, 0);
        const existing = fit.items.find(item => item.flag === 5 && item.type_id === type.id);
        const quantity = Math.max((existing?.quantity || 0) + minimum, reload);
        if (quantity > 1000000) throw new Error('Maximum quantity reached.');
        if (existing) existing.quantity = quantity;
        else fit.items.push({ type_id: type.id, flag: 5, quantity });
    }

    function addToBay(type, flag) {
        if (!fit) throw new Error('Choose a ship first.');
        if (!type || (flag === 87 && type.categoryID !== 18) || (flag === 158 && type.categoryID !== 87)) throw new Error('This item cannot go in that bay.');
        if (flag === 87) validateDrones({ ...fit, items: [...fit.items, { type_id: type.id, flag, quantity: 1 }] }, catalog, pilotStats?.maxActiveDrones);
        if (flag === 158) validateFighters({ ...fit, items: [...fit.items, { type_id: type.id, flag, quantity: 1 }] }, catalog, stats?.fighterCapacity);
        const existing = fit.items.find(item => item.type_id === type.id && item.flag === flag);
        if (existing) {
            if (existing.quantity >= ([87, 158].includes(flag) ? 1000 : 1000000)) throw new Error('Maximum quantity reached.');
            existing.quantity++;
        } else fit.items.push({ type_id: type.id, flag, quantity: 1, active: 0 });
    }

    function metaLabel(type) {
        const group = type.metaGroupID || 1;
        return ({ 1: 'Tech I', 2: 'Tech II', 3: 'Storyline', 4: 'Faction', 5: 'Officer', 6: 'Deadspace', 14: 'Tech III', 15: 'Abyssal', 17: 'Premium', 19: 'Limited Time', 54: 'Structure Tech I' }[group] || 'Meta group ' + group) + ' · Meta ' + (type.attributes.metaLevelOld || 0);
    }

    function compareEquipment(a, b) {
        if (equipmentSort === 'Alpha') return a.name.localeCompare(b.name);
        return (a.attributes.metaLevelOld || 0) - (b.attributes.metaLevelOld || 0)
            || (a.metaGroupID || 1) - (b.metaGroupID || 1)
            || a.name.localeCompare(b.name);
    }

    function search() {
        element('sort').textContent = equipmentSort;
        element('sort').setAttribute('aria-label', 'Equipment sort: ' + equipmentSort);
        const category = element('category').value;
        const query = element('search').value.trim().toLowerCase();
        const hull = catalog[fit?.ship_type_id];
        const groups = [
            ['High', 'High slots'], ['Medium', 'Mid slots'], ['Low', 'Low slots'],
            ['Rig', 'Rigs'], ['SubSystem', 'Subsystems'], ['Drone', 'Drones'], ['Fighter', 'Fighters'], ['Cargo', 'Cargo']
        ];
        const matches = types.filter(type => type.categoryID !== 6 && (category !== 'Fighter' || type.categoryID === 87)).map(type => ({
            type, category: category === 'Cargo' ? 'Cargo' : (rackFor(type)?.name || (type.categoryID === 18 ? 'Drone' : type.categoryID === 87 ? 'Fighter' : 'Cargo'))
        })).filter(match => (category === 'All' || match.category === category)
            && (match.type.categoryID !== 32 || (hull && hull.attributes.maxSubSystems > 0 && compatible(match.type, hull)))
            && (!hull || ['Drone', 'Fighter', 'Cargo'].includes(match.category) || compatible(match.type, hull))
            && (equipmentModule ? (match.type.id === equipmentModule.id || compatibleCharge(equipmentModule, match.type))
                : query.split(/\s+/).every(word => match.type.name.toLowerCase().includes(word))))
            .sort((a, b) => groups.findIndex(group => group[0] === a.category) - groups.findIndex(group => group[0] === b.category)
                || (a.type.attributes.subSystemSlot || 0) - (b.type.attributes.subSystemSlot || 0)
                || compareEquipment(a.type, b.type));
        const results = element('results');
        results.replaceChildren();
        let previousCategory;
        let previousMeta;
        let groupResults = results;
        for (const match of matches) {
            const type = match.type;
            if (match.category !== previousCategory) {
                const label = match.category === 'Cargo' && equipmentModule && rackFor(equipmentModule) ? 'Valid charges' : groups.find(group => group[0] === match.category)[1];
                if (!query) {
                    const group = node('details', 'border-bottom');
                    group.open = equipmentGroups.get(match.category) ?? (category !== 'All');
                    group.addEventListener('toggle', () => {
                        equipmentGroups.set(match.category, group.open);
                        saveState();
                    });
                    group.append(node('summary', 'p-2 fw-bold', label));
                    groupResults = node('div', 'list-group list-group-flush');
                    group.append(groupResults);
                    results.append(group);
                } else {
                    results.append(node('div', 'list-group-item fw-bold', label));
                }
                previousCategory = match.category;
                previousMeta = null;
            }
            const meta = equipmentSort === 'Meta' ? metaLabel(type) : null;
            if (meta && meta !== previousMeta) groupResults.append(node('div', 'list-group-item small fw-bold', meta));
            previousMeta = meta;
            let chargeList;
            const row = node('div', 'list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2');
            const showCharges = fitted => {
                revealedCharges.set(type.id, fitted.flag);
                const charges = types.filter(charge => compatibleCharge(type, charge)).sort(compareEquipment);
                if (charges.length) {
                    if (!chargeList) {
                        chargeList = node('div', 'list-group list-group-flush w-100');
                        row.append(chargeList);
                    }
                    chargeList.replaceChildren(node('div', 'list-group-item fw-bold', 'Valid charges'));
                    let previousChargeMeta;
                    for (const charge of charges) {
                        const label = equipmentSort === 'Meta' ? metaLabel(charge) : null;
                        if (label && label !== previousChargeMeta) chargeList.append(node('div', 'list-group-item small fw-bold', label));
                        previousChargeMeta = label;
                        const chargeRow = node('div', 'list-group-item d-flex align-items-center justify-content-between gap-2 ps-3');
                        const chargeName = node('span', 'small', charge.name.replace(/\s+(XS|S|M|L|XL|XXL)$/, '\u00a0$1'));
                        chargeName.style.flex = '1 1 0';
                        chargeName.style.minWidth = '0';
                        chargeRow.append(chargeName, button('Load', () => {
                            if (!fit.items.some(item => item.flag === fitted.flag && item.type_id === fitted.type_id)) throw new Error('Fit this module again before loading a charge.');
                            setCharge(fitted.flag, charge.id);
                            changed();
                        }, 'btn btn-sm btn-primary text-white fw-semibold flex-shrink-0'));
                        chargeRow.lastChild.setAttribute('aria-label', 'Load ' + charge.name);
                        chargeRow.draggable = true;
                        chargeRow.addEventListener('dragstart', event => {
                            event.stopPropagation();
                            event.dataTransfer.setData('application/x-zkb-module', String(charge.id));
                            event.dataTransfer.effectAllowed = 'copy';
                            highlightDropTargets(charge);
                        });
                        chargeList.append(chargeRow);
                    }
                }
            };
            const name = node('span', 'small', type.name.replace(/\s+(XS|S|M|L|XL|XXL)$/, '\u00a0$1'));
            name.style.flex = '1 1 0';
            name.style.minWidth = '0';
            row.append(name, button('Add', () => {
                if (!fit) throw new Error('Choose a ship first.');
                if (type.categoryID === 8 && category !== 'Cargo') {
                    const modules = fit.items.filter(item => rackFor(catalog[item.type_id]) && ![5, 87, 158].includes(item.flag) && compatibleCharge(catalog[item.type_id], type));
                    const target = modules.find(item => item.flag === selected?.flag) || modules[0];
                    if (!target) throw new Error('Fit a compatible module before loading this charge, or select Cargo to store it.');
                    setCharge(target.flag, type.id);
                } else if (['Drone', 'Fighter', 'Cargo'].includes(match.category)) {
                    const flag = match.category === 'Drone' ? 87 : match.category === 'Fighter' ? 158 : 5;
                    addToBay(type, flag);
                } else {
                    addModule(fit, type, catalog, stats, selected?.rack === match.category ? selected.flag : undefined);
                    showCharges(fit.items.at(-1));
                }
                changed();
            }, 'btn btn-sm btn-primary text-white fw-semibold flex-shrink-0'));
            row.lastChild.setAttribute('aria-label', 'Add ' + type.name);
            row.lastChild.disabled = !hull || (match.category === 'Drone' && !hasDroneBay(fit, catalog)) || (match.category === 'Fighter' && !hasFighterBay(fit, catalog)) || (!['Drone', 'Fighter', 'Cargo'].includes(match.category) && !compatible(type, hull));
            if (row.lastChild.disabled) row.lastChild.title = hull ? 'Not compatible with this ship' : 'Choose a ship to fit this item';
            if (rackFor(type) || [8, 18, 87].includes(type.categoryID)) {
                row.draggable = true;
                row.addEventListener('dragstart', event => {
                    event.dataTransfer.setData('application/x-zkb-module', String(type.id));
                    event.dataTransfer.effectAllowed = 'copy';
                    highlightDropTargets(type);
                });
            }
            groupResults.append(row);
            const revealed = fit?.items.find(item => item.type_id === type.id && item.flag === revealedCharges.get(type.id));
            if (revealed) showCharges(revealed);
        }
        if (!matches.length) results.append(node('p', 'text-muted p-2 mb-0', hull ? 'No matching equipment.' : 'Choose a ship first.'));
    }

    function renderFit() {
        dropTargets.clear();
        const hull = catalog[fit.ship_type_id];
        element('hull').textContent = hull.name;
        const ship = fittingImage('ship-' + hull.id, 'w-100 h-100 rounded');
        ship.src = 'https://images.evetech.net/types/' + hull.id + '/render?size=512';
        ship.width = 512;
        ship.height = 512;
        ship.alt = hull.name;
        element('bigship').replaceChildren(ship);
        const slots = element('slots');
        slots.replaceChildren();
        for (const rack of [racks[2], racks[1], racks[0], racks[3], racks[4]]) {
            const fitted = fit.items.filter(item => item.flag >= rack.start && item.flag < rack.start + 8 && catalog[item.type_id].categoryID !== 8);
            const count = slotCount(rack, hull, stats, catalog);
            const displayed = Math.max(count, ...fitted.map(item => item.flag - rack.start + 1));
            const prefix = { High: 'high', Medium: 'mid', Low: 'low', Rig: 'rig', SubSystem: 'sub' }[rack.name];
            for (let index = 0; index < 8; index++) {
                const frame = wheel.querySelector('.flag' + (rack.start + index));
                if (frame) frame.style.display = index < displayed ? '' : 'none';
                const mount = element(prefix + (index + 1));
                if (mount) { mount.replaceChildren(); mount.hidden = index >= displayed; }
                const chargeMount = element(prefix + (index + 1) + 'l');
                if (chargeMount) chargeMount.replaceChildren();
            }
            if (!displayed) continue;
            for (let index = 0; index < displayed; index++) {
                const flag = rack.start + index;
                const item = fitted.find(item => item.flag === flag);
                const calculated = item && calculatedItems.find(detail => detail.type_id === item.type_id && detail.slot?.type === rack.name && detail.slot.index === index + 1);
                const choose = button((index + 1) + '. ' + (item ? catalog[item.type_id].name : 'Empty slot'), () => {
                    selected = { rack: rack.name, flag };
                    if (item && calculated) {
                        const states = moduleStates(calculated.maxState).map(state => state[0]);
                        const current = states.includes(item.state) ? item.state : calculated.state;
                        item.state = states[(states.indexOf(current) + 1) % states.length];
                        changed();
                    } else {
                        element('category').value = rack.name;
                        element('search').value = '';
                        equipmentModule = null;
                        renderFit();
                        search();
                        element('search').focus();
                    }
                });
                const mount = element(prefix + (index + 1));
                if (!mount) continue;
                choose.className = 'btn p-0 rounded-1 w-100 h-100 border-0 bg-transparent text-white shadow-none';
                const outline = wheel.querySelector('.flag' + flag)?.querySelector('.fitted path');
                if (outline) {
                    outline.style.stroke = '#808080';
                    if (calculated && calculated.maxState !== 'Passive' && calculated.state === 'Overload') outline.style.stroke = 'var(--bs-danger)';
                    else if (calculated && calculated.maxState !== 'Passive' && ['Online', 'Active'].includes(calculated.state)) outline.style.stroke = 'var(--bs-success)';
                }
                choose.setAttribute('aria-label', rack.label.replace(/s$/, '') + ' ' + (index + 1) + ': ' + (item ? catalog[item.type_id].name : 'Empty'));
                choose.setAttribute('aria-pressed', String(selected?.flag === flag));
                choose.title = rack.label.replace(/s$/, '') + ' ' + (index + 1) + ': ' + (item ? catalog[item.type_id].name + ' · ' + (moduleStates(calculated?.maxState).find(state => state[0] === (item.state || calculated?.state))?.[1] || 'Online') : 'Empty');
                choose.textContent = '+';
                if (item) {
                    const icon = fittingImage('module-' + flag + '-' + item.type_id, 'w-100 h-100 rounded-1');
                    icon.src = 'https://images.evetech.net/types/' + item.type_id + '/icon?size=128';
                    icon.alt = '';
                    removalDrag(icon, item);
                    choose.replaceChildren(icon);
                    removalDrag(choose, item);
                    if (calculated?.state === 'Passive' && calculated.maxState !== 'Passive') choose.className += ' opacity-50';
                }
                const frame = wheel.querySelector('.flag' + flag);
                const accepts = type => type.categoryID === 8 ? !!item && compatibleCharge(catalog[item.type_id], type) : !item && rackFor(type)?.name === rack.name && compatible(type, hull);
                if (frame) dropTargets.set(frame, accepts);
                const dragover = event => {
                    if (dragType && !accepts(dragType)) return;
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'copy';
                };
                const drop = event => {
                    event.stopPropagation();
                    event.preventDefault();
                    try {
                        const payload = event.dataTransfer.getData('application/x-zkb-fitted-item');
                        const source = payload ? JSON.parse(payload) : null;
                        const type = catalog[source?.type_id || Number(event.dataTransfer.getData('application/x-zkb-module'))];
                        if (source && type?.categoryID !== 8) throw new Error('Drop a charge onto a compatible module.');
                        if (type?.categoryID === 8) setCharge(flag, type.id);
                        else {
                            if (!type || rackFor(type)?.name !== rack.name || (rack.name === 'SubSystem' && type.attributes.subSystemSlot !== flag)) throw new Error('This module does not fit that slot.');
                            addModule(fit, type, catalog, stats, flag);
                        }
                        selected = { rack: rack.name, flag };
                        changed();
                    } catch (error) { showStatus(error.message); }
                };
                choose.addEventListener('dragover', dragover);
                choose.addEventListener('drop', drop);
                if (frame) {
                    frame.ondragover = dragover;
                    frame.ondrop = drop;
                }
                mount.append(choose);
                const charge = fit.items.find(other => other.flag === flag && catalog[other.type_id].categoryID === 8);
                const chargeMount = element(prefix + (index + 1) + 'l');
                if (charge && chargeMount) {
                    const chargeIcon = fittingImage('charge-' + flag + '-' + charge.type_id, 'w-100 h-100');
                    chargeIcon.src = 'https://images.evetech.net/types/' + charge.type_id + '/icon?size=128';
                    chargeIcon.alt = catalog[charge.type_id].name;
                    chargeIcon.title = catalog[charge.type_id].name;
                    removalDrag(chargeIcon, charge);
                    chargeMount.append(chargeIcon);
                }
            }
        }
        for (const [flag, label] of [[87, 'Drones'], [158, 'Fighters'], [5, 'Cargo']]) {
            if (flag === 87 && !hasDroneBay(fit, catalog)) continue;
            if (flag === 158 && !hasFighterBay(fit, catalog)) continue;
            const section = node('section', 'mb-3 p-2 rounded');
            section.dataset.bay = 'true';
            section.style.border = '1px solid transparent';
            section.style.minHeight = '4rem';
            dropTargets.set(section, type => flag === 5 || (flag === 87 && type.categoryID === 18 && hasDroneBay(fit, catalog)) || (flag === 158 && type.categoryID === 87 && hasFighterBay(fit, catalog)));
            section.addEventListener('dragover', event => {
                const types = Array.from(event.dataTransfer.types);
                const moving = flag === 5 && types.includes('application/x-zkb-fitted-item');
                if (!moving && !types.includes('application/x-zkb-module')) return;
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            });
            section.addEventListener('drop', event => {
                const typeID = event.dataTransfer.getData('application/x-zkb-module');
                const payload = event.dataTransfer.getData('application/x-zkb-fitted-item');
                if (!typeID && !payload) return;
                event.preventDefault();
                try {
                    if (payload) {
                        const dropped = JSON.parse(payload);
                        const charge = fit.items.find(item => item.flag === dropped.flag && item.type_id === dropped.type_id);
                        if (flag !== 5 || !charge || [5, 87, 158].includes(charge.flag) || catalog[charge.type_id].categoryID !== 8) return;
                        addCargoCharge(catalog[charge.type_id], charge.quantity);
                    } else {
                        const type = catalog[Number(typeID)];
                        if (flag === 5 && type?.categoryID === 8) addCargoCharge(type);
                        else addToBay(type, flag);
                    }
                    changed();
                } catch (error) { showStatus(error.message); }
            });
            section.append(node('h3', 'h6 mx-0 border-bottom pb-2', label));
            const items = fit.items.filter(item => item.flag === flag)
                .sort((a, b) => catalog[a.type_id].name.localeCompare(catalog[b.type_id].name));
            if (!items.length && flag !== 158) section.append(node('p', 'text-muted small', 'Empty'));
            if (flag === 158) {
                const deployed = (fit.fighterTubes || []).filter(Boolean).reduce((total, tube) => total + tube.quantity, 0);
                const stored = items.reduce((total, item) => total + item.quantity - deployedFighters(item.type_id), 0);
                section.append(node('p', 'small text-secondary mb-2', deployed + ' deployed fighters in ' + items.reduce((total, item) => total + (item.active || 0), 0) + ' / ' + (stats?.fighterTubes ?? hull.attributes.fighterTubes ?? 0) + ' tubes are included in Fighter DPS · ' + stored + ' fighters stored in bay'));
                const roles = node('div', 'd-flex flex-wrap gap-3 small mb-2');
                for (const [label, attribute, fighterAttribute] of [['Light', 'fighterLightSlots', 'fighterSquadronIsLight'], ['Support', 'fighterSupportSlots', 'fighterSquadronIsSupport'], ['Heavy', 'fighterHeavySlots', 'fighterSquadronIsHeavy']]) {
                    const limit = hull.attributes[attribute] || 0;
                    if (!limit) continue;
                    const used = (fit.fighterTubes || []).filter(tube => tube && catalog[tube.type_id].attributes[fighterAttribute]).length;
                    roles.append(node('span', 'badge rounded-pill text-bg-dark border border-secondary', label + ' ' + used + ' / ' + limit));
                }
                section.append(roles);
                const assignments = Array.from({ length: stats?.fighterTubes ?? hull.attributes.fighterTubes ?? 0 }, (_, index) => fit.fighterTubes?.[index] || null);
                const tubes = node('div', 'row row-cols-2 row-cols-sm-3 row-cols-md-5 g-2 mb-3');
                for (let index = 0; index < assignments.length; index++) {
                    const item = items.find(item => item.type_id === assignments[index]?.type_id);
                    const column = node('div', 'col');
                    const card = node('div', 'card bg-black ' + (item ? 'border-success' : 'border-secondary') + ' h-100 text-center');
                    const body = node('div', 'card-body p-2 position-relative');
                    body.append(node('span', 'position-absolute top-0 start-0 small text-secondary ps-1', String(index + 1)));
                    if (item) {
                        const type = catalog[item.type_id];
                        const squadronSize = type.attributes.fighterSquadronMaxSize || item.quantity;
                        const dial = node('div', 'rounded-circle mx-auto mb-1 p-1 position-relative');
                        dial.style.width = '64px';
                        dial.style.height = '64px';
                        dial.style.background = 'conic-gradient(from 210deg, var(--bs-success) 0deg ' + 300 * assignments[index].quantity / squadronSize + 'deg, transparent ' + 300 * assignments[index].quantity / squadronSize + 'deg)';
                        dial.title = assignments[index].quantity + ' fighters deployed in tube ' + (index + 1);
                        const image = node('img', 'd-block w-100 h-100 rounded-circle bg-black');
                        image.src = 'https://images.evetech.net/types/' + type.id + '/icon?size=64';
                        image.alt = '';
                        const count = node('span', 'position-absolute bottom-0 start-50 translate-middle-x badge rounded-pill text-bg-dark border border-success', String(assignments[index].quantity));
                        dial.append(image, count);
                        const fighterName = node('div', 'd-flex align-items-center justify-content-center gap-1');
                        const adjust = (change, label) => {
                            const control = button(change < 0 ? '−' : '+', () => {
                                const nextTubes = (fit.fighterTubes || []).map(tube => tube && { ...tube });
                                const quantity = nextTubes[index].quantity + change;
                                nextTubes[index] = quantity > 0 ? { ...nextTubes[index], quantity } : null;
                                const nextItems = fighterItemsForTubes(fit.items, nextTubes);
                                try { validateFighters({ ...fit, fighterTubes: nextTubes, items: nextItems }, catalog, stats?.fighterCapacity); }
                                catch (error) { showStatus(error.message); return; }
                                fit.fighterTubes = nextTubes;
                                fit.items = nextItems;
                                changed();
                            }, 'btn btn-sm btn-link text-white text-decoration-none p-0 fw-bold');
                            control.setAttribute('aria-label', label + ' ' + type.name + ' in fighter tube ' + (index + 1));
                            control.disabled = change > 0 && (assignments[index].quantity >= squadronSize || deployedFighters(type.id) >= item.quantity);
                            return control;
                        };
                        fighterName.append(adjust(-1, 'Remove'), node('div', 'small text-white text-truncate', type.name), adjust(1, 'Add'));
                        body.append(dial, fighterName);
                    } else body.append(node('div', 'display-6 text-secondary lh-1 mt-2', '+'), node('div', 'small text-secondary mt-1', 'Open'), node('div', 'small text-secondary fst-italic text-truncate', 'Empty'));
                    const options = [[0, 'Open']];
                    for (const candidate of items) {
                        const nextTubes = assignments.map(tube => tube && { ...tube });
                        nextTubes[index] = { type_id: candidate.type_id, quantity: catalog[candidate.type_id].attributes.fighterSquadronMaxSize || candidate.quantity };
                        try {
                            validateFighters({ ...fit, fighterTubes: nextTubes, items: fighterItemsForTubes(fit.items, nextTubes) }, catalog, stats?.fighterCapacity);
                            options.push([candidate.type_id, catalog[candidate.type_id].name]);
                        } catch (error) { /* This fighter cannot occupy another tube. */ }
                    }
                    const assignment = select(options, assignments[index]?.type_id || 0, 'Fighter tube ' + (index + 1), value => {
                        const nextTubes = Array.from({ length: assignments.length }, (_, tube) => fit.fighterTubes?.[tube] || null);
                        const typeID = Number(value);
                        nextTubes[index] = typeID ? { type_id: typeID, quantity: catalog[typeID].attributes.fighterSquadronMaxSize || items.find(item => item.type_id === typeID).quantity } : null;
                        const nextItems = fighterItemsForTubes(fit.items, nextTubes);
                        try { validateFighters({ ...fit, fighterTubes: nextTubes, items: nextItems }, catalog, stats?.fighterCapacity); }
                        catch (error) { showStatus(error.message); renderFit(); return; }
                        fit.fighterTubes = nextTubes;
                        fit.items = nextItems;
                        changed();
                    });
                    assignment.className = 'form-select form-select-sm mt-2';
                    body.append(assignment);
                    card.append(body);
                    column.append(card);
                    tubes.append(column);
                }
                section.append(tubes);
                section.append(node('div', 'small fw-bold text-secondary text-uppercase border-bottom pb-1 mb-2', 'Fighter bay inventory'));
                if (!items.length) section.append(node('p', 'text-muted small', 'No fighters in inventory.'));
            }
            items.forEach(item => {
                const row = node('div', flag === 158 ? 'd-grid align-items-center gap-2 py-1 border-bottom border-secondary border-opacity-25' : 'd-flex flex-wrap align-items-center gap-2 mb-2');
                if (flag === 158) row.style.gridTemplateColumns = '4rem auto 32px minmax(0, 1fr) auto';
                if (flag === 158) {
                    row.append(node('span', 'small text-secondary', '×'));
                    const image = node('img', 'rounded flex-shrink-0');
                    image.src = 'https://images.evetech.net/types/' + item.type_id + '/icon?size=64';
                    image.width = 32;
                    image.height = 32;
                    image.alt = '';
                    row.append(image);
                }
                const itemName = node('span', 'flex-grow-1 small', catalog[item.type_id].name);
                itemName.style.minWidth = '0';
                if (flag === 158) {
                    const type = catalog[item.type_id];
                    const squadronSize = type.attributes.fighterSquadronMaxSize || item.quantity;
                    const deployed = deployedFighters(item.type_id);
                    const capacity = stats?.fighterCapacity ?? hull.attributes.fighterCapacity ?? 0;
                    const maximum = type.volume > 0 ? Math.floor(capacity / type.volume) : 0;
                    const otherLoad = items.filter(other => other !== item).reduce((total, other) => total + catalog[other.type_id].volume * (other.quantity - deployedFighters(other.type_id)), 0);
                    const available = type.volume > 0 ? Math.floor(Math.max(0, capacity - otherLoad) / type.volume) : 0;
                    itemName.append(node('small', 'd-block text-secondary text-truncate', deployed + ' deployed · ' + (item.quantity - deployed) + ' in bay · max ' + maximum + ' in empty bay'));
                    itemName.title = squadronSize + ' per squadron · empty bay fits ' + maximum + ' fighters · current bay allows ' + available;
                }
                row.append(itemName);
                const quantity = node('input', 'form-control form-control-sm');
                quantity.style.width = flag === 158 ? '4rem' : '5rem';
                if (flag === 158) quantity.className += ' order-first';
                quantity.type = 'number';
                quantity.min = '1';
                quantity.max = [87, 158].includes(flag) ? '1000' : '1000000';
                if (flag === 158) {
                    const type = catalog[item.type_id];
                    const squadronSize = type.attributes.fighterSquadronMaxSize || item.quantity;
                    const deployed = deployedFighters(item.type_id);
                    const capacity = stats?.fighterCapacity ?? hull.attributes.fighterCapacity ?? 0;
                    const otherLoad = items.filter(other => other !== item).reduce((total, other) => total + catalog[other.type_id].volume * (other.quantity - deployedFighters(other.type_id)), 0);
                    quantity.max = Math.min(1000, deployed + (type.volume > 0 ? Math.floor(Math.max(0, capacity - otherLoad) / type.volume) : 0));
                }
                quantity.value = item.quantity;
                quantity.setAttribute('aria-label', catalog[item.type_id].name + ' quantity');
                quantity.addEventListener('change', () => {
                    if (!quantity.checkValidity()) { quantity.reportValidity(); return; }
                    const nextQuantity = Number(quantity.value);
                    let nextTubes = fit.fighterTubes || [];
                    if (flag === 158) {
                        nextTubes = nextTubes.map(tube => tube && { ...tube });
                        let remove = deployedFighters(item.type_id, nextTubes) - nextQuantity;
                        for (let index = nextTubes.length - 1; index >= 0 && remove > 0; index--) {
                            if (nextTubes[index]?.type_id !== item.type_id) continue;
                            const quantity = Math.min(remove, nextTubes[index].quantity);
                            nextTubes[index].quantity -= quantity;
                            remove -= quantity;
                            if (!nextTubes[index].quantity) nextTubes[index] = null;
                        }
                    }
                    const nextActive = flag === 158 ? nextTubes.filter(tube => tube?.type_id === item.type_id).length : Math.min(item.active || 0, nextQuantity);
                    const nextItems = fit.items.map(other => other === item ? { ...item, quantity: nextQuantity, active: nextActive } : other);
                    try {
                        if (flag === 87) validateDrones({ ...fit, items: nextItems }, catalog, pilotStats?.maxActiveDrones);
                        if (flag === 158) validateFighters({ ...fit, fighterTubes: nextTubes, items: nextItems }, catalog, stats?.fighterCapacity);
                    } catch (error) {
                        quantity.value = item.quantity;
                        showStatus(error.message);
                        return;
                    }
                    item.quantity = nextQuantity;
                    item.active = nextActive;
                    if (flag === 158) fit.fighterTubes = nextTubes;
                    changed();
                });
                row.append(quantity);
                if (flag === 87) row.append(select(Array.from({ length: Math.min(item.quantity, 25) + 1 }, (_, i) => [i, i + ' deployed']), item.active || 0, 'Deployed ' + catalog[item.type_id].name, value => {
                    try {
                        validateDrones({ ...fit, items: fit.items.map(other => other === item ? { ...item, active: Number(value) } : other) }, catalog, pilotStats?.maxActiveDrones);
                    } catch (error) {
                        showStatus(error.message);
                        renderFit();
                        return;
                    }
                    item.active = Number(value);
                    changed();
                }));
                removalDrag(row, item);
                const remove = button('', () => removeItem(item), 'btn btn-sm btn-danger text-white flex-shrink-0');
                remove.style.backgroundColor = '#550404';
                remove.style.borderColor = '#550404';
                remove.setAttribute('aria-label', 'Remove ' + catalog[item.type_id].name);
                remove.title = 'Remove ' + catalog[item.type_id].name;
                const trash = node('i', 'fas fa-trash');
                trash.setAttribute('aria-hidden', 'true');
                remove.append(trash);
                row.append(remove);
                section.append(row);
            });
            slots.append(section);
        }
        if (dragType) highlightDropTargets(dragType, dragFromFit);
    }

    function renderStats(result) {
        stats = result.stats;
        pilotStats = result.character;
        calculatedItems = result.details;
        const number = (key, divisor = 1) => Number.isFinite(stats[key]) ? (stats[key] / divisor).toLocaleString(undefined, { maximumFractionDigits: 2 }) : '—';
        const compact = value => Number.isFinite(value) ? value.toLocaleString(undefined, { maximumFractionDigits: 1 }) : '—';
        const duration = value => {
            if (!Number.isFinite(value)) return '—';
            const seconds = Math.max(0, Math.round(value));
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor(seconds % 3600 / 60);
            return (hours ? hours + 'h ' : '') + (minutes ? minutes + 'm ' : '') + seconds % 60 + 's';
        };
        const pricing = node('div');
        const costs = { Hull: [{ type_id: fit.ship_type_id, quantity: 1 }], Modules: [], 'Loaded charges': [], Drones: [], Fighters: [], Cargo: [] };
        for (const item of fit.items) {
            const group = item.flag === 5 ? 'Cargo' : item.flag === 87 ? 'Drones' : item.flag === 158 ? 'Fighters' : catalog[item.type_id].categoryID === 8 ? 'Loaded charges' : 'Modules';
            costs[group].push({ ...item });
        }
        const amounts = new Map();
        for (const label of [...Object.keys(costs), 'Total']) {
            const row = node('div', 'd-flex justify-content-between gap-3 py-1' + (label === 'Total' ? ' fw-bold border-top' : ''));
            const amount = node('span', 'text-end');
            row.append(node('span', '', label), amount);
            amounts.set(label, amount);
            pricing.append(row);
        }
        pricing.append(node('small', 'text-secondary', 'Estimated ISK values using zKillboard prices.'));
        const updatePrices = () => {
            let total = 0;
            let complete = true;
            for (const [label, items] of Object.entries(costs)) {
                let subtotal = 0;
                let pending = false;
                let missing = false;
                for (const item of items) {
                    const price = prices.get(item.type_id);
                    if (price === undefined) pending = true;
                    else if (price === null) missing = true;
                    else subtotal += price * (item.quantity || 1);
                }
                amounts.get(label).textContent = pending ? 'Loading…' : missing ? 'Unavailable' : subtotal.toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' ISK';
                total += subtotal;
                complete &&= !pending && !missing;
            }
            amounts.get('Total').textContent = complete ? total.toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' ISK' : '—';
        };
        updatePrices();
        const ids = [...new Set(Object.values(costs).flat().map(item => item.type_id))];
        if (!priceRequest) {
            priceRequest = (async () => {
                for (let attempt = 0; attempt < 3; attempt++) {
                    if (!alive) return;
                    const response = await fetch('/api/prices/date/' + new Date(Date.now() - 86400000).toISOString().slice(0, 10) + '/', { signal: events.signal });
                    if (response.status === 429) {
                        const retryAfter = response.headers.get('Retry-After');
                        const delay = retryAfter === null ? NaN : /^\d+$/.test(retryAfter) ? Number(retryAfter) * 1000 : Date.parse(retryAfter) - Date.now();
                        if (attempt < 2) await new Promise(resolve => {
                            const abort = () => { clearTimeout(timeout); resolve(); };
                            const timeout = setTimeout(() => { events.signal.removeEventListener('abort', abort); resolve(); }, Number.isFinite(delay) ? Math.max(0, delay) : 60000 * (attempt + 1));
                            events.signal.addEventListener('abort', abort, { once: true });
                        });
                        continue;
                    }
                    if (!response.ok) throw new Error('Prices unavailable');
                    for (const [typeID, value] of Object.entries(await response.json())) {
                        const price = Number(value);
                        prices.set(Number(typeID), Number.isFinite(price) && price > 0.01 ? price : null);
                    }
                    return;
                }
            })().catch(() => {});
        }
        priceRequest.then(() => {
            if (!alive) return;
            for (const id of ids) if (!prices.has(id)) prices.set(id, null);
            updatePrices();
        });
        element('resource-cpu').textContent = compact(stats.cpuOutput - stats.cpuLoad) + '/' + compact(stats.cpuOutput);
        element('resource-powergrid').textContent = compact(stats.powerOutput - stats.powerLoad) + '/' + compact(stats.powerOutput);
        const sections = [
            ['Capacitor', []],
            ['Offense', [
                ['DPS without reload', number('damagePerSecondWithoutReload'), 'turret_missile'],
                ['DPS with reload', number('damagePerSecondWithReload'), 'sustained'],
                ['Volley', number('damageAlpha'), 'volley'],
                ['Drone DPS', number('droneDamagePerSecond'), 'drone'],
                ['Fighter DPS (' + number('fighterCraftDeployed') + ' deployed)', number('fighterDamagePerSecond'), 'drone']
            ]],
            ['Defense', []],
            ['Navigation & Targeting', [
                ['Speed', number('maxVelocity') + ' m/s', 'propulsion'],
                ['Align', number('alignTime') + ' s', 'microwarpdrive'],
                ['Warp', number('warpSpeedMultiplier') + ' AU/s', 'microwarpdrive'],
                ['Target range / targets', number('maxTargetRange', 1000) + ' km / ' + number('maxLockedTargets'), 'targeting_range'],
                ['Scan resolution', number('scanResolution') + ' mm', 'targeting_resolution'],
                ['Signature', number('signatureRadius') + ' m', 'targeting_strength']
            ]],
            ['Drones, Fighters & Cargo', [
                ['Drone bay', number('droneCapacityLoad') + ' / ' + number('droneCapacity') + ' m³', 'dronebay'],
                ['Drone bandwidth', number('droneBandwidthLoad') + ' / ' + number('droneBandwidth') + ' Mbit/s', 'dronebandwith'],
                ['Fighter bay (' + number('fighterCraftInBay') + ' stored)', number('fighterCapacityLoad') + ' / ' + number('fighterCapacity') + ' m³', 'dronebay'],
                ['Fighter tubes', number('fighterTubesUsed') + ' / ' + number('fighterTubes'), 'drone'],
                ...[['Light', 'fighterLightSlots'], ['Support', 'fighterSupportSlots'], ['Heavy', 'fighterHeavySlots']].filter(([, key]) => stats[key] > 0).map(([role, key]) => [role + ' fighter tubes', number(key + 'Used') + ' / ' + number(key), 'drone']),
                ['Cargo capacity', number('capacity') + ' m³', 'cargo']
            ]],
            ['Fitting', [
                ['Calibration', number('upgradeLoad') + ' / ' + number('upgradeCapacity'), 'icon-rigslot'],
                ['Free turret / launcher hardpoints', number('turretSlotsLeft') + ' / ' + number('launcherSlotsLeft'), 'turret_missile']
            ]],
            ['Pricing', []]
        ];
        element('stats').replaceChildren();
        for (const [title, rows] of sections) {
            const column = node('section', 'col-12 col-md-6 col-xl-12');
            const card = node('div', 'card bg-black border-primary border-opacity-25 shadow-sm');
            const body = node('div', 'card-body px-3 py-2');
            const list = node(['Capacitor', 'Defense', 'Pricing'].includes(title) ? 'div' : 'dl', 'row gx-2 gy-1 small lh-sm mb-0');
            list.id = 'simulate-stats-' + title.toLowerCase().replace(/[^a-z]+/g, '-');
            list.hidden = !expandedSections.has(title);
            const heading = node('h3', 'h6 mx-0 mb-0 border-bottom border-primary pb-1');
            const icon = node('i', 'fas fa-chevron-' + (list.hidden ? 'down' : 'up'));
            icon.setAttribute('aria-hidden', 'true');
            const toggle = button('', () => {
                list.hidden = !list.hidden;
                if (list.hidden) expandedSections.delete(title);
                else expandedSections.add(title);
                toggle.setAttribute('aria-expanded', String(!list.hidden));
                icon.className = 'fas fa-chevron-' + (list.hidden ? 'down' : 'up');
                saveState();
            }, 'btn p-0 border-0 text-white w-100 d-flex align-items-center justify-content-between text-start');
            toggle.setAttribute('aria-expanded', String(!list.hidden));
            toggle.setAttribute('aria-controls', list.id);
            icon.title = 'Double-click to expand or collapse all sections';
            icon.addEventListener('dblclick', () => {
                // The two preceding clicks restore this section's original state.
                const expanded = String(list.hidden);
                element('stats').querySelectorAll('button[aria-expanded]').forEach(section => {
                    if (section.getAttribute('aria-expanded') !== expanded) section.click();
                });
            });
            if (title === 'Capacitor') {
                const summary = node('span', 'd-flex align-items-center gap-2');
                summary.append(node('span', stats.capacitorDepletesIn < 0 ? 'text-success fw-semibold' : 'text-warning fw-semibold', stats.capacitorDepletesIn < 0 ? 'Stable' : duration(stats.capacitorDepletesIn)), icon);
                toggle.append(node('span', 'fw-bold', title), summary);
            } else if (title === 'Defense') {
                const summary = node('span', 'd-flex align-items-center gap-2');
                summary.append(node('span', 'fw-semibold', number('ehp') + ' EHP'), icon);
                toggle.append(node('span', 'fw-bold', title), summary);
            } else toggle.append(node('span', 'fw-bold', title), icon);
            heading.append(toggle);
            body.append(heading);
            list.className += ' mt-2';
            if (title === 'Capacitor') {
                const capacitor = node('div', 'col-12 d-flex align-items-center gap-2 text-white');
                const capacitorIcon = node('img', 'flex-shrink-0');
                capacitorIcon.src = '/img/simulate/capacitor.png';
                capacitorIcon.width = 40;
                capacitorIcon.height = 40;
                capacitorIcon.alt = '';
                const values = node('div', 'd-flex flex-column gap-1');
                values.append(node('span', 'fw-semibold', number('capacitorCapacity') + ' GJ / ' + duration(stats.rechargeRate / 1000)));
                const percentage = Number.isFinite(stats.capacitorPeakDeltaPercentage) ? stats.capacitorPeakDeltaPercentage.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%' : '—';
                values.append(node('span', '', 'Δ ' + number('capacitorPeakDelta') + ' GJ/s (' + percentage + ')'));
                capacitor.append(capacitorIcon, values);
                list.append(capacitor);
            } else if (title === 'Defense') {
                const grid = node('div', 'col-12 row g-1 align-items-center mx-0');
                grid.append(node('div', 'col-4 pe-2 text-end text-white fw-semibold', 'HP'));
                const damages = [['Em', 'EM', 'electromagnetic', 'bg-primary'], ['Thermal', 'Thermal', 'thermal', 'bg-danger'], ['Kinetic', 'Kinetic', 'kinetic', 'bg-secondary'], ['Explosive', 'Explosive', 'explosive', 'bg-warning']];
                for (const [, name, image] of damages) {
                    const damage = node('div', 'col-2 text-center');
                    const damageIcon = node('img', 'd-block mx-auto');
                    damageIcon.src = '/img/simulate/' + image + '-resistance-32x32.png';
                    damageIcon.width = 20;
                    damageIcon.height = 20;
                    damageIcon.alt = name;
                    damage.append(damageIcon);
                    grid.append(damage);
                }
                for (const [label, prefix, hp] of [['Shield', 'shield', 'shieldCapacity'], ['Armor', 'armor', 'armorHP'], ['Hull', '', 'hp']]) {
                    const layer = node('div', 'col-4 d-flex align-items-center text-white');
                    const layerIcon = node('img', 'me-1 flex-shrink-0');
                    layerIcon.src = '/img/simulate/' + label.toLowerCase() + '-resistance-32x32.png';
                    layerIcon.width = 20;
                    layerIcon.height = 20;
                    layerIcon.alt = label;
                    layer.append(layerIcon, node('span', 'fw-semibold', number(hp)));
                    grid.append(layer);
                    for (const [damage, , , color] of damages) {
                        const value = stats[prefix + (prefix ? damage : damage.toLowerCase()) + 'DamageResonance'];
                        grid.append(node('div', 'simulate-resistance-value col-2 px-1 py-1 rounded-1 text-center text-white fw-semibold ' + color, Number.isFinite(value) ? ((1 - value) * 100).toFixed(1) + '%' : '—'));
                    }
                }
                const tank = node('div', 'col-12 mt-1 pt-1 border-top border-secondary border-opacity-25 d-flex align-items-center text-white');
                const tankIcon = node('img', 'me-1 flex-shrink-0');
                tankIcon.src = '/img/simulate/shield_recharge.png';
                tankIcon.width = 20;
                tankIcon.height = 20;
                tankIcon.alt = '';
                tank.append(tankIcon, node('span', '', 'Shield recharge ' + number('passiveShieldEffectiveRechargeRate') + ' EHP/s · ' + number('shieldRechargeRate', 1000) + ' s'));
                grid.append(tank);
                const repairs = node('div', 'col-12 d-flex flex-column gap-1 text-white');
                for (const [label, stat, image] of [['Shield boost', 'shieldEffectiveBoostRate', 'shield_boost'], ['Armor repair', 'armorEffectiveRepairRate', 'armor_repair']]) {
                    const repair = node('span', 'd-flex align-items-center');
                    const repairIcon = node('img', 'me-1');
                    repairIcon.src = '/img/simulate/' + image + '.png';
                    repairIcon.width = 20;
                    repairIcon.height = 20;
                    repairIcon.alt = '';
                    repair.append(repairIcon, node('span', '', label + ' ' + number(stat) + ' EHP/s'));
                    repairs.append(repair);
                }
                grid.append(repairs);
                list.append(grid);
            } else rows.forEach(([label, value, image]) => {
                const term = node('dt', 'col-6 mb-1 fw-normal d-flex align-items-center text-white');
                const rowIcon = node('img', 'me-1 flex-shrink-0');
                rowIcon.src = '/img/simulate/' + image + '.png';
                rowIcon.width = 20;
                rowIcon.height = 20;
                rowIcon.alt = '';
                term.append(rowIcon, node('span', '', label));
                list.append(term, node('dd', 'col-6 mb-1 text-end text-white fw-semibold', value));
            });
            if (title === 'Pricing') list.append(pricing);
            body.append(list);
            card.append(body);
            column.append(card);
            element('stats').append(column);
        }
        const messages = warnings(fit, catalog, stats, result.character);
        element('warnings').hidden = messages.length === 0;
        element('warnings').textContent = messages.join(' ');
        renderFit();
    }

    function changed() {
        if (!fit) return;
        element('mode-control').hidden = catalog[fit.ship_type_id].groupID !== 1305;
        for (const mode of ['Defense', 'Propulsion', 'Sharpshooter']) {
            const active = mode === (fit.mode || 'Defense');
            element('mode-' + mode).className = 'btn btn-sm btn-outline-primary' + (active ? ' active' : '');
            element('mode-' + mode).setAttribute('aria-pressed', String(active));
        }
        fit.name = element('name').value || 'New fit';
        fit.skillLevel = Number(element('skills').value);
        const snapshot = JSON.stringify(fit);
        if (snapshot !== fitHistory[historyIndex]) {
            fitHistory.splice(historyIndex + 1);
            fitHistory.push(snapshot);
            if (fitHistory.length > 31) fitHistory.shift();
            historyIndex = fitHistory.length - 1;
        }
        element('undo').disabled = historyIndex <= 0;
        element('redo').disabled = historyIndex >= fitHistory.length - 1;
        try { localStorage.setItem('zkb:simulate', snapshot); } catch (error) { /* Storage may be disabled. */ }
        saveState();
        if (!stats) renderFit();
        element('stats').setAttribute('aria-busy', 'true');
        element('status').textContent = '';
        const request = { id: ++sequence, fit: JSON.parse(JSON.stringify(fit)), simulate: true, skillLevel: fit.skillLevel };
        if (calculationPending) queuedCalculation = request;
        else postCalculation(request);
    }

    function postCalculation(request) {
        calculationPending = true;
        worker.postMessage(request);
        timer = setTimeout(() => {
            worker.terminate();
            element('controls').disabled = true;
            showStatus('Calculation timed out. Reload the page to try again; your fit is saved in this browser.');
        }, 60000);
    }

    function loadFit(next) {
        fit = next;
        fit.items.filter(item => item.flag === 158 && !Number.isInteger(item.active)).forEach(item => { item.active = 0; });
        if (!Array.isArray(fit.fighterTubes)) fit.fighterTubes = fit.items.filter(item => item.flag === 158).flatMap(item => Array.from({ length: item.active || 0 }, () => ({ type_id: item.type_id, quantity: catalog[item.type_id].attributes.fighterSquadronMaxSize || item.quantity })));
        fit.fighterTubes = fit.fighterTubes.slice(0, catalog[fit.ship_type_id].attributes.fighterTubes || 0).map(tube => {
            if (Number.isInteger(tube)) tube = { type_id: tube, quantity: catalog[tube]?.attributes.fighterSquadronMaxSize || 0 };
            const item = fit.items.find(item => item.flag === 158 && item.type_id === tube?.type_id);
            return item && Number.isInteger(tube.quantity) && tube.quantity > 0 ? { type_id: tube.type_id, quantity: Math.min(tube.quantity, catalog[tube.type_id].attributes.fighterSquadronMaxSize || item.quantity) } : null;
        });
        fit.items = fighterItemsForTubes(fit.items, fit.fighterTubes);
        revealedCharges.clear();
        equipmentModule = null;
        stats = null;
        pilotStats = null;
        calculatedItems = [];
        selected = null;
        element('ship').value = catalog[fit.ship_type_id].name;
        element('name').value = fit.name;
        element('skills').value = fit.skillLevel === 0 ? '0' : '5';
        changed();
        search();
    }

    root.addEventListener('click', async event => {
        try {
            switch (event.target.id) {
                case 'simulate-sort':
                    equipmentSort = equipmentSort === 'Meta' ? 'Alpha' : 'Meta';
                    search();
                    saveState();
                    break;
                case 'simulate-eft-open':
                    element('eft-modal-text').value = fit ? exportEFT({ ...fit, name: element('name').value || 'New fit' }, catalog) : element('eft-modal-text').value;
                    element('eft-modal-status').textContent = '';
                    element('eft-modal-status').className = 'small mt-2';
                    break;
                case 'simulate-eft-modal-copy':
                case 'simulate-eft-modal-import':
                    try {
                        const text = element('eft-modal-text').value;
                        if (event.target.id === 'simulate-eft-modal-import') {
                            loadFit(importEFT(text, catalog));
                            element('eft-modal-status').textContent = 'Fit imported.';
                            window.bootstrap?.Modal.getInstance(element('eft-modal'))?.hide();
                        } else {
                            try { await navigator.clipboard.writeText(text); }
                            catch (error) { element('eft-modal-text').focus(); element('eft-modal-text').select(); throw new Error('Copy the selected text manually.'); }
                            element('eft-modal-status').textContent = 'EFT copied.';
                        }
                    } catch (error) {
                        element('eft-modal-status').className = 'alert alert-danger mt-2 mb-0';
                        element('eft-modal-status').textContent = error.message;
                    }
                    break;
                case 'simulate-save':
                    if (!fit) throw new Error('Choose a ship first.');
                    window.saveFitting(null, exportESIFit({ ...fit, name: element('name').value || 'New fit' }, catalog));
                    break;
                case 'simulate-undo':
                    if (historyIndex > 0) loadFit(JSON.parse(fitHistory[--historyIndex]));
                    break;
                case 'simulate-redo':
                    if (historyIndex < fitHistory.length - 1) loadFit(JSON.parse(fitHistory[++historyIndex]));
                    break;
                case 'simulate-new': {
                    const hull = types.find(type => type.categoryID === 6 && type.name.toLowerCase() === element('ship').value.trim().toLowerCase());
                    if (!hull) throw new Error('Select a ship from the list.');
                    loadFit({ ship_type_id: hull.id, name: 'New fit', items: [] });
                    break;
                }
            }
        } catch (error) { showStatus(error.message); }
    }, { signal: events.signal });
    globalThis.addEventListener?.('keydown', event => {
        if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey || event.isComposing) return;
        if (event.target.closest?.('input, textarea, select, [contenteditable]:not([contenteditable="false"])')) return;
        const action = { z: 'undo', r: 'redo' }[event.key.toLowerCase()];
        if (!action) return;
        event.preventDefault();
        if (!element(action).disabled) element(action).click();
    }, { signal: events.signal });
    element('ship').addEventListener('keydown', event => {
        if (event.key === 'Enter' && !shipSearch) { event.preventDefault(); element('new').click(); }
    }, { signal: events.signal });
    element('category').addEventListener('change', () => { equipmentModule = null; selected = null; search(); if (fit) renderFit(); }, { signal: events.signal });
    element('search').addEventListener('input', () => { equipmentModule = null; element('category').value = 'All'; search(); }, { signal: events.signal });
    element('name').addEventListener('change', changed, { signal: events.signal });
    element('skills').addEventListener('change', changed, { signal: events.signal });
    for (const mode of ['Defense', 'Propulsion', 'Sharpshooter']) {
        element('mode-' + mode).addEventListener('click', () => {
            if (catalog[fit?.ship_type_id]?.groupID !== 1305 || (fit.mode || 'Defense') === mode) return;
            fit.mode = mode;
            changed();
        }, { signal: events.signal });
    }
    try {
        worker = new Worker(new URL('./fit-stats-worker.js' + new URL(import.meta.url).search, import.meta.url), { type: 'module' });
        worker.onerror = () => {
            clearTimeout(timer);
            element('controls').disabled = true;
            showStatus('Unable to run the fitting engine. Reload the page to try again.');
        };
        worker.onmessage = ({ data }) => {
            if (!alive) {
                clearTimeout(timer);
                calculationPending = false;
                queuedCalculation = null;
                return;
            }
            clearTimeout(timer);
            if (calculationPending) {
                calculationPending = false;
                if (queuedCalculation) {
                    const request = queuedCalculation;
                    queuedCalculation = null;
                    postCalculation(request);
                }
            }
            if (data.id !== sequence) return;
            element('stats').setAttribute('aria-busy', 'false');
            if (data.error) { showStatus(data.error); return; }
            if (data.catalog) {
                catalog = data.catalog;
                types = Object.values(catalog).sort((a, b) => a.name.localeCompare(b.name));
                if (window.jQuery && jQuery.fn.zz_search) {
                    shipSearch = jQuery(element('ship'));
                    shipSearch.zz_search((match, event) => {
                        if (event) event.preventDefault();
                        if (match) loadFit({ ship_type_id: match.id, name: 'New fit', items: [] });
                    }, (query, done) => {
                        done(types.filter(type => type.categoryID === 6 && type.name.toLowerCase().includes(query.toLowerCase())).slice(0, 20).map(type => ({
                            id: type.id, name: type.name, type: 'ship', image: 'https://images.evetech.net/types/' + type.id + '/render?size=32'
                        })));
                    });
                }
                element('controls').disabled = false;
                element('status').textContent = 'Choose a ship or import an EFT fit.';
                const incoming = new URLSearchParams(window.location.hash.slice(1));
                const incomingFit = incoming.get('fit');
                const incomingEFT = incoming.get('eft');
                if (incomingFit !== null || incomingEFT !== null) {
                    if (incomingEFT !== null) element('eft-modal-text').value = incomingEFT;
                    try {
                        restoring = false;
                        loadFit(incomingFit !== null ? importTypeIDFit(incomingFit, catalog) : importEFT(incomingEFT, catalog));
                        window.history?.replaceState(null, '', window.location.pathname + window.location.search);
                    }
                    catch (error) { showStatus('Unable to load this fit: ' + error.message); }
                    if (!fit) element('hull').textContent = 'Choose a ship to begin';
                    return;
                }
                try {
                    const saved = JSON.parse(localStorage.getItem('zkb:simulate'));
                    if (saved) {
                        // Validate without round-tripping away slot positions and module state.
                        importEFT(exportEFT(saved, catalog), catalog);
                        if (savedUI?.history?.[savedUI.historyIndex] === JSON.stringify(saved)) {
                            fitHistory.push(...savedUI.history);
                            historyIndex = savedUI.historyIndex;
                        }
                        loadFit(saved);
                    }
                } catch (error) { showStatus('Saved fit could not be restored. Choose a ship or import EFT.'); }
                if (!fit) element('hull').textContent = 'Choose a ship to begin';
                if (savedUI) {
                    element('search').value = savedUI.search || '';
                    element('category').value = savedUI.category || 'All';
                    element('ship').value = savedUI.ship ?? element('ship').value;
                    element('name').value = savedUI.name ?? element('name').value;
                    element('eft-modal-text').value = savedUI.eft || '';
                    expandedSections.clear();
                    for (const title of savedUI.expanded || []) expandedSections.add(title);
                    for (const [key, value] of savedUI.groups || []) equipmentGroups.set(key, value);
                    for (const [key, value] of savedUI.charges || []) revealedCharges.set(key, value);
                    selected = savedUI.selected;
                    equipmentModule = catalog[savedUI.equipmentModule];
                }
                search();
                element('results').scrollTop = savedUI?.resultsScroll || 0;
                restoring = false;
                return;
            }
            element('status').textContent = '';
            renderStats(data);
            if (savedUI?.pageScroll) {
                window.scrollTo?.(0, savedUI.pageScroll);
                savedUI.pageScroll = 0;
            }
        };
        worker.postMessage({ id: ++sequence, catalog: true });
        timer = setTimeout(() => {
            worker.terminate();
            showStatus('Fitting data timed out. Reload the page to try again.');
        }, 60000);
    } catch (error) { showStatus('This browser could not start the fitting engine.'); }
};

window.zkbInitSimulate();
