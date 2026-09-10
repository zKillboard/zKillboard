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
    $('#scanalyzerexpandall').off('click.zkb-scanalyzer').on('click.zkb-scanalyzer', function() {
        if (!result || !result.chars.length) return;
        let expanded = !result.chars.every(function(character) { return character.scanalyzerExpanded; });
        result.chars.forEach(function(character) {
            character.scanalyzerExpanded = expanded;
            popChar(character);
        });
        updateScanalyzerExpandAll();
    });
    $('#results').off('click.zkb-scanalyzer-expand').on('click.zkb-scanalyzer-expand', '.scanalyzer-row-toggle', function() {
        let character = result && result.chars[Number($(this).closest('tr').attr('data-scanalyzer-row'))];
        if (!character) return;
        character.scanalyzerExpanded = !character.scanalyzerExpanded;
        popChar(character);
        updateScanalyzerExpandAll();
    });

    if (navigator.clipboard === undefined) $("#clip").hide();
    else $('#clippy').off('click.zkb-scanalyzer').on('click.zkb-scanalyzer', copypasta);

    clearInput();
    $("#clippy").removeAttr("disabled");
}

function toggleScanInput() {
    let collapsed = $('#scan-input-column').hasClass('d-none');
    $('#scan-input-column').toggleClass('d-none', !collapsed);
    $('#scan-results-column').toggleClass('col-lg-10', collapsed).toggleClass('col-lg-12', !collapsed);
    (collapsed ? $('#clipinput') : $('#clipresults')).append($('#clip'));
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
    $('#resultcounts').empty();
    $('#playergroups').empty();
    $('#shipgroups').empty();
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

    $("#results").empty();
    $("#playergroups").empty();
    $("#shipgroups").empty();

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
        let name = (result.allis[alli] || {}).name || '';
        let img = $(document.createElement('img')).addClass("eveimage img-rounded").css("height", "40px").attr("src", "https://images.evetech.net/alliances/" + alli + "/logo?size=64").attr("title", name);
        return $(document.createElement('a')).attr("href", "/alliance/" + alli + "/").append(img);
    }
    if (corp) {
        let name = (result.corps[corp] || {}).name || '';
        let img = $(document.createElement('img')).addClass("eveimage img-rounded").css("height", "40px").attr("src", "https://images.evetech.net/corporations/" + corp + "/logo?size=64").attr("title", name);
        return $(document.createElement('a')).attr("href", "/corporation/" + corp + "/").append(img);
    }
    return '';
}

