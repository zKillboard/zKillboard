$(document).ready(function() {
    zkbInitScanalyzer();
});

function zkbInitScanalyzer() {
    window.zkbPageCleanup = function() {
        if (scanCall != undefined) clearTimeout(scanCall);
        scanCall = undefined;
        if (scanalyzerRowObserver) scanalyzerRowObserver.disconnect();
        scanalyzerRowObserver = undefined;
    };
    scanReadyCharCheck();
}

window.zkbInitScanalyzer = zkbInitScanalyzer;

function scanReadyCharCheck() {
    /*if (characterID == -1) return setTimeout(scanReadyCharCheck, 100);
    if (characterID == 0) {
        $(".contentrequiredlogin.content").remove();
    } else {
        $(".contentrequiredlogin.login").remove();
        scanReady();
    }*/
    scanReady();
}

function scanReady() {
    $('#scaninput').off('blur.zkb-scanalyzer').on('blur.zkb-scanalyzer', startProcess);
    $('#scaninputtoggle').off('click.zkb-scanalyzer').on('click.zkb-scanalyzer', toggleScanInput);

    if (navigator.clipboard === undefined) $("#clip").hide();
    else $('#clippy').off('click.zkb-scanalyzer').on('click.zkb-scanalyzer', copypasta);

    clearInput();
    $("#clippy").removeAttr("disabled");
}

function toggleScanInput() {
    let collapsed = $('#scan-input-column').hasClass('d-none');
    $('#scan-input-column').toggleClass('d-none', !collapsed);
    $('#scan-results-column').toggleClass('col-lg-10', collapsed).toggleClass('col-lg-12', !collapsed);
    $('#scaninputtoggle')
        .attr('aria-expanded', collapsed ? 'true' : 'false')
        .attr('aria-label', collapsed ? 'Hide scan input' : 'Show scan input')
        .attr('title', collapsed ? 'Hide scan input' : 'Show scan input')
        .find('#scaninputtoggleicon')
        .text(collapsed ? '◀ Input' : '▶ Input');
}

function clearInput() {
    $('#scaninput').val('');
    updateStatus('awaiting your input');
}

async function copypasta() {
    let val = await navigator.clipboard.readText();
    if (val.trim().length < 3) return updateStatus('no usable text in the clipboard');
    $("#scaninput").val(val);
    if (!$('#scan-input-column').hasClass('d-none')) toggleScanInput();
    startProcess();
    return false;
}

var scanCall = undefined;
function startProcess() {
    if (!document.getElementById('scaninput')) return;
    if (scanalyzerRowObserver) scanalyzerRowObserver.disconnect();
    scanalyzerRowObserver = undefined;
    $("#clippy").attr("disabled", "true");
    $('#resultssection').hide();
    $('#resultcounts').html('');
    $('#playergroups').html('');
    $('#shipgroups').html('');
    $('#scanlayout').removeClass('has-ships');

    if (scanCall != undefined) clearTimeout(scanCall);
    scanCall = setTimeout(doScan, 1);
}

function doScan() {
    if (!document.getElementById('scaninput')) return;
    updateStatus('fetching');
    scanCall = undefined;

    let val = $("#scaninput").val();
    if (val.length < 3) return updateStatus('valid input please');
    if (val.length > 25000) return updateStatus('input too large! 25000 character limit');

    $("#results").html('');
    $("#playergroups").html('');
    $("#shipgroups").html('');

    $("#scaninput").attr('disabled', 'true');
    let json = {scan: JSON.stringify(val)};
    $.ajax('/cache/bypass/scan/', {
method: 'post',
data: json,
dataType: 'json',
success: async function(r) {
    try {
        await showResult(r);
    } finally {
        showDone();
    }
},
error: function(a, b, c) {
    showError(a, b, c);
    showDone();
}
});
}

function getImage(corp, alli) {
    if (alli) {
        let name = getName('alli', alli);
        let img = `<img class="eveimage img-rounded" style='height: 40px;' src='https://images.evetech.net/alliances/${alli}/logo?size=64' title="${name}" />`
            return `<a href='/alliance/${alli}/'>${img}</a>`
    }
    if (corp) {
        let name = getName('corp', corp);
        let img = `<img class="eveimage img-rounded" style='height: 40px;' src='https://images.evetech.net/corporations/${corp}/logo?size=64' title="${name}" />`
        return `<a href='/corporation/${corp}/'>${img}</a>`
    }
    return '';
}

