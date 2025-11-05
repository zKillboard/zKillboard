var ws;
var adblocked = undefined;


$(document).ready(function() {
    if (navbar) $('#tracker-dropdown').load('/navbar/');

    // autocomplete
    $('#searchbox').zz_search( function(data, event) { window.location = '/' + data.type + '/' + data.id + '/'; event.preventDefault(); } );

    // prevent firing of window.location in table rows if a link is clicked directly
    $('.killListRow a').click(function(e) {
        e.stopPropagation();
    });

    // See if we are embedded in an iframe on another website perhaps?
    if (top !== self) {
        $("#iframed").modal('show');
    }

    // Send that ESI URL to the parser, allows the website to parse the killmail so that
    // it is ready for the user when they click submit
    $("#killmailurl").bind('paste', function(event) {
        setTimeout(sendCrestUrl, 1);
    });
    $('#postExternalMail').on('click', pasteCrestUrl)

    addKillListClicks();

    var datepicker = $('.datepicker').datepicker({
            format: "mm/yyyy",
            viewMode: 1,
            minViewMode: 1,
            autoclose: true
    }).on("changeDate", function(ev){
            console.log(ev);
            date = new Date(ev.date);
            var year = date.getFullYear();
            var month = date.getMonth() + 1;
            var newHREF = actualURI + 'year/' + year + '/month/' + month + '/';
            console.log(newHREF);
            location.href = newHREF;
    });

    $(document).on('keypress', checkForSearchKey);
    $('#dls-slider').on('change input', updateDLS);
    $('#dls-slider').on('click touchstart mousedown', stopPropagation);

    // setup websocket with callbacks
    //if (start_websocket) startWebSocket();
    if (entityType != 'none') {
        statsboxUpdate({type: (entityType == 'label' ? entityType : entityType + "ID"), id: entityID});
    }

    $(".datatable").DataTable();

    // Prep comments, if the page has the function for them
    if (typeof prepComments === "function") prepComments();

    // For named anchors with the hrefit classname, make it a link as well
    $(".hrefit").each(function() { t = $(this);  t.attr('href', '#' + t.attr('name')); });
    $(".fetchme").each(function() { loadKillRow($(this).attr('killID'));  });
    setTimeout(fixCCPsBrokenImages, 1000);

    assignRowColor();
    doFormats();
    $(document).ajaxComplete(doFormats);

    // Anything that has a raw value to it will be able to be copied to the clipboard
	$("[raw]").click(copyToClipboard);
	
	$("label[for]").on("click", () => { $(window).focus(); })
});

function copyToClipboard(e) {
    console.log(this);
    const raw = $(this).attr("raw");
    console.log(raw);
    if (navigator.clipboard.writeText) {
        navigator.clipboard.writeText(raw);
        showToast(raw + ' has been copied to your clipboard');
    }
}

const asciiForwardSlash = '/'.charCodeAt(0);
const asciiBackSlash = '\\'.charCodeAt(0);

function checkForSearchKey(event) {
    if ($("input:focus, textarea:focus").length == 0) {
        if (event.which == asciiForwardSlash) {$("#searchbox").focus(); return false; }
        if (event.which == asciiBackSlash) return window.location = '/asearch/';
    }
}

function startWebSocket() {
    try {
        ws = new ReconnectingWebSocket((window.location.hostname == 'localhost' ? 'ws' : 'wss' ) + '://' + window.location.hostname + '/websocket/', '', {maxReconnectAttempts: 15});
        ws.onmessage = function(event) {
                wslog(event.data);
        };
        ws.onopen = function(event) {
            doSubs();
        };

        var channel = entityType + ":" + entityID;
        if (entityPage != 'index' && entityPage != 'overview') channel = channel + ":" + entityPage;
        if (entityType != 'none') {
            pubsub(channel);
            pubsub('stats:' + channel);
        }
        if (window.location.pathname == '/') pubsub('all:*');

        console.log('WebSocket connected');
    } catch (e) {
        setTimeout(startWebSocket, 100);
    }
}

const pubsubs = ['public'];
function doSubs() {
    pubsubs.forEach((e) => { pubsub(e); });
}

