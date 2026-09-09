(function() {
    var statsState = null;

    function failFitStats(state, message) {
        if (state.statsWorker) state.statsWorker.terminate();
        state.statsWorker = null;
        state.statsRequests.forEach(function(request) {
            clearTimeout(request.timer);
            request.output.textContent = message;
            request.output.dataset.loading = 'false';
        });
        state.statsRequests.clear();
    }

    function calculateFitClick(event) {
        var button = event.target.closest('[data-zkb-calculate-fit]');
        if (!button || !statsState) return;
        var panel = button.closest('[data-zkb-fit-stats]') || document.getElementById(button.getAttribute('data-zkb-calculate-fit'));
        if (!panel) return;
        var output = panel.querySelector('[data-zkb-fit-stats-output]');
        var open = output.hidden;
        output.hidden = !open;
        panel.hidden = !panel.contains(button) && !open;
        panel.querySelector('[data-zkb-fit-stats-notice]').hidden = !open;
        document.querySelectorAll('[data-zkb-calculate-fit]').forEach(function(toggle) {
            if (toggle.getAttribute('aria-controls') !== output.id) return;
            toggle.setAttribute('aria-expanded', String(open));
            var icon = toggle.querySelector('i');
            icon.classList.toggle('fa-chevron-down', !open);
            icon.classList.toggle('fa-chevron-up', open);
        });
        if (!open || output.dataset.loaded === 'true' || output.dataset.loading === 'true') return;
        var state = statsState;
        output.dataset.loading = 'true';
        output.textContent = 'Loading fitting data and calculating stats...';

        try {
            if (!state.statsWorker) {
                state.statsWorker = new Worker('/js/fit-stats-worker.js', { type: 'module' });
                state.statsWorker.onerror = function() {
                    failFitStats(state, 'Unable to load fitting statistics. Please try again.');
                };
                state.statsWorker.onmessage = function(event) {
                    var request = state.statsRequests.get(event.data.id);
                    if (!request) return;
                    clearTimeout(request.timer);
                    state.statsRequests.delete(event.data.id);
                    request.output.dataset.loading = 'false';
                    if (event.data.error) {
                        request.output.textContent = event.data.error;
                        return;
                    }
                    var stats = event.data.stats;
                    function number(name, unit, divisor, values) {
                        var value = (values || stats)[name];
                        return Number.isFinite(value) ? (value / (divisor || 1)).toLocaleString(undefined, { maximumFractionDigits: 2 }) + (unit || '') : 'Unavailable';
                    }
                    function section(title, rows) {
                        var column = document.createElement('section');
                        column.className = 'col-12 col-md-6';
                        var heading = document.createElement('h3');
                        heading.className = 'h6 border-bottom pb-2 mx-0';
                        heading.textContent = title;
                        var list = document.createElement('dl');
                        list.className = 'row gx-0 small mb-0';
                        rows.forEach(function(row) {
                            var label = document.createElement('dt');
                            label.className = 'col-6 pe-2';
                            label.textContent = row[0];
                            var value = document.createElement('dd');
                            value.className = 'col-6';
                            value.textContent = row[1];
                            list.append(label, value);
                        });
                        column.append(heading, list);
                        grid.append(column);
                    }
                    var capacitor = 'Unavailable';
                    if (Number.isFinite(stats.capacitorDepletesIn)) {
                        capacitor = stats.capacitorDepletesIn < 0 ? 'Stable' : number('capacitorDepletesIn', ' s');
                    }
                    var grid = document.createElement('div');
                    grid.className = 'row g-3';
                    section('Fitting', [
                        ['CPU', number('cpuLoad') + ' / ' + number('cpuOutput', ' tf')],
                        ['Powergrid', number('powerLoad') + ' / ' + number('powerOutput', ' MW')],
                        ['Calibration', number('upgradeLoad') + ' / ' + number('upgradeCapacity')],
                        ['High / Mid / Low slots', number('hiSlots') + ' / ' + number('medSlots') + ' / ' + number('lowSlots')],
                        ['Turret / Launcher hardpoints free', number('turretSlotsLeft') + ' / ' + number('launcherSlotsLeft')],
                        ['Cargo capacity', number('capacity', ' m³')]
                    ]);
                    section('Offense (recorded charges, no active drones)', [
                        ['DPS (without reload)', number('damagePerSecondWithoutReload', ' HP/s')],
                        ['DPS (with reload)', number('damagePerSecondWithReload', ' HP/s')],
                        ['Volley damage', number('damageAlpha', ' HP')]
                    ]);
                    var defense = [
                        ['Effective HP', number('ehp', ' EHP')],
                        ['Shield / Armor / Hull', number('shieldCapacity') + ' / ' + number('armorHP') + ' / ' + number('hp', ' HP')],
                        ['Shield recharge', number('shieldRechargeRate', ' s', 1000)],
                        ['Passive shield tank', number('passiveShieldEffectiveRechargeRate', ' EHP/s')],
                        ['Shield boost', number('shieldEffectiveBoostRate', ' EHP/s')],
                        ['Armor repair', number('armorEffectiveRepairRate', ' EHP/s')],
                        ['Hull repair', number('hullEffectiveRepairRate', ' EHP/s')]
                    ];
                    [['Shield', 'shield'], ['Armor', 'armor'], ['Hull', '']].forEach(function(layer) {
                        var resistances = ['Em', 'Thermal', 'Kinetic', 'Explosive'].map(function(damage) {
                            var key = layer[1] + (layer[1] ? damage : damage.toLowerCase()) + 'DamageResonance';
                            return Number.isFinite(stats[key]) ? ((1 - stats[key]) * 100).toFixed(1) + '%' : 'Unavailable';
                        });
                        defense.push([layer[0] + ' resists (EM / Th / Kin / Exp)', resistances.join(' / ')]);
                    });
                    section('Defense (equal incoming damage)', defense);
                    section('Capacitor', [
                        ['Capacity', number('capacitorCapacity', ' GJ')],
                        ['Sustain', capacitor],
                        ['Recharge time', number('rechargeRate', ' s', 1000)],
                        ['Peak recharge', number('capacitorPeakRecharge', ' GJ/s')],
                        ['Peak surplus / deficit', number('capacitorPeakDelta', ' GJ/s')],
                        ['Peak surplus / deficit %', number('capacitorPeakDeltaPercentage', '%')]
                    ]);
                    section('Targeting', [
                        ['Target range', number('maxTargetRange', ' km', 1000)],
                        ['Locked targets', number('maxLockedTargets')],
                        ['Scan resolution', number('scanResolution', ' mm')],
                        ['Sensor strength', number('scanStrength')],
                        ['Signature radius', number('signatureRadius', ' m')]
                    ]);
                    section('Navigation', [
                        ['Speed', number('maxVelocity', ' m/s')],
                        ['Align time', number('alignTime', ' s')],
                        ['Warp speed', number('warpSpeedMultiplier', ' AU/s')],
                        ['Mass', number('mass', ' t', 1000)],
                        ['Inertia modifier', number('agility')]
                    ]);
                    section('Drones (remain in bay)', [
                        ['Bay used / capacity', number('droneCapacityLoad') + ' / ' + number('droneCapacity', ' m³')],
                        ['Bandwidth capacity', number('droneBandwidth', ' Mbit/s')],
                        ['Control range', number('droneControlDistance', ' km', 1000, event.data.character)],
                        ['Active drone DPS', '0 (none deployed)']
                    ]);
                    var miningRows = [];
                    var miningYield = 0;
                    event.data.details.forEach(function(item) {
                        if (!item.slot || item.slot.type !== 'High') return;
                        var attributes = Object.fromEntries(item.attributes.map(function(attribute) { return [attribute.name, attribute.value]; }));
                        if (!Number.isFinite(attributes.miningAmount) || attributes.miningAmount <= 0) return;
                        var cycle = attributes.cycleTime || attributes.duration;
                        if (!Number.isFinite(cycle) || cycle <= 0) return;
                        miningYield += attributes.miningAmount * 60000 / cycle;
                        miningRows.push(
                            ['High ' + item.slot.index + ': ' + item.name, number('miningAmount', ' m³/cycle', 1, attributes)],
                            ['Cycle / range', number('cycle', ' s', 1000, { cycle: cycle }) + ' / ' + number('maxRange', ' km', 1000, attributes)]
                        );
                    });
                    if (miningRows.length) {
                        section('Mining (excluding critical bonuses)', [
                            ['Combined yield', number('yield', ' m³/min', 1, { yield: miningYield })],
                            ['Mining hold capacity', number('generalMiningHoldCapacity', ' m³')]
                        ].concat(miningRows));
                    }
                    request.output.replaceChildren(grid);
                    var all = document.createElement('details');
                    all.className = 'mt-3';
                    var summary = document.createElement('summary');
                    summary.textContent = 'All calculated attributes: ship, pilot, modules, charges and drones';
                    all.append(summary);
                    event.data.details.forEach(function(item) {
                        var detail = document.createElement('details');
                        detail.className = 'ms-3 mt-2';
                        var title = document.createElement('summary');
                        title.textContent = item.name + (item.slot ? ' · ' + item.slot.type + (item.slot.index ? ' ' + item.slot.index : '') : '') + ' · ' + item.state;
                        var table = document.createElement('table');
                        table.className = 'table table-sm small';
                        var caption = document.createElement('caption');
                        caption.textContent = 'Calculated values in native attribute units (durations in milliseconds unless named otherwise).';
                        var body = document.createElement('tbody');
                        item.attributes.sort(function(a, b) { return a.label.localeCompare(b.label); }).forEach(function(attribute) {
                            var row = document.createElement('tr');
                            var label = document.createElement('th');
                            label.scope = 'row';
                            label.className = 'text-break';
                            label.textContent = attribute.label;
                            label.title = attribute.name;
                            var value = document.createElement('td');
                            value.className = 'text-break';
                            value.textContent = Number.isFinite(attribute.value) ? String(attribute.value) : 'Unavailable';
                            row.append(label, value);
                            body.append(row);
                        });
                        table.append(caption, body);
                        detail.append(title, table);
                        all.append(detail);
                    });
                    request.output.append(all);
                    request.output.dataset.loaded = 'true';
                };
            }
            var id = ++state.statsRequestID;
            state.statsRequests.set(id, {
                output: output,
                timer: setTimeout(function() {
                    failFitStats(state, 'The fitting calculation timed out. Please try again.');
                }, 60000)
            });
            state.statsWorker.postMessage({ id: id, fit: JSON.parse(panel.getAttribute('data-zkb-fit-stats')) });
        } catch (error) {
            failFitStats(state, 'Fitting statistics are unavailable in this browser.');
            output.dataset.loading = 'false';
            output.textContent = 'Fitting statistics are unavailable in this browser.';
        }
    }

    window.zkbCleanupFitStats = function() {
        if (statsState) failFitStats(statsState, '');
        statsState = null;
        document.removeEventListener('click', calculateFitClick);
    };

    window.zkbInitFitStats = function() {
        window.zkbCleanupFitStats();
        statsState = { statsWorker: null, statsRequests: new Map(), statsRequestID: 0 };
        document.addEventListener('click', calculateFitClick);
    };
})();