function getName(type, id) {
    try {
        let i = result[type][id];
        if (typeof i.name == 'undefined') return '';


        if (type == 'corps') return `<a href='/corporation/${i.id}/'>[${i.ticker}]</a>`;
        return `<a href='/alliance/${i.id}/'>&lt;${i.ticker}&gt;</a>`;
    } catch (e) {
        return '';
    }
}

function finiteOrBlank(value) {
    if (value === '' || value === null || typeof value == 'undefined') return '';
    let number = Number(value);
    return Number.isFinite(number) ? number : '';
}

function shipImages(characterID, ships) {
    let html = '';
    for (let ship of ships) {
        html += `<a href='/character/${characterID}/reset/ship/${ship.shipTypeID}/'><img class="eveimage img-rounded" src="https://images.evetech.net/types/${ship.shipTypeID}/render?size=64" style='width: 40px;' title="${ship.shipName}: ${ship.appearances} appearances (${ship.kills} kills, ${ship.losses} losses)" /></a>`;
    }
    return html;
}

function formatScanalyzerRow(row) {
    row.find("[format='format-int-once']").each(function() {
        let field = $(this);
        doFieldUpdate(field, Number(getRawValue(field) || 0).toLocaleString());
        removeFormatMarker(field);
    });
    row.find("[format='format-pct-once']").each(function() {
        let field = $(this);
        doFieldUpdate(field, (Number(getRawValue(field) || 0) + '%').toLocaleString());
        removeFormatMarker(field);
    });
    row.find("[format='format-dec2-once']").each(function() {
        let field = $(this);
        doFieldUpdate(field, parseFloat(getRawValue(field)).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        removeFormatMarker(field);
    });
}

function popChar(ch) {
    let type = ch.allianceID > 0 ? 'alli' : 'corp';
    let id = ch.allianceID > 0 ? ch.allianceID : ch.corporationID;
    ch.stats = ch.stats || {};
    let labels = (ch.labels || []).slice();
    let badges = [];
    ch.ships = ch.ships || [];
    ch.topShips = ch.topShips || [];

    let ships = shipImages(ch.id, ch.ships);
    let topShips = shipImages(ch.id, ch.topShips);

    ch.stats.shipsDestroyed = Number(ch.stats.shipsDestroyed) | 0;
    ch.stats.shipsLost = Number(ch.stats.shipsLost) | 0;
    ch.stats.awoxCount = Number(ch.stats.awoxCount) | 0;
    ch.stats.dangerRatio = finiteOrBlank(ch.stats.dangerRatio);
    if (ch.stats.dangerRatio === '') {
        if (ch.stats.shipsLost > 0) {
            let destroyed = ch.stats.shipsDestroyed + (Number(ch.stats.pointsDestroyed) || 0);
            let lost = ch.stats.shipsLost + (Number(ch.stats.pointsLost) || 0);
            if (destroyed > 0 || lost > 0) ch.stats.dangerRatio = Math.floor((destroyed / (lost + destroyed)) * 100);
        } else if (ch.stats.shipsDestroyed > 0) {
            ch.stats.dangerRatio = 100;
        }
    }
    ch.stats.snuggly = ch.stats.dangerRatio === '' ? '' : 100 - ch.stats.dangerRatio;
    let char = ch.id > 0 ? `<a href='/character/${ch.id}/'>${ch.name}</a>` : ch.name;
    let secStatus = ch.id > 0 && typeof ch.secStatus != 'undefined' ? ch.secStatus : '?';
    if (secStatus == '?') labels.unshift('?');
    let secStatusFormat = secStatus == '? ' ? '' : 'format-dec2-once';
    let corp = getName('corps', ch.corporationID);
    let alli = getName('allis', ch.allianceID);
    let image = getImage(ch.corporationID, ch.allianceID);
    let secColor = getStatusColor(ch.secStatus);
    let negativeSecurity = Number(secStatus) < 0;
    let secTextColor = negativeSecurity ? '#fff' : secColor;
    let secBackground = negativeSecurity ? secColor : '#000';
    if (typeof ch.secStatus == 'undefined') ch.secStatus = 0;
    ch.stats.gangRatio = finiteOrBlank(ch.stats.gangRatio);
    ch.stats.avgGangSize = finiteOrBlank(ch.stats.avgGangSize);
    if (!(ch.stats.shipsDestroyed > 1)) ch.stats.gangRatio = '';
    ch.stats.soloRatio = ch.stats.gangRatio === '' && ch.stats.shipsDestroyed > 1 ? '' : 100 - ch.stats.gangRatio;
    if (ch.stats.shipsDestroyed == 0) {
        ch.stats.soloRatio = '';
        ch.stats.avgGangSize = '';
    }
    if (ch.unknown == true) labels.push('no known kb activity');
    else if (ch.inactive == true) labels.push('no recent kb activity');
    ch.stats.gankerCount = Number(ch.stats.gankerCount) | 0;
    if (ch.stats.gankerCount >= 10) badges.push(`<span class="badge bg-danger text-white" title="${ch.stats.gankerCount} past-year highsec ganks">GANKER (${ch.stats.gankerCount})</span>`);
    if (ch.stats.awoxCount > 0) badges.push(`<span class="badge bg-danger">AWOX (${ch.stats.awoxCount})</span>`);
    if (ch.stats.fc) {
        let fcLevel = String(ch.stats.fc.level || '').toUpperCase();
        let fcTitle = `Past-year FC signal: ${Number(ch.stats.fc.monitorAppearances) || 0} Monitor, ${Number(ch.stats.fc.commandShipAppearances) || 0} command-ship, ${Number(ch.stats.fc.largeFleetAppearances) || 0} large-fleet appearances`;
        badges.push(`<span class="badge text-white" style="background-color: #963800;" title="${fcTitle}">FC (${fcLevel})</span>`);
    }
    if (ch.stats.bait) {
        let baitLevel = String(ch.stats.bait.level || '').toUpperCase();
        let baitCount = Number(ch.stats.bait.count) || 0;
        let baitClass = baitLevel == 'HIGH' ? 'bg-danger' : (baitLevel == 'LOW' ? 'bg-secondary' : '');
        let baitStyle = baitLevel == 'MEDIUM' ? ' style="background-color: #963800;"' : '';
        badges.push(`<span class="badge text-white ${baitClass}"${baitStyle} title="${baitCount} past-year bait matches">BAIT ${baitLevel} (${baitCount})</span>`);
    }
    if (ch.stats.cyno) {
        let cynoCount = Number(ch.stats.cyno.count) || 0;
        let cynoTitle = `Past-year fitted cynos: ${Number(ch.stats.cyno.standard) || 0} standard, ${Number(ch.stats.cyno.covert) || 0} covert, ${Number(ch.stats.cyno.industrial) || 0} industrial`;
        badges.push(`<span class="badge text-white" style="background-color: #633399;" title="${cynoTitle}">CYNO (${cynoCount})</span>`);
    }

    let soloColor = '';
    if (ch.stats.shipsDestroyed > 10 && ch.stats.soloRatio >= 50) soloColor = 'green';

    let notes = labels.join(', ');
    let badgeNotes = badges.join(' ');

    let nameCell = `<td>${char}<br/><small class="d-flex align-items-center gap-1"><span>Sec: <span class="fw-bold rounded px-1" style="color: ${secTextColor}; background-color: ${secBackground}; border: 1px solid ${secColor}; box-shadow: 0 0 3px ${secColor};" format="${secStatusFormat}" raw="${secStatus}"></span> <span>${notes}</span></span><span class="ms-auto text-nowrap">${badgeNotes}</span></small></td>`;
    let imageCell = `<td class='pilotmemberimage'>${image}</td>`;
    let memberCell = `<td class="pilotmember">${corp}<br/>${alli}</td>`;
    let current = ch.scanalyzerElement;
    if (current && current.length && current[0].isConnected) {
        let currentCells = current.children();
        let update = $(`<tr>${nameCell}${imageCell}${memberCell}</tr>`);
        formatScanalyzerRow(update);
        let cells = update.children();
        currentCells.eq(0).replaceWith(cells.eq(0));
        currentCells.eq(3).replaceWith(cells.eq(1));
        currentCells.eq(4).replaceWith(cells.eq(2));
    } else {
        let h = $(`<tr data-scanalyzer-row="${ch.scanalyzerRow}" danger="${ch.stats.dangerRatio}">${nameCell}<td class='pilotships'>${ships}</td><td class='pilotships'>${topShips}</td>${imageCell}${memberCell}<td class="text-end"><span class="pilotkl green" format="format-int-once" raw="${ch.stats.shipsDestroyed}"></span><br/><span class="red" format="format-int-once" raw="${ch.stats.shipsLost}"></span></td><td class="pilotds text-end"><span class="red" format="format-pct-once" raw="${ch.stats.dangerRatio}"></span><br/><span class="green" format="format-pct-once" raw="${ch.stats.snuggly}"></span></td><td class="text-end"><span format="format-pct-once" raw="${ch.stats.gangRatio}"></span><br/><span format="format-dec2-once" raw="${ch.stats.avgGangSize}"></td><td class='text-end ${soloColor}' format="format-pct-once" raw="${ch.stats.soloRatio}"></td></tr>`);
        formatScanalyzerRow(h);
        $('#results').append(h);
        ch.scanalyzerElement = h;
    }
}

function popUEs() {
    mapping = {corps: {}, allis: {}};
    for (let character of result.chars) {
        if (character.allianceID) mapping.allis[character.allianceID] = (mapping.allis[character.allianceID] | 0) + 1;
        else if (character.corporationID) mapping.corps[character.corporationID] = (mapping.corps[character.corporationID] | 0) + 1;
    }
    $('#playergroups').html('');
    Object.keys(mapping.allis).forEach(popUEa);
    Object.keys(mapping.corps).forEach(popUEc);
}

function popUEa(alli) {
    let count = mapping.allis[alli];
    let info = result.allis[alli] || {};
    let name = info.name || '';
    let ticker = info.ticker || '';
    let img = `<img class="eveimage img-rounded" src='https://images.evetech.net/alliances/${alli}/logo?size=64' title="${name}" />`
    let link = `<a href='/alliance/${alli}/' class='nowrap'>&lt;${ticker}&gt;</a>`;
    let h = $(`<div style='order: -${count}' class='float-start scan-entity text-center'>${img}<br/>${link}<br/><div class='text-center'>${count}</div></div>`);
    $('#playergroups').append(h);
}

function popShip(ship) {
    let img = `<img src="https://images.evetech.net/types/${ship.shipTypeID}/render?size=64" alt="${ship.shipName}" />`;
    let link = `<a href='/ship/${ship.shipTypeID}/'>${ship.shipName}</a>`;
    let h = $(`<div style='order: -${ship.count}' class='float-start scan-entity text-center'>${img}<br/>${link}<br/><span format="format-int-once" raw="${ship.count}"></span></div>`);
    $('#shipgroups').append(h);
}

function popUEc(corp) {
    let count = mapping.corps[corp];
    let info = result.corps[corp] || {};
    let name = info.name || '';
    let ticker = info.ticker || '';
    let img = `<img class="eveimage img-rounded" src='https://images.evetech.net/corporations/${corp}/logo?size=64' title="${name}" />`
        let link = `<a href='/corporation/${corp}/' class='nowrap'>[${ticker}]</a>`
        let h = $(`<div style='order: -${count}' class='float-start scan-entity text-center'>${img}<br/>${link}<br/><div class='text-center'>${count}</div></div>`);
    $('#playergroups').append(h);
}

let scanalyzerEsiDb;

function getScanalyzerEsiDb() {
    if (scanalyzerEsiDb) return scanalyzerEsiDb;
    if (!window.indexedDB) return Promise.resolve(null);

    scanalyzerEsiDb = new Promise(function(resolve) {
        let request = window.indexedDB.open('zkillboard-scanalyzer');
        request.onupgradeneeded = function() {
            request.result.createObjectStore('esi', {keyPath: 'key'});
        };
        request.onsuccess = function() { resolve(request.result); };
        request.onerror = function() { resolve(null); };
        request.onblocked = function() { resolve(null); };
    });
    return scanalyzerEsiDb;
}

async function getCachedEsiInfo(keys) {
    if (keys.length == 0) return {};
    let db = await getScanalyzerEsiDb();
    if (!db) return {};

    return new Promise(function(resolve) {
        let values = {};
        let transaction = db.transaction('esi', 'readwrite');
        let store = transaction.objectStore('esi');
        for (let key of keys) {
            let request = store.get(key);
            request.onsuccess = function() {
                let value = request.result;
                if (value && value.expiresAt > Date.now()) values[key] = value.data;
                else if (value) store.delete(key);
            };
        }
        transaction.oncomplete = function() { resolve(values); };
        transaction.onerror = function() { resolve({}); };
        transaction.onabort = function() { resolve({}); };
    });
}

async function cacheEsiInfo(values) {
    if (values.length == 0) return;
    let db = await getScanalyzerEsiDb();
    if (!db) return;

    return new Promise(function(resolve) {
        let transaction = db.transaction('esi', 'readwrite');
        let store = transaction.objectStore('esi');
        for (let value of values) {
            store.put({key: value.key, data: value.data, expiresAt: Date.now() + 86400000});
        }
        transaction.oncomplete = function() { resolve(); };
        transaction.onerror = function() { resolve(); };
        transaction.onabort = function() { resolve(); };
    });
}

async function fetchEsiDetails(entities, onDetail) {
    let details = [];
    for (let i = 0; i < entities.length; i += 10) {
        let batch = await Promise.all(entities.slice(i, i + 10).map(async function(entity) {
            try {
                let response = await fetch(`https://esi.evetech.net/${entity.endpoint}/${entity.id}/?datasource=tranquility`, {
                    headers: {'Accept': 'application/json', 'X-User-Agent': 'zkillboard.com ScanAlyzer'}
                });
                if (!response.ok) return null;
                let detail = {entity: entity, detail: await response.json()};
                if (onDetail) onDetail(detail);
                return detail;
            } catch (e) {
                return null;
            }
        }));
        details.push(...batch.filter(Boolean));
    }
    return details;
}

async function enrichUnknownCharacters(scanResult, onCharacter, onAffiliation) {
    if (Array.isArray(scanResult.corps)) scanResult.corps = Object.assign({}, scanResult.corps);
    if (Array.isArray(scanResult.allis)) scanResult.allis = Object.assign({}, scanResult.allis);

    let unknownByName = {};
    for (let character of scanResult.chars) {
        if (!(Number(character.id) > 0)) unknownByName[character.name.toLowerCase()] = character;
    }

    let characterNames = Object.keys(unknownByName);
    if (characterNames.length == 0) return 0;

    let affiliations = [];
    let updated = 0;
    let applyCharacter = function(response) {
        let character = unknownByName[response.entity.name.toLowerCase()];
        if (!character) return;
        let detail = response.detail;
        character.id = Number(response.entity.id);
        character.name = detail.name || response.entity.name;
        character.corporationID = Number(detail.corporation_id) || 0;
        character.allianceID = Number(detail.alliance_id) || 0;
        character.factionID = Number(detail.faction_id) || 0;
        if (detail.security_status != null) character.secStatus = Number(detail.security_status);
        character.inactive = true;
        delete character.unknown;

        if (character.corporationID > 0 && (!scanResult.corps[character.corporationID] || typeof scanResult.corps[character.corporationID].name == 'undefined')) {
            affiliations.push({endpoint: 'corporations', id: character.corporationID});
        }
        if (character.allianceID > 0 && (!scanResult.allis[character.allianceID] || typeof scanResult.allis[character.allianceID].name == 'undefined')) {
            affiliations.push({endpoint: 'alliances', id: character.allianceID});
        }
        updated++;
        if (onCharacter) onCharacter(character);
    };

    let cachedCharacters = await getCachedEsiInfo(characterNames.map(function(name) { return `characters:${name}`; }));
    for (let name of characterNames) {
        let cached = cachedCharacters[`characters:${name}`];
        if (cached) applyCharacter(cached);
    }

    let unknownNames = characterNames
        .filter(function(name) { return !cachedCharacters[`characters:${name}`]; })
        .map(function(name) { return unknownByName[name].name; });
    let characterDetails = [];
    if (unknownNames.length > 0) {
        updateStatus('fetching missing characters from ESI');
        let resolved = [];
        try {
            for (let i = 0; i < unknownNames.length; i += 500) {
                let response = await fetch('https://esi.evetech.net/universe/ids/?datasource=tranquility', {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-User-Agent': 'zkillboard.com ScanAlyzer'},
                    body: JSON.stringify(unknownNames.slice(i, i + 500))
                });
                if (!response.ok) throw new Error(`ESI returned ${response.status}: ${await response.text()}`);
                let entities = await response.json();
                resolved.push(...(entities.characters || []));
            }
        } catch (e) {
            console.warn('Unable to resolve ScanAlyzer character names through ESI', e);
        }

        let characterRequests = resolved
            .filter(function(character) { return unknownByName[character.name.toLowerCase()]; })
            .map(function(character) { return {endpoint: 'characters', id: character.id, name: character.name}; });
        characterDetails = await fetchEsiDetails(characterRequests, applyCharacter);
        await cacheEsiInfo(characterDetails.map(function(response) {
            return {
                key: `characters:${response.entity.name.toLowerCase()}`,
                data: {
                    entity: response.entity,
                    detail: {
                        name: response.detail.name,
                        corporation_id: response.detail.corporation_id,
                        alliance_id: response.detail.alliance_id,
                        faction_id: response.detail.faction_id,
                        security_status: response.detail.security_status
                    }
                }
            };
        }));
    }

    affiliations = Array.from(new Map(affiliations.map(function(entity) {
        return [`${entity.endpoint}:${entity.id}`, entity];
    })).values());
    let applyAffiliation = function(response) {
        let target = response.entity.endpoint == 'corporations' ? scanResult.corps : scanResult.allis;
        target[response.entity.id] = {
            id: Number(response.entity.id),
            name: response.detail.name || '',
            ticker: response.detail.ticker || ''
        };
        if (onAffiliation) onAffiliation(response.entity);
    };

    let cachedAffiliations = await getCachedEsiInfo(affiliations.map(function(entity) { return `${entity.endpoint}:${entity.id}`; }));
    for (let entity of affiliations) {
        let cached = cachedAffiliations[`${entity.endpoint}:${entity.id}`];
        if (cached) applyAffiliation(cached);
    }

    let affiliationRequests = affiliations.filter(function(entity) {
        return !cachedAffiliations[`${entity.endpoint}:${entity.id}`];
    });
    if (affiliationRequests.length > 0) updateStatus('fetching missing characters from ESI');
    let affiliationDetails = await fetchEsiDetails(affiliationRequests, applyAffiliation);
    await cacheEsiInfo(affiliationDetails.map(function(response) {
        return {
            key: `${response.entity.endpoint}:${response.entity.id}`,
            data: {
                entity: response.entity,
                detail: {name: response.detail.name, ticker: response.detail.ticker}
            }
        };
    }));
    return updated;
}

let result = undefined;
let mapping = undefined;
let scanalyzerRowObserver;

function renderCharacterResults() {
    mapping = {corps: {}, allis: {}};
    $('#results').html('');
    result.chars.forEach(popChar);
    popUEs();
}

async function showResult(r) {
    if (!document.getElementById('scaninput')) return;
    result = r;
    result.chars.forEach(function(character, index) { character.scanalyzerRow = index; });

    let affiliationRows = {corporations: {}, alliances: {}};
    let indexAffiliations = function(character) {
        for (let affiliation of [
            {endpoint: 'corporations', id: Number(character.corporationID) || 0},
            {endpoint: 'alliances', id: Number(character.allianceID) || 0}
        ]) {
            if (affiliation.id == 0) continue;
            if (!affiliationRows[affiliation.endpoint][affiliation.id]) affiliationRows[affiliation.endpoint][affiliation.id] = new Set();
            affiliationRows[affiliation.endpoint][affiliation.id].add(character);
        }
    };
    result.chars.forEach(indexAffiliations);

    let queuedCharacters = new Map();
    let deferredCharacters = new Map();
    let visibleRows = new Set();
    let updateFrame = null;
    let updateWaiters = [];
    let flushCharacterUpdates = function() {
        updateFrame = null;
        let started = performance.now();
        let count = 0;
        while (queuedCharacters.size > 0) {
            let [row, character] = queuedCharacters.entries().next().value;
            queuedCharacters.delete(row);
            popChar(character);
            if (++count == 5 || performance.now() - started >= 8) break;
        }
        if (queuedCharacters.size > 0) {
            updateFrame = window.requestAnimationFrame(flushCharacterUpdates);
        } else {
            updateWaiters.splice(0).forEach(function(resolve) { resolve(); });
        }
    };
    let queueCharacterUpdate = function(character) {
        if (scanalyzerRowObserver && !visibleRows.has(character.scanalyzerRow)) {
            deferredCharacters.set(character.scanalyzerRow, character);
            return;
        }
        queuedCharacters.set(character.scanalyzerRow, character);
        if (updateFrame == null) updateFrame = window.requestAnimationFrame(flushCharacterUpdates);
    };
    let waitForCharacterUpdates = function() {
        if (queuedCharacters.size == 0 && updateFrame == null) return Promise.resolve();
        return new Promise(function(resolve) { updateWaiters.push(resolve); });
    };

    console.log(result);
    if (result.chars.length == 0 && result.ships.length == 0) {
        $("#resultcounts").html('');
        return updateStatus('nothing to show here - did you provide valid input?');
    }

    if (result.chars.length == 0) $("#pilotentities").hide();
    else $("#pilotentities").show();

    let resultcount = '';
    if (result.chars.length > 0) {
        resultcount = result.chars.length + ' characters';
        if (result.ships.length > 0) resultcount += ' and ';
    }
    if (result.ships.length) resultcount += result.ships.length + ' ships';
    resultcount += ' identified';
    //$("#resultcounts").html(`<i>${resultcount}</i>`);

    renderCharacterResults();
    if (window.IntersectionObserver) {
        scanalyzerRowObserver = new IntersectionObserver(function(entries) {
            for (let entry of entries) {
                let row = Number(entry.target.dataset.scanalyzerRow);
                if (entry.isIntersecting) {
                    visibleRows.add(row);
                    let character = deferredCharacters.get(row);
                    if (character) {
                        deferredCharacters.delete(row);
                        queueCharacterUpdate(character);
                    }
                } else {
                    visibleRows.delete(row);
                }
            }
        }, {rootMargin: '200px 0px'});
        result.chars.forEach(function(character) { scanalyzerRowObserver.observe(character.scanalyzerElement[0]); });
    }
    if (result.ships.length > 0) {
        $('#scanlayout').addClass('has-ships');
    } else {
        $('#scanlayout').removeClass('has-ships');
    }
    result.ships.forEach(popShip);

    doFormats();
    updateStatus('');

    await enrichUnknownCharacters(result, function(character) {
        indexAffiliations(character);
        queueCharacterUpdate(character);
    }, function(entity) {
        let characters = affiliationRows[entity.endpoint][entity.id] || [];
        for (let character of characters) queueCharacterUpdate(character);
    });
    await waitForCharacterUpdates();
    popUEs();
    updateStatus('');
}

function showError(a, b, c) {
    if (!document.getElementById('scaninput')) return;
    updateStatus('an error! check the console for details');
    console.log('error', a, b, c);
}

function showDone() {
    if (!document.getElementById('scaninput')) return;
    $("#scaninput").removeAttr('disabled');
    $("#clippy").removeAttr("disabled");
}

function updateStatus(msg = '') {
    if (!document.getElementById('status')) return;
    if (msg == '') {
        $('#status').html('').hide();
        $('#resultssection').show();
    } else {
        $('#status').html(`<i>... ${msg} ...</i>`).show();
    }
}

function getStatusColor(sec) {
    let calcStatus = sec;
    if (calcStatus > 5) calcStatus = 5;
    if (calcStatus < -5) calcStatus = -5; 
    calcStatus = (calcStatus / 5) + 0.8;
    if (calcStatus > 1) calcStatus = 1;

    switch (calcStatus) {
        case 1.0:
            return '#2c74e0';
        case 0.9:
            return '#3a9aeb';
        case 0.8:
            return '#4ecef8';
        case 0.7:
            return '#60d9a3';
        case 0.6:
            return '#71e554';
        case 0.5:
            return '#f3fd82';
        case 0.4:
            return '#DC6D07';
        case 0.3:
            return '#ce440f';
        case 0.2:
            return '#bc1117';
        case 0.1:
            return '#722020';
        default:
            return '#8d3264';
    }
}