function htmlNotify (data) 
{
    if (tn === false) return;
    if("Notification" in window) {
        if (Notification.permission !== 'denied' && Notification.permission !== "granted") {
            Notification.requestPermission(function (permission) {
                if (permission === 'granted') htmlNotify(data);
            });
            return;
        }
        if (Notification.permission === 'granted') {
            var notif = new Notification(data.title, {
                body: data.iskStr,
                icon: data.image,
                tag: data.url
            });
            setTimeout(function() { notif.close() }, 20000);
            notif.onclick = function () {
                notif.close();
                window.open(data.url).focus();
            };
        }
    }
}

function wslog(msg)
{
    if (msg === 'ping' || msg === 'pong') return;
    json = JSON.parse(msg);
    if (json.action === 'tqStatus') {
        $("#lasthour").attr('raw', json.kills).attr('format', 'format-int-once');
        updateTqStatus(json.tqStatus, json.tqCount);
    } else if (json.action === 'reload') {
        console.log('Reload imminent in the next 5 minutes');
        setTimeout("location.reload(true);", Math.floor(1 + (Math.random() * 500000)));
    } else if (json.action === 'bigkill') {
        htmlNotify(json);
    } else if (json.action === 'lastHour') {
        $("#lasthour").text(json.kills);
        doFormats();
    } else if (json.action === 'audio') {
        audio(json.uri);
    } else if (json.action === 'comment') {
        $("#commentblock").html(json.html);
    } else if (json.action === 'littlekill') {
        var killID = json.killID;
        setTimeout(function() { loadLittleMail(killID); }, Math.floor(Math.random() * 1000));    
    } else if (json.action == 'statsbox') {
        console.log(json);
        statsboxUpdate(json);
    } else if (json.action == 'message') {
        console.log(json);
        if (json.message.length > 0) $("#zkb-message").html("<center>" + json.message + "</center>").removeClass('hide');
        else $("#zkb-message").html('').addClass('hide');
    } else if (json.action == 'ztop') {
        $("#ztoptextblock").text(json.message);
    } else {
        console.log("Unknown action: " + json.action);
    }
}

function loadLittleMail(killID) {
        // Add the killmail to the live feed kill list
        $.get("/cache/24hour/killlistrow/" + killID + "/", addLittleKill);
}

function loadKillRow(killID, retries = 0) {
        $.get("/cache/24hour/killlistrow/" + killID + "/", function(data) { addKillRow(data, killID); })
            .fail(function(jqXHR, textStatus, errorThrown) {
                retries++;
                if (retries < 3) setTimeout(loadKillRow.bind(null, killID, retries), 1000);
            });
}

function addKillRow(data, id) {
    $("#kill-" + id).replaceWith(data);
    assignRowColor();
    adjustKillmailPresentation();
}

var dateFormatter = new Intl.DateTimeFormat(undefined, {dateStyle: 'long', timeZone: 'UTC' });
var longFormatter = new Intl.DateTimeFormat(undefined, {dateStyle: 'long', timeStyle: 'long', timeZone: 'UTC' });
function adjustKillmailPresentation() {
    // Remove excess killmails
    while ($(".tr-killmail").length > 50) $(".tr-killmail").last().remove();
    // Ensure the last row isn't a dangling date row
    while ($("#killmailstobdy tr").last().hasClass("tr-date")) $("#killmailstobdy tr").last().remove();

    // Check over date rows and only show the first tr-date row for a particular date
    let priorDate = undefined;
    $(".tr-date").each( function() { row = $(this); date = row.attr('date'); if (date == priorDate) row.hide(); else row.show(); priorDate = date; }  );
    /*
    $(".dateFormat th").each( function()  { t = $(this); p = t.parent(); t.text(p.attr('date'); p.removeClass("dateFormat"); });
    $("[format='format-date-long-once']").each(function() { t = $(this); t.html( longFormatter.format( new Date( Number(t.attr('epoch'))) )); t.removeAttr('format'); });
    */
}

function prepKills(data) {
    let html = '';
    for(i = 0; i < data.length; i++) {
        killID = data[i];
        let tr = $("<tr id='kill-" + killID + "' class='fetchme' killID='" + killID + "'></tr>'");
        $("#killmailstobdy").append(tr);
        loadKillRow(killID);
    }
    $("#kms_loading").remove();
}

var killdata = undefined;
function addLittleKill(data) {
    var data = $(data);
    killdata = $(data);
    $("#killlist tbody tr").first().before(data);

    // Keep the page from growing too much...
    while ($("#killlist tbody tr").length > 100) $("#killlist tbody tr:last").remove();
    // Tell the user what's going on and not to expect sequential killmails
    if ($("#livefeednotif").length == 0) {
        $("#killlist thead tr").after("<tr><td id='livefeednotif' colspan='7'><strong><em>Live feed - killmails may be out of order.</em></strong></td></tr>");
    }
    assignRowColor();
    adjustKillmailPresentation();
}

