$(document).ready(function() {
    //$('#scaninput').on('paste', startProcess);
    $('#scaninput').on('blur', startProcess);

    if (navigator.clipboard === undefined) $("#clip").hide();
    else $('#clippy').on('click', copypasta);

    clearInput();
})

function clearInput() {
    $('#scaninput').val('');
    updateStatus('awaiting your input');
}

async function copypasta() {
    let val = await navigator.clipboard.readText();
    if (val.trim().length < 3) return updateStatus('no usable text in the clipboard');
    $("#scaninput").val(val);
    startProcess();
    return false;
}

var scanCall = undefined;
function startProcess() {
    $('#resultssection').hide();
    $('#resultcounts').html('');
    $('#playergroups').html('');
    $('#shipgroups').html('');

    if (scanCall != undefined) clearTimeout(scanCall);
    scanCall = setTimeout(doScan, 1);
}

function doScan() {
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
success: showResult,
error: showError,
complete: showDone
});
}

function getImage(corp, alli) {
    if (alli) {
        let name = getName('alli', alli);
        let img = `<img class="eveimage img-rounded" style='height: 40px;' src='https://images.evetech.net/alliances/${alli}/logo?size=64' title="${name}" />`
            return `<a href='/alliances/${alli}/'>${img}</a>`
    }
    let name = getName('corp', corp);
    let img = `<img class="eveimage img-rounded" style='height: 40px;' src='https://images.evetech.net/corporations/${corp}/logo?size=64' title="${name}" />`
        return `<a href='/corporations/${corp}/'>${img}</a>`
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

function popChar(ch) {
    let type = ch.allianceID > 0 ? 'alli' : 'corp';
    let id = ch.allianceID > 0 ? ch.allianceID : ch.corporationID;

    let ships = '';
    for (let i = 0; i < ch.ships.length; i++) {
        ships = ships + `<a href='/character/${ch.id}/reset/ship/${ch.ships[i].shipTypeID}/'><img class="eveimage img-rounded" src="https://images.evetech.net/types/${ch.ships[i].shipTypeID}/render?size=64" style='width: 40px;' title="${ch.ships[i].shipName} ${ch.ships[i].kills} Kills" /></a>`;
    }

    ch.stats.snuggly = 100 - ch.stats.dangerRatio;
    let char = `<a href='/character/${ch.id}/'>${ch.name}</a>`
        let corp = getName('corps', ch.corporationID);
    let alli = getName('allis', ch.allianceID);
    let image = getImage(ch.corporationID, ch.allianceID);
    let secColor = getStatusColor(ch.secStatus);
    if (typeof ch.secStatus == 'undefined') ch.secStatus = 0;
    if (ch.allianceID) mapping['allis'][ch.allianceID] = (mapping['allis'][ch.allianceID] | 0) + 1;
    else mapping['corps'][ch.corporationID] = (mapping['corps'][ch.corporationID] | 0) + 1;
    if (!(ch.stats.shipsDestroyed > 1)) ch.stats.gangRatio = '';
    ch.stats.soloRatio = 100 - ch.stats.gangRatio;
    ch.stats.shipsDestroyed = ch.stats.shipsDestroyed | 0;
    if (ch.stats.shipsDestroyed == 0) {
        ch.stats.soloRatio = '';
        ch.stats.avgGangSize = '';
        ch.stats.dangerRatio = '';
        ch.stats.snuggly = ch.stats.shipsLost > 0 ? 100 : '';
    }

    let h = $(`<tr><td>${char}<br/>Sec: <small style='color: ${secColor}' format="format-dec2-once" raw="${ch.secStatus}"></small></td><td class='pilotships'>${ships}</td><td class='pilotmemberimage'>${image}</td><td class="pilotmember">${corp}<br/>${alli}</td><td class="text-right"><span class="pilotkl green" format="format-int-once" raw="${ch.stats.shipsDestroyed}"></span><br/><span class="red" format="format-int-once" raw="${ch.stats.shipsLost}"></span></td><td class="pilotds text-right"><span class="red" format="format-pct-once" raw="${ch.stats.dangerRatio}"></span><br/><span  class="green" format="format-pct-once" raw="${ch.stats.snuggly}"></span></td><td class="text-right"><span format="format-pct-once" raw="${ch.stats.gangRatio}"></span><br/><span format="format-dec2-once" raw="${ch.stats.avgGangSize}"></td><td class='text-right' format="format-pct-once" raw="${ch.stats.soloRatio}"></td></tr>`);
    $('#results').append(h);
}

function popUEs() {
    $('.entitygroup').html('');
    Object.keys(mapping.allis).forEach(popUEa);
    Object.keys(mapping.corps).forEach(popUEc);
}

function popUEa(alli) {
    let count = mapping.allis[alli];
    let name = result.allis[alli].name;
    let ticker = result.allis[alli].ticker;
    let img = `<img class="eveimage img-rounded" src='https://images.evetech.net/alliances/${alli}/logo?size=64' title="${name}" />`
        let link = `<a href='/alliances/${alli}/'>&lt;${ticker}&gt;</a>`
        let h = $(`<div style='order: -${count}' class='pull-left scan-entity text-center'>${img}<br/>${link}<br/><div class='text-center'>${count}</div></div>`);
    $('#playergroups').append(h);
}

function popShip(ship) {
    let img = `<img src="https://images.evetech.net/types/${ship.shipTypeID}/render?size=64" alt="${ship.shipName}" />`;
    let link = `<a href='/ship/${ship.shipTypeID}/'>${ship.shipName}</a>`;
    let h = $(`<div style='order: -${ship.count}' class='pull-left scan-entity text-center'>${img}<br/>${link}<br/><span format="format-int-once" raw="${ship.count}"></span></div>`);
    $('#shipgroups').append(h);
}

function popUEc(corp) {
    let count = mapping.corps[corp];
    let name = result.corps[corp].name;
    let ticker = result.corps[corp].ticker;
    let img = `<img class="eveimage img-rounded" src='https://images.evetech.net/corporations/${corp}/logo?size=64' title="${name}" />`
        let link = `<a href='/corporation/${corp}/'>[${ticker}]</a>`
        let h = $(`<div style='order: -${count}' class='pull-left scan-entity text-center'>${img}<br/>${link}<br/><div class='text-center'>${count}</div></div>`);
    $('#playergroups').append(h);
}

let result = undefined;
let mapping = undefined;
function showResult(r) {
    result = r;
    mapping = {corps: {}, allis: {}};
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

    result.chars.forEach(popChar);
    popUEs();
    result.ships.forEach(popShip); 

    doFormats();
    updateStatus('');
}

function showError(a, b, c) {
    updateStatus('an error! check the console for details');
    console.log('error', a, b, c);
}

function showDone() {
    $("#scaninput").removeAttr('disabled');
}

function updateStatus(msg = '') {
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
