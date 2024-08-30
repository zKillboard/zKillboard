var ws;
var adblocked = undefined;

$(document).ready(function() {
    if (navbar) $('#tracker-dropdown').load('/navbar/');

    // add the autocomplete search thing
    $('#searchbox').zz_search( function(data, event) { window.location = '/' + data.type + '/' + data.id + '/'; event.preventDefault(); } );
    $('#searchbox').on('focus', function() { $("#advancedsearchnavbar").slideDown(); });
    $('#searchbox').on('blur', function() { $("#advancedsearchnavbar").slideUp(); });

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

    addKillListClicks();

    /*var pathname = $(location).attr('pathname');
    console.log(pathname.substr(0,9));
    if (pathname != '/map/' && pathname.substr(0, 9) != '/account/') {
        $("a[href='/']").on('click', function(event) { doLoad($(this).attr('href')); return false; } );
        addPartials();
        console.log($(location).attr('pathname'));
    }*/

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

    $(document).keyup(function(e) {
        if ($("input:focus, textarea:focus").length === 0 && e.which === 191) {
            $("#searchbox").focus();
        }
    });

    // setup websocket with callbacks
    if (start_websocket) startWebSocket();

    setTimeout(function() { $("#messagedad").show(); }, 5500);
    $("img[shipImageError='true']").each(fixShipRender2Icon);

    $(".datatable").DataTable();

    // Prep comments, if the page has the function for them
    if (typeof prepComments === "function") prepComments();

    // For named anchors with the hrefit classname, make it a link as well
    $(".hrefit").each(function() { t = $(this);  t.attr('href', '#' + t.attr('name')); });
    $(".fetchme").each(function() { loadKillRow($(this).attr('killID'));  });
    assignRowColor();
});

function startWebSocket() {
    try {
        ws = new ReconnectingWebSocket('wss://' + window.location.hostname + '/websocket/', '', {maxReconnectAttempts: 15});
        ws.onmessage = function(event) {
                wslog(event.data);
        };
        ws.onopen = function(event) {
            pubsub('public');
            // If we connected and somehow got completely disconnected - reload the page
            ws.onclose = function(event) {
                //window.location = window.location;
            }
        }

        var channel = entityType + ":" + entityID;
        if (entityPage != 'index' && entityPage != 'overview') channel = channel + ":" + entityPage;
        if (entityType != 'none') {
            statsboxUpdate({type: (entityType == 'label' ? entityType : entityType + "ID"), id: entityID});
            pubsub(channel);
            pubsub('stats:' + channel);
        }
        if (window.location.pathname == '/') pubsub('all:*');

        console.log('WebSocket connected');
    } catch (e) {
        setTimeout(startWebSocket, 100);
    }
}

function htmlNotify (data) 
{
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
        tqStatus = json.tqStatus;
        tqCount = json.tqCount;
        if (tqStatus === 'OFFLINE' || tqStatus === 'UNKNOWN') {
            html = '<span class="red">TQ ' + tqCount + "</span>";
        } else {
            html = '<span class="green">TQ ' + tqCount + "</span>";
        }
        $("#tqStatus").html(html);
        $("#lasthour").text(json.kills);
    } else if (json.action === 'reload') {
        console.log('Reload imminent in the next 5 minutes');
        setTimeout("location.reload(true);", Math.floor(1 + (Math.random() * 500000)));
    } else if (json.action === 'bigkill') {
        htmlNotify(json);
    } else if (json.action === 'lastHour') {
        $("#lasthour").text(json.kills);
    } else if (json.action === 'audio') {
        audio(json.uri);
    } else if (json.action === 'comment') {
        $("#commentblock").html(json.html);
    } else if (json.action === 'littlekill') {
        var killID = json.killID;
        setTimeout(function() { loadLittleMail(killID); }, Math.floor(Math.random() * 1000));
    } else if (json.action === 'twitch-online') {
        console.log('twitch user online: ' + json.channel);
        twitchlive(json.channel);
    } else if (json.action == 'twitch-offline') {
        twitchoffline();
    } else if (json.action == 'statsbox') {
        statsboxUpdate(json);
    } else {
        console.log("Unknown action: " + json.action);
    }
}

function loadLittleMail(killID) {
        // Add the killmail to the live feed kill list
        $.get("/cache/24hour/killlistrow/" + killID + "/", addLittleKill);
}

function loadKillRow(killID) {
        $.get("/cache/24hour/killlistrow/" + killID + "/", function(data) { addKillRow(data, killID); });
}

function addKillRow(data, id) {
    $("#kill-" + id).replaceWith(data);
    fixDateRows();
    assignRowColor();
}

function fixDateRows() {
    let priorDate = undefined;
$(".tr-date").each( function() { row = $(this); date = row.attr('date'); if (date == priorDate) row.remove(); priorDate = date; }  );
}

function prepKills(data) {
    let html = '';
    for(i = 0; i < data.length; i++) {
        id = data[i];
        html = html + "<tr id='kill-" + id + "' class='fetchme' killID='" + id + "'></tr>'";
    }
    $("#killmailstobdy").html(html);
    $(".fetchme").each(function() { loadKillRow($(this).attr('killID'));  });
}