/* This is currently not used, it is here as a proof of concept */
function addLittleKillInOrder(data) {
    let added = false;
    let lastrow = undefined;
    let rows = [].reverse.call($("#killmailstobdy tr"));
    data = $(data);
    data.hide();
    let killid = Number(data.attr('killid'));
    console.log('loading', killid);
    for (row of rows) {
        row = $(row);
        let curKillID = Number(row.attr('killid'));
        if (isNaN(curKillID)) continue;
        if (curKillID == killid) return; // we're already displaying this killmail
        if (curKillID > killid) {  row.after(data); break; }
        lastrow = row;
    }
    lastrow.before(data);
    setTimeout(() => { data.show('slow');}, 1);

    while ($("#killmailstobdy tr").length > 50) $("#killmailstobdy tr").last().remove()
}
function audio(uri)
{
    var audio = new Audio(uri);
    audio.volume = 0.1;
    audio.play();
}

function saveFitting(id) {
    $('#modalMessageBody').html('<div style="color: white;">Saving fit....</div>');
    $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});

    var request = $.ajax({
url: "/ccpsavefit/" + id + "/",
type: "GET",
dataType: "text"
});

request.done(function(msg) {
        $('#modalMessageBody').html('<div style="color: white;">' + msg + '</div>');
        $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});
        });
}


let sortOrder = 1;
let sortColumn = 0;
function doSort(column, doHide)
{
    let count = $(".item_row").length;
    if (count >= 250 && confirm(`Are you sure? There are ${count} rows to sort! This could result in high cpu usage which could cause your web application to temporarily lock up during the sort and possible increased battery drainage (e.g. phones, tablets, laptops).`) === false) return;
    if (doHide) $(".hide-when-sorted").hide();
    else $(".hide-when-sorted").show();

    if (column != sortColumn) {
        if (column >= 2) order = -1;
        else order = 1;
    }
    else order = -1 * sortOrder;
    if (column == 0) order = 1;

    //if (column == sortColumn && order == sortOrder) return;

    sortItemTable(column, order);
    sortColumn = column;
    sortOrder = order;
}

function sortItemTable(column, order) {
    var table, rows, switching, i, x, y, shouldSwitch;  
    table = document.getElementById("itemTable");
    switching = true;
    haveSwitched = false;

    do {
        haveSwitched = false;
        rows = table.rows;
        for (i = 1; i < (rows.length - 1); i++) {
            x = rows[i].getElementsByTagName("td")[column];
            if (x == undefined) x = rows[i].getElementsByTagName("th")[column];
            y = rows[i + 1].getElementsByTagName("td")[column];
            if (y == undefined) y = rows[i + 1].getElementsByTagName("th")[column];
            if (!x || !y) continue;
            let v1 = x.getAttribute('data-order');
            v1 = (v1 == null) ? x.innerHTML : parseFloat(v1);
            if (isNaN(v1)) v1 = x.getAttribute('data-order');
            let v2 = y.getAttribute('data-order');
            v2 = (v2 == null) ? y.innerHTML : parseFloat(v2);
            if (isNaN(v2)) v2 = y.getAttribute('data-order');
            if ((order == 1 && v1 > v2) || (order == -1 && v1 < v2)) {
                rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                t = rows[i];
                rows[i] = rows[i] + 1;
                rows[i + 1] = t;
                haveSwitched = true;
            }
        }
    } while (haveSwitched); 
} 

function sendCrestUrl() {
    str = $("#killmailurl").val();
    strSplit = str.split("/");
    if (strSplit.length === 8) strSplit.shift();
    killID = strSplit[4];
    hash = strSplit[5];
    a = ['/crestmail/', killID, '/', hash, '/'];
    url = a.join('');
    $.get(url);
}

function pasteCrestUrl() {
    setTimeout(pasteCrestUrlAsync, 1);
    return false;
}
async function pasteCrestUrlAsync() {
    try {
        const isFirefox = navigator.userAgent.toLowerCase().includes('firefox');
        if (isFirefox) return window.location = '/post/';

        let str = await navigator.clipboard.readText();
        strSplit = str.split('/');
        if (strSplit.length === 8) strSplit.shift();
        else return window.location = '/post/';

        $('#externalurl').val(str);
        $('#externalkmform').submit();
        console.log('submitted');

        return false;
    } catch (e) {
        console.log(e);
        return window.location = '/post/';
    }
}