function getName(type, id) {
    try {
        let i = result[type][id];
        if (typeof i.name == 'undefined') return '';


        if (type == 'corps') return $(document.createElement('a')).attr("href", "/corporation/" + i.id + "/").text("[" + i.ticker + "]");
        return $(document.createElement('a')).attr("href", "/alliance/" + i.id + "/").text("<" + i.ticker + ">");
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
    let images = [];
    for (let ship of ships) {
        images.push($(document.createElement('a')).attr("href", "/character/" + characterID + "/reset/ship/" + ship.shipTypeID + "/").append($(document.createElement('img')).addClass("eveimage img-rounded").attr("src", "https://images.evetech.net/types/" + ship.shipTypeID + "/render?size=64").css("width", "40px").attr("title", ship.shipName + ": " + ship.appearances + " appearances (" + ship.kills + " kills, " + ship.losses + " losses)")));
    }
    return images;
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

function updateScanalyzerExpandAll() {
    let expanded = result && result.chars.length > 0 && result.chars.every(function(character) { return character.scanalyzerExpanded; });
    $('#scanalyzerexpandall')
        .attr('aria-expanded', expanded ? 'true' : 'false')
        .attr('aria-label', expanded ? 'Collapse all rows' : 'Expand all rows')
        .attr('title', expanded ? 'Collapse all rows' : 'Expand all rows')
        .find('i')
        .toggleClass('fa-caret-down', expanded)
        .toggleClass('fa-caret-right', !expanded);
}

function popChar(ch) {
    let type = ch.allianceID > 0 ? 'alli' : 'corp';
    let id = ch.allianceID > 0 ? ch.allianceID : ch.corporationID;
    ch.stats = ch.stats || {};
    let labels = (ch.labels || []).slice();
    let badges = [];
    ch.ships = ch.ships || [];
    ch.topShips = ch.topShips || [];

    let expanded = ch.scanalyzerExpanded == true;
    let ships = shipImages(ch.id, ch.ships.slice(0, expanded ? 9 : 5));
    let topShips = shipImages(ch.id, ch.topShips.slice(0, expanded ? 9 : 5));
    let associates = (ch.associates || []).slice(0, expanded ? 50 : 2).map(function(associate) {
        let characterID = Number(associate.characterID);
        let sharedKills = Number(associate.sharedKills) || 0;
        return $(document.createElement('span')).addClass("text-nowrap").attr("title", sharedKills + " shared PvP killmails in the past 90 days").append($(document.createElement('a')).attr("href", "/character/" + characterID + "/").text(associate.name), document.createTextNode(" ("), document.createTextNode(sharedKills.toLocaleString()), document.createTextNode(")"));
    }).flatMap(function(node, index) { return index ? [document.createElement('br'), node] : [node]; });
    let affiliates = (ch.affiliates || []).slice(0, expanded ? 25 : 2).map(function(affiliate) {
        let alliance = getName('allis', Number(affiliate.allianceID));
        let sharedKills = Number(affiliate.sharedKills) || 0;
        return alliance == '' ? '' : $(document.createElement('span')).addClass("text-nowrap").attr("title", sharedKills + " shared PvP killmails in the past 90 days").append(alliance, document.createTextNode(" ("), document.createTextNode(sharedKills.toLocaleString()), document.createTextNode(")"));
    }).filter(Boolean).flatMap(function(node, index) { return index ? [document.createElement('br'), node] : [node]; });

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
    let char = ch.id > 0 ? $(document.createElement('a')).attr("href", "/character/" + ch.id + "/").text(ch.name) : document.createTextNode(ch.name);
    let hasSecurity = ch.id > 0 && ch.secStatus !== null && ch.secStatus !== '' && typeof ch.secStatus != 'undefined';
    let secStatus = hasSecurity ? Number(ch.secStatus) : '';
    let secStatusFormat = hasSecurity ? 'format-dec2-once' : '';
    let corp = getName('corps', ch.corporationID);
    let alli = getName('allis', ch.allianceID);
    let image = getImage(ch.corporationID, ch.allianceID);
    let secColor = getStatusColor(secStatus);
    let secRgb = secColor.match(/[0-9a-f]{2}/gi).map(function(value) {
        value = parseInt(value, 16) / 255;
        return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
    });
    let secLuminance = 0.2126 * secRgb[0] + 0.7152 * secRgb[1] + 0.0722 * secRgb[2];
    let secTextColor = (secLuminance + 0.05) / 0.05 >= 1.05 / (secLuminance + 0.05) ? '#000' : '#fff';
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
    if (ch.stats.gankerCount >= 10) badges.push($(document.createElement('span')).addClass("badge zkb-label-danger text-white").attr("title", ch.stats.gankerCount + " past-year highsec ganks").text("GANKER (" + ch.stats.gankerCount + ")"));
    if (ch.stats.awoxCount > 0) badges.push($(document.createElement('span')).addClass("badge zkb-label-danger text-white").text("AWOX (" + ch.stats.awoxCount + ")"));
    if (ch.stats.fc) {
        let fcLevel = String(ch.stats.fc.level || '').toUpperCase();
        let fcTitle = `Past-year FC signal: ${Number(ch.stats.fc.monitorAppearances) || 0} Monitor, ${Number(ch.stats.fc.commandShipAppearances) || 0} command-ship, ${Number(ch.stats.fc.largeFleetAppearances) || 0} large-fleet appearances`;
        badges.push($(document.createElement('span')).addClass("badge text-white").css("background-color", "#963800").attr("title", fcTitle).text("FC (" + fcLevel + ")"));
    }
    if (ch.stats.bait) {
        let baitLevel = String(ch.stats.bait.level || '').toUpperCase();
        let baitCount = Number(ch.stats.bait.count) || 0;
        let baitClass = baitLevel == 'HIGH' ? 'zkb-label-danger' : (baitLevel == 'LOW' ? 'bg-secondary' : '');
        let baitStyle = baitLevel == 'MEDIUM' ? '#963800' : '';
        badges.push($(document.createElement('span')).addClass("badge text-white " + baitClass).css("background-color", baitStyle).attr("title", baitCount + " past-year bait matches").text("BAIT " + baitLevel + " (" + baitCount + ")"));
    }
    if (ch.stats.cyno) {
        let cynoCount = Number(ch.stats.cyno.count) || 0;
        let cynoTitle = `Past-year fitted cynos: ${Number(ch.stats.cyno.standard) || 0} standard, ${Number(ch.stats.cyno.covert) || 0} covert, ${Number(ch.stats.cyno.industrial) || 0} industrial`;
        badges.push($(document.createElement('span')).addClass("badge text-white").css("background-color", "#633399").attr("title", cynoTitle).text("CYNO (" + cynoCount + ")"));
    }
    (ch.stats.characterTags || []).forEach(function(tag) {
        let hasCount = Object.prototype.hasOwnProperty.call(tag, 'count');
        let tagText = String(tag.label || '') + (hasCount ? ` (${(Number(tag.count) || 0).toLocaleString()})` : '');
        badges.push($(document.createElement('span')).addClass("badge text-white").css("background-color", tag.color).attr("title", tag.title).text(tagText));
    });

    let soloColor = '';
    if (ch.stats.shipsDestroyed > 10 && ch.stats.soloRatio >= 50) soloColor = 'green';

    let notes = labels.join(', ');
    let badgeNotes = badges.flatMap(function(badge, index) { return index ? [document.createTextNode(' '), badge] : [badge]; });

    let security = hasSecurity ? $(document.createElement('span')).addClass("fw-bold rounded px-1").attr("style", "color: " + secTextColor + "; background-color: " + secColor + "; border: 1px solid " + secColor + "; box-shadow: 0 0 3px " + secColor + ";").attr("aria-label", "Security status " + secStatus).attr("title", "Security status " + secStatus).attr("format", secStatusFormat).attr("raw", secStatus) : '';
    let caret = expanded ? 'down' : 'right';
    let toggleTitle = expanded ? 'Collapse row' : 'Expand row';
    let nameCell = $(document.createElement('td')).append(
        $(document.createElement('button')).attr("type", "button").addClass("btn btn-link btn-sm p-0 me-1 scanalyzer-row-toggle")
            .attr("aria-expanded", expanded).attr("aria-label", toggleTitle).attr("title", toggleTitle)
            .append($(document.createElement('i')).addClass("fas fa-caret-" + caret).attr("aria-hidden", "true")),
        char, document.createElement('br'),
        $(document.createElement('small')).addClass("d-flex align-items-center gap-1").append(
            $(document.createElement('span')).append(security, document.createTextNode(" "), $(document.createElement('span')).text(notes)),
            $(document.createElement('span')).addClass("ms-auto text-nowrap").append(badgeNotes)));
    let shipsCell = $(document.createElement('td')).addClass("pilotships").append(ships);
    let topShipsCell = $(document.createElement('td')).addClass("pilotships").append(topShips);
    let associateCell = $(document.createElement('td')).addClass("small").append(associates);
    let affiliateCell = $(document.createElement('td')).addClass("small").append(affiliates);
    let imageCell = $(document.createElement('td')).addClass("pilotmemberimage").append(image);
    let memberCell = $(document.createElement('td')).addClass("pilotmember").append(corp, $(document.createElement('br')), alli);
    let current = ch.scanalyzerElement;
    if (current && current.length && current[0].isConnected) {
        let currentCells = current.children();
        let update = $(document.createElement('tr')).append(nameCell, imageCell, memberCell, shipsCell, topShipsCell, associateCell, affiliateCell);
        formatScanalyzerRow(update);
        let cells = update.children();
        for (let i = 0; i < cells.length; i++) currentCells.eq(i).replaceWith(cells.eq(i));
    } else {
        let h = $(document.createElement('tr')).attr("data-scanalyzer-row", ch.scanalyzerRow).attr("danger", ch.stats.dangerRatio).append(
            nameCell, imageCell, memberCell, shipsCell, topShipsCell, associateCell, affiliateCell,
            $(document.createElement('td')).addClass("text-end").append(
                $(document.createElement('span')).addClass("pilotkl green").attr("format", "format-int-once").attr("raw", ch.stats.shipsDestroyed),
                document.createElement('br'), $(document.createElement('span')).addClass("red").attr("format", "format-int-once").attr("raw", ch.stats.shipsLost)),
            $(document.createElement('td')).addClass("pilotds text-end").append(
                $(document.createElement('span')).addClass("red").attr("format", "format-pct-once").attr("raw", ch.stats.dangerRatio),
                document.createElement('br'), $(document.createElement('span')).addClass("green").attr("format", "format-pct-once").attr("raw", ch.stats.snuggly)),
            $(document.createElement('td')).addClass("text-end").append(
                $(document.createElement('span')).attr("format", "format-pct-once").attr("raw", ch.stats.gangRatio),
                document.createElement('br'), $(document.createElement('span')).attr("format", "format-dec2-once").attr("raw", ch.stats.avgGangSize)),
            $(document.createElement('td')).addClass("text-end " + soloColor).attr("format", "format-pct-once").attr("raw", ch.stats.soloRatio));
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
    $('#playergroups').empty();
    Object.keys(mapping.allis).forEach(popUEa);
    Object.keys(mapping.corps).forEach(popUEc);
}

function popUEa(alli) {
    let count = mapping.allis[alli];
    let info = result.allis[alli] || {};
    let name = info.name || '';
    let ticker = info.ticker || '';
    let img = $(document.createElement('img')).addClass("eveimage img-rounded").attr("src", "https://images.evetech.net/alliances/" + alli + "/logo?size=64").attr("title", name);
    let link = $(document.createElement('a')).attr("href", "/alliance/" + alli + "/").addClass("nowrap").text("<" + ticker + ">");
    let h = $(document.createElement('div')).css("order", -count).addClass("float-start flex-shrink-0 scan-entity text-center").append(img, $(document.createElement('br')), link, $(document.createElement('br')), $(document.createElement('div')).addClass("text-center").text(count));
    $('#playergroups').append(h);
}

function popShip(ship) {
    let img = $(document.createElement('img')).attr("src", "https://images.evetech.net/types/" + ship.shipTypeID + "/render?size=64").attr("alt", ship.shipName);
    let link = $(document.createElement('a')).attr("href", "/ship/" + ship.shipTypeID + "/").text(ship.shipName);
    let h = $(document.createElement('div')).css("order", -ship.count).addClass("float-start scan-entity text-center").append(img, $(document.createElement('br')), link, $(document.createElement('br')), $(document.createElement('span')).attr("format", "format-int-once").attr("raw", ship.count));
    $('#shipgroups').append(h);
}

function popUEc(corp) {
    let count = mapping.corps[corp];
    let info = result.corps[corp] || {};
    let name = info.name || '';
    let ticker = info.ticker || '';
    let img = $(document.createElement('img')).addClass("eveimage img-rounded").attr("src", "https://images.evetech.net/corporations/" + corp + "/logo?size=64").attr("title", name);
    let link = $(document.createElement('a')).attr("href", "/corporation/" + corp + "/").addClass("nowrap").text("[" + ticker + "]");
    let h = $(document.createElement('div')).css("order", -count).addClass("float-start flex-shrink-0 scan-entity text-center").append(img, $(document.createElement('br')), link, $(document.createElement('br')), $(document.createElement('div')).addClass("text-center").text(count));
    $('#playergroups').append(h);
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

async function enrichCharacters(scanResult, onCharacter, onAffiliation) {
    if (Array.isArray(scanResult.corps)) scanResult.corps = Object.assign({}, scanResult.corps);
    if (Array.isArray(scanResult.allis)) scanResult.allis = Object.assign({}, scanResult.allis);

    let unknownByName = {};
    let missingSecurityByID = {};
    for (let character of scanResult.chars) {
        if (!(Number(character.id) > 0)) {
            unknownByName[character.name.toLowerCase()] = character;
        } else if (character.secStatus === null || character.secStatus === '' || typeof character.secStatus == 'undefined') {
            missingSecurityByID[Number(character.id)] = character;
        }
    }

    let characterNames = Object.keys(unknownByName);
    let missingSecurityIDs = Object.keys(missingSecurityByID);
    if (characterNames.length == 0 && missingSecurityIDs.length == 0) return 0;

    let affiliations = [];
    let updated = 0;
    let applyCharacter = function(response) {
        let character = missingSecurityByID[Number(response.entity.id)];
        let unknown = !character && response.entity.name ? unknownByName[response.entity.name.toLowerCase()] : null;
        if (!character) character = unknown;
        if (!character) return;
        let detail = response.detail;
        if (unknown) {
            character.id = Number(response.entity.id);
            character.name = detail.name || response.entity.name;
            character.corporationID = Number(detail.corporation_id) || 0;
            character.allianceID = Number(detail.alliance_id) || 0;
            character.factionID = Number(detail.faction_id) || 0;
            character.inactive = true;
            delete character.unknown;
        }
        if (detail.security_status != null) character.secStatus = Number(detail.security_status);

        if (unknown && character.corporationID > 0 && (!scanResult.corps[character.corporationID] || typeof scanResult.corps[character.corporationID].name == 'undefined')) {
            affiliations.push({endpoint: 'corporations', id: character.corporationID});
        }
        if (unknown && character.allianceID > 0 && (!scanResult.allis[character.allianceID] || typeof scanResult.allis[character.allianceID].name == 'undefined')) {
            affiliations.push({endpoint: 'alliances', id: character.allianceID});
        }
        updated++;
        if (onCharacter) onCharacter(character);
    };

    let cachedCharacters = await getCachedEsiInfo(
        characterNames.map(function(name) { return `characters:${name}`; })
            .concat(missingSecurityIDs.map(function(id) { return `characters:${id}`; }))
    );
    for (let name of characterNames) {
        let cached = cachedCharacters[`characters:${name}`];
        if (cached) applyCharacter(cached);
    }
    for (let id of missingSecurityIDs) {
        let cached = cachedCharacters[`characters:${id}`];
        if (cached) applyCharacter(cached);
    }

    let unknownNames = characterNames
        .filter(function(name) { return !cachedCharacters[`characters:${name}`]; })
        .map(function(name) { return unknownByName[name].name; });
    let characterRequests = missingSecurityIDs
        .filter(function(id) { return !cachedCharacters[`characters:${id}`]; })
        .map(function(id) {
            return {endpoint: 'characters', id: Number(id), name: missingSecurityByID[id].name};
        });
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

        characterRequests.push(...resolved
            .filter(function(character) { return unknownByName[character.name.toLowerCase()]; })
            .map(function(character) { return {endpoint: 'characters', id: character.id, name: character.name, cacheName: true}; }));
    }

    characterRequests = Array.from(new Map(characterRequests.map(function(entity) {
        return [Number(entity.id), entity];
    })).values());
    let characterDetails = [];
    if (characterRequests.length > 0) {
        updateStatus('fetching missing character details from ESI');
        characterDetails = await fetchEsiDetails(characterRequests, applyCharacter);
        await cacheEsiInfo(characterDetails.map(function(response) {
            return {
                key: `characters:${response.entity.id}`,
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
        }).concat(characterDetails.filter(function(response) {
            return response.entity.cacheName;
        }).map(function(response) {
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
        })));
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
    $('#results').empty();
    result.chars.forEach(popChar);
    updateScanalyzerExpandAll();
    popUEs();
}

async function showResult(r) {
    if (!document.getElementById('scaninput')) return;
    result = r;
    result.chars.forEach(function(character, index) {
        character.scanalyzerRow = index;
        character.scanalyzerExpanded = false;
    });

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
        $("#resultcounts").empty();
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

    await enrichCharacters(result, function(character) {
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
        $('#status').empty().hide();
        $('#resultssection').show();
    } else {
        $('#status').empty().append($(document.createElement('i')).text("... " + msg + " ...")).show();
    }
}

function getStatusColor(sec) {
    let calcStatus = sec;
    if (calcStatus > 5) calcStatus = 5;
    if (calcStatus < -5) calcStatus = -5; 
    calcStatus = (calcStatus / 5) + 0.8;
    if (calcStatus > 1) calcStatus = 1;
    calcStatus = Math.round(calcStatus * 10) / 10;

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