var killdata = undefined;
function addLittleKill(data) {
            if (!(showAds != 0 && typeof fusetag == 'undefined')) {
                var data = $(data);
killdata = $(data);
                data.on('click', function(event) {
                    if (event.which === 2) return false;
                    window.location = '/kill/' + $(this).attr('killID') + '/';
                    return false;
                });
                /*if (showAds == 0)*/ $("#killlist tbody tr").first().before(data);
                //else $("#killlist tbody tr").eq(0).after(data);
            }
            // Keep the page from growing too much...
            while ($("#killlist tbody tr").length > 50) $("#killlist tbody tr:last").remove();
            // Tell the user what's going on and not to expect sequential killmails
            if ($("#livefeednotif").length == 0) {
                $("#killlist thead tr").after("<tr><td id='livefeednotif' colspan='7'><strong><em>Live feed - killmails may be out of order.</em></strong></td></tr>");
            }
    assignRowColor();
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
    $('#modalMessageBody').html('Saving fit....');
    $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});

    var request = $.ajax({
        url: "/ccpsavefit/" + id + "/",
        type: "GET",
        dataType: "text"
    });

    request.done(function(msg) {
        $('#modalMessageBody').html(msg);
        $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});
    });
}


let sortOrder = 1;
let sortColumn = 0;
function doSort(column, doHide)
{
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
    console.log(column, order);
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
    $.post(url, function( data ) {
        result = JSON.parse(data); console.log(result);
        $("#fav-star-killmail").css("color", result.color);
        $('#modalTitle').text('Favorites');
        $('#modalMessageBody').text(result.message);
        $('#modalMessage').modal({backdrop: true, keyboard: true, show: true});
    });
}

function pubsub(channel)
{
    try {
        ws.send(JSON.stringify({'action':'sub', 'channel': channel}));
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
    if (detectAdblock) {
        let detection = detectAdblock();
        detectAdblock().then((res) => { 
            adblocked = (res.uBlockOrigin === true || res.adblockPlus === true);
            console.log('Adblocked?', adblocked);
            gtag('event', (adblocked === true ? 'ad_blocked' : 'ad_unblocked'));
            if (adblocked === true) {
                return showAdblockedMessage();
            }
        });
    } else {
        gtag('event', 'adblocked', 'detectAdblock blocked');
    }
    if (typeof fusetag == 'undefined') {
        adfailcount++;
        if (adfailcount <= 5) return setTimeout(loadads, 1000);

        console.log('ads appear to be blocked');
        return showAdblockedMessage();
    }
    $("#messagedad").remove();
    var adblocks = $(".publift:visible");
    adnumber = adblocks.length;
    adblocks.each(function() {
            var elem = $(this);
            var fuse = elem.attr("fuse");
            elem.load('/cache/1hour/publift/' + fuse + '/', adblockloaded);
    });
}

var bottomad = null;
function adblockloaded() {
    adnumber--;
    if (adnumber <= 0) {
        fusetag.loadSlots();
    }
}

function showAdblockedMessage() {
    if ($("#publifttop").html() == "") $("#publifttop").html('<h4>AdBlocker Detected! :(</h4><p>Please support zKillboard by disabling your adblocker.<br/><a href="/information/payments/">Or block them with ISK and get a golden wreck too.</a></p>');
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

function fixShipRender2Icon() { $(this).attr('src', $(this).attr('src').replace('render', 'icon')).removeAttr('imageError'); }

function twitchlive(channel) {
    if ($('#twitch-channel').text() != channel) {
        $('#twitch-embed').html("");
        $('#twitch-channel').text(channel);
        $('#twitch-live').removeClass('hidden').attr('href', 'https://twitch.tv/' + channel.toLowerCase());
        $('#twitchers').removeClass('hidden');
        if (channel == 'SquizzCaphinator') $('#twitch-channel').addClass('squizz');
    }
}

function twitchoffline() {
    $('#twitch-live').removeClass('squizz');
    $("#twitch-embed").html("");
    $("#twitch-channel").text("");
    $('#twitchers').addClass('hidden');
    $("#twitch-live").removeClass('hidden');
}

function twitchtime() {
    /*new Twitch.Embed("twitch-embed", {
        width: '100%',
        height: 500,
        channel: $("#twitch-channel").text(),
    });
    $("#twitch-live").addClass('hidden');*/
    gtag('event', 'twitch-clicked');
}

function statsboxUpdate(stats) {
    if (stats.type == 'systemID') stats.type = 'solarSystemID';
    else if (stats.type == 'shipID') stats.type = 'shipTypeID';
    $.get('/cache/bypass/stats/?type=' + stats.type + '&id=' + stats.id, setStatsboxValues);
}

function setStatsboxValues(stats) {
    Object.keys(stats).forEach((e) => $('#' + e).attr('raw', stats[e]) )
    doFormats();
    updateStats(stats);
}

function doFormats() {
    $("[format='format-int']").each(function() { t = $(this); doFieldUpdate(t, Number(t.attr('raw') | '').toLocaleString()); });
    $("[format='format-dec1']").each(function() { t = $(this); doFieldUpdate(t, parseFloat(t.attr('raw')).toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits:1} )); });
    $("[format='format-isk']").each(function() { t = $(this); doFieldUpdate(t, formatISK(Number(t.attr('raw')))) });

    $("[format='format-dec2-once']").each(function() { t = $(this); doFieldUpdate(t, parseFloat(t.attr('raw')).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2} )); t.removeAttr('format'); });
    $("[format='format-isk-once']").each(function() { t = $(this); doFieldUpdate(t, formatISK(Number(t.attr('raw')))); t.removeAttr('format'); });
}

function doFieldUpdate(f, v) {
    if (f.text() == String(v)) return;
    f.animate({opacity: 0}, 100, function() {
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