function loadPartial(url) {
    setTimeout("doLoad('" + url + "');", 1);
    return false;
}

function addPartials() {
    //var partials = ['kill', 'faction', 'system', 'region', 'group', 'ship', 'location'];
    var partials = ['kill', 'character', 'corporation', 'alliance', 'faction', 'system', 'region', 'group', 'ship', 'location'];
    for (partial of partials) {
        console.log("Adding partial for " + partial);
        $(".pagecontent a[href^='/" + partial + "/']").on('click', function(event) { doLoad($(this).attr('href')); return false; } );
    }
}

function loadCompleted() {
    window.scrollTo(0, 0);
    NProgress.done();
    //addPartials();
    addKillListClicks();
    $('#tracker-dropdown').load('/navbar/');
}

function doLoad(url) {
    return;
    //console.log("Loading: " + url);
    var pathname = window.location.pathname;
    var state = { 'href' : pathname };
    NProgress.start();
    $(".pagecontent").load('/partial' + url, null, loadCompleted);
    history.pushState(state, null, url);
}

// Revert to a previously saved state
window.addEventListener('popstate', function(event) {
        //window.location = window.location;
        });

function addKillListClicks()
{
    $(".killListRow").on('click', function(event) {
            if (event.which === 2) return false;
            console.log($(this).attr('killID'));
            window.location = '/kill/' + $(this).attr('killID') + '/';
            return false;
            });
}

function doSponsor(url)
{
    $('#modalMessageBody').load(url);
    $('#modalTitle').text('Sponsor this killmail');
    $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});
}

function doFavorite(killID) {
    var color = $("#fav-star-killmail").css("color");
    var action = (color === "rgb(128, 128, 128)") ? "save" : "remove";
    var url = '/account/favorite/' + killID + '/' + action + '/';
    $.post(url, function( result ) {
		console.log(result);
    	$("#fav-star-killmail").css("color", result.color);
		showToast(result.message, 5000);
    });
}

function pubsub(channel)
{
    try {
        ws.send(JSON.stringify({'action':'sub', 'channel': channel}));
        if (!pubsubs.includes(channel)) pubsubs.push(channel);
        console.log("subscribing to " + channel);
    } catch (e) {
        setTimeout("pubsub('" + channel + "');", 150);
    }
}

function curday()
{
    today = new Date();
    var dd = today.getDate();
    var mm = today.getMonth()+1; //As January is 0.
    var yyyy = today.getFullYear();

    if(dd<10) dd='0'+dd;
    if(mm<10) mm='0'+mm;
    return (yyyy+mm+dd);
};

function commentUpVote(pageID, commentID) 
{
    if (showAds == 0 || typeof fusetag != "undefined") $.ajax("/cache/bypass/comment/" + pageID + "/" + commentID + "/up/");
}

var adnumber = 0;
var adfailcount = 0;
function loadads() {
    $("#messagedad").remove();
    var adblocks = $(".publift:visible");
    adnumber = adblocks.length;
    adblocks.each(function() {
            var elem = $(this);
            var fuse = elem.attr("fuse");
            elem.load('/cache/1hour/publift/' + fuse + '/', adblockloaded);
            });
    startWebSocket();
}

var bottomad = null;
function adblockloaded() {
    adnumber--;
    if (adnumber <= 0) {
        fusetag.loadSlots();
    }
}

function showAdblockedMessage() {
    if ($("#publifttop").html() == "") {
        gtag('event', 'adblocked', 'detectAdblock blocked');
        let html = '';
        //if (promoURI != '') html = `<div style='max-height: 130px; max-width: 100%;'><a href="${promoURI}" target="_blank"><img style='max-height: 130px; max-width: 100%;' src="${promoImage1}" alt="Promotional Image" />User code "zkill" for 3% Off!</a></div>`;
        //else html = '<h4>AdBlocker Detected! :(</h4><p>Please support zKillboard by disabling your adblocker.<br/><a href="/information/payments/">Or block them with ISK and get a golden wreck too.</a></p>';
        $("#publifttop").html(html);
        if (ws) ws.close();
        $(".liveupdates").addClass('hidden');
        $("#noliveupdates").removeClass("hidden");
    }
}

var now = time();
var today = now - (now % 86400);
var week = now - (now % 604800);

function time() {
return Math.floor(Date.now() / 1000);
}

// gtcplex320.jpg  gtcplex728.jpg  merch320.jpg  merch728.jpg
var banner_links = ['https://store.markeedragon.com/affiliate.php?id=928&redirect=index.php?cat=4', 'https://www.zazzle.com/store/zkillboard/products'];
var banners_sm = ['/img/banners/gtcplex320.jpg', '/img/banners/merch320.jpg'];
var banners_lg = ['/img/banners/gtcplex728.jpg?1', '/img/banners/merch728.jpg'];
var ob_firstcall = true;
function otherBanners() {
if (ob_firstcall) {
ob_firstcall = false;
return setTimeout(otherBanners, 6000);
}
if ($("#messagedad").length == 0) return;

var minute = new Date().getMinutes();
var mod = minute % 2; // number of other banners
$('#otherBannerAnchor').attr('href', banner_links[mod]);
$('#otherBannerImg').attr('src', banners_lg[mod]);
$("#otherBannerDiv").css('display', 'block');
setTimeout(otherBanners, Math.min(30000, 1000 * (61 - new Date().getSeconds())));
}

/*
   <h4>zKillboard does NOT automatically get all killmails</h4><p>zKillboard does not get all killmails automatically. CCP does not make killmails public. They must be provided by various means.</p><ul><li>Someone manually posts the killmail.</li><li>A character has authorized zKillboard to retrieve their killmails.</li><li>A corporation director or CEO has authorized zKillboard to retrieve their corporation\'s killmails.</li><li>War killmail (victim and final blow have a Concord sanctioned war with each other)</li></ul><p>The killmail API works just like killmails do in game. The victim gets the killmail, and the person with the finalblow gets the killmail. Therefore, for zKillboard to be able to retrieve the killmail via API it must have the character or corporation API submitted for the victim or the person with the final blow. If an NPC gets the final blow, the last character to aggress to the victim will receive the killmail and credit for the final blow.</p><p>Remember, every PVP killmail has two sides, the victim and the aggressors. Victims often don\'t want their killmails to be made public, however, the aggressors do.</p>
 */

function showAdder(showAdd, type, id, doTN) {
    if (doTN) pubsub('tracker:' + type + ':' + id);
    return (showAdd && ($("#tracker-remove-" + type + "-" + id).removeClass("hidden").length == 0));
}

function statsboxUpdate(stats) {
    if (stats.type == 'systemID') stats.type = 'solarSystemID';
    else if (stats.type == 'shipID') stats.type = 'shipTypeID';
    $.get('/cache/bypass/stats/?type=' + stats.type + '&id=' + stats.id, setStatsboxValues);
}

function setStatsboxValues(stats) {
    Object.keys(stats).forEach((e) => $('#' + e).attr('raw', stats[e]) )
        doFormats();
    waitForStatsFunctionToLoadBecauseChromeIsBeingDumb(stats);
}

function waitForStatsFunctionToLoadBecauseChromeIsBeingDumb(stats) {
    if (typeof updateStats == 'undefined') return setTimeout(function() { waitForStatsFunctionToLoadBecauseChromeIsBeingDumb(stats), 10});
    updateStats(stats);
}

function doFormats() {
    $("[format='format-int']").each(function() { t = $(this); doFieldUpdate(t, Number(t.attr('raw') || 0).toLocaleString()); });
    $("[format='format-int-once']").each(function() { t = $(this); doFieldUpdate(t, Number(t.attr('raw') || 0).toLocaleString()); t.removeAttr('format'); });
    $("[format='format-pct-once']").each(function() { t = $(this); doFieldUpdate(t, (Number(t.attr('raw') || 0) + '%').toLocaleString()); t.removeAttr('format'); });

    $("[format='format-dec1']").each(function() { t = $(this); doFieldUpdate(t, parseFloat(t.attr('raw')).toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits:1} )); });
    $("[format='format-isk']").each(function() { t = $(this); doFieldUpdate(t, formatISK(Number(t.attr('raw')))) });

    $("[format='format-dec2-once']").each(function() { t = $(this); doFieldUpdate(t, parseFloat(t.attr('raw')).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2} )); t.removeAttr('format'); });
    $("[format='format-dec2-once-i']").each(function() { t = $(this); doFieldUpdate(t, parseFloat(t.attr('raw')).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2} ) + ' ISK'); t.removeAttr('format'); });
    $("[format='format-isk-once']").each(function() { t = $(this); doFieldUpdate(t, formatISK(Number(t.attr('raw')))); t.removeAttr('format'); });
    $("[format='format-isk-once-i']").each(function() { t = $(this); doFieldUpdate(t, formatISK(Number(t.attr('raw'))) + ' ISK'); t.removeAttr('format'); });
    $("#statsbox td[raw='-']").text('-');
}

function doFieldUpdate(f, v) {
    if (f.attr('raw') == '' || f.attr('raw') == '-' || f.attr('raw') == undefined) return;
    if (v == 'NaN') v = '';

    if (f.text() == String(v)) return;

    let o = $(f).attr('flash') == undefined ? 1 : 0;
    f.animate({opacity: o}, 100, function() {
            $(this).text(v).animate({opacity: 1}, 100);
            })
}

const formatIskIndex = ['', 'k', 'm', 'b', 't', 'k t', 'm t', 'b t'];
function formatISK(value, decimals = 2) {
    if (value < 10000) return value.toLocaleString();
    let i = 0;
    while (value > 999.99) {
        value = value / 1000;
        i++;
    }
    return value.toLocaleString(undefined, {minimumFractionDigits: decimals, maximumFractionDigits: decimals}) + formatIskIndex [i];
}

function assignRowColor() {
    $(".kltbd").each(assignGreenRed);
}

function assignGreenRed() {
    // URI split could be done at the global level, but page URI might change so we're keeping it here
    let urisplit = window.location.pathname.split('/');
    if (urisplit.length < 4 || urisplit[2] == '') return;
    let vicid = urisplit[2];

    let row = $(this).removeClass('kltbd').removeClass('winwin').removeClass('error');
    let vics = row.attr('vics');
    if (vics == '') return;
    vics = vics.split(',');

    for (i=0;i<vics.length;i++) if (vicid == vics[i]) {
        $("#kill-" + row.attr('killID') + " .glyphicon-remove").removeClass('hidden');
        return row.addClass('error');
    }

    $("#kill-" + row.attr('killID') + " .glyphicon-ok").removeClass('hidden');
    row.addClass('winwin');
}

function fixCCPsBrokenImages() {
    $("img[shipimageerror='true']").each(function() { let t = $(this);  let src = t.attr('src'); console.log('fixing', src); t.attr('src', src.replace('render', 'icon')).removeAttr('shipimageerror'); });
}

function updateTqStatus(tqStatus, count) { 
    $("#tqCount").attr('raw', count).attr('format', 'format-int-once');
    let detail = 'TQ ', clss = 'green';

    if (tqStatus != 'ONLINE') clss = 'red';

    $("#tqStatusDetail").text(detail);
    $("#tqStatus").removeClass("red").removeClass("green").addClass(clss);

    doFormats();
}

const dlsOptions = {
    '0': 'ASAP - Killmails will post as they are received.',
    '1': '1 hour - killmails will post when they are 1 hour old.',
    '2': '3 hours - killmails will post when they are 3 hours old.',
    '3': '8 hours - killmails will post when they are 8 hours old. ',
    '4': '24 hours - killmails will post when they are 24 hours old.',
    '5': '72 hours - killmails will post when they are 72 hours (3 days) old.'
}
function updateDLS(e) {
    let slider = $(this);
    let val = slider.val() || 0;
    $("#dls-value").text(dlsOptions[val]);
    $("#dls-login").attr('href', `/ccpoauth2/${val}/`);
}

console.log('common.js loaded');

function stopPropagation(e) {
    e.stopPropagation();
}


function showToast(message, duration = 3000) {
	// Ensure a container exists
	let container = document.getElementById('toast-container');
	if (!container) {
		container = document.createElement('div');
		container.id = 'toast-container';
		document.body.appendChild(container);
	}

	// Create toast element
	const toast = document.createElement('div');
	toast.className = 'toast';
	toast.textContent = message;

	container.appendChild(toast);

	setTimeout(() => { toast.classList.add('show'); }, 10);


	// Hide and remove after duration
	setTimeout(hideToast, 3000);
}

function hideToast() {
	let container = document.getElementById('toast-container');
	if (container) container.remove();
}
