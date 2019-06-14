var ws;
$(document).ready(function() {
    //$('body').on('touchstart.dropdown', '.dropdown-menu', function (e) { e.stopPropagation(); });

    if ($("[rel=tooltip]").length) {
        $("[rel=tooltip]").tooltip({
            placement: "bottom",
            animation: false
        });
    }

    // add the autocomplete search thing
    $('#searchbox').zz_search( function(data, event) { window.location = '/' + data.type + '/' + data.id + '/'; event.preventDefault(); } );

    // prevent firing of window.location in table rows if a link is clicked directly
    $('.killListRow a').click(function(e) {
        e.stopPropagation();
    });

    // See if we are embedded in an iframe on another website perhaps?
    if (top !== self) {
        $("#iframed").modal('show');
    }

    // Send that CREST URL to the parser, allows the website to parse the killmail so that
    // it is ready for the user when they click submit
    $("#killmailurl").bind('paste', function(event) {
        setTimeout(sendCrestUrl, 1);
    });

    // setup websocket with callbacks
    ws = new ReconnectingWebSocket('wss://zkillboard.com:2096/', '', {maxReconnectAttempts: 15});
    ws.onmessage = function(event) {
        wslog(event.data);
    };
    ws.onopen = function(event) {
        pubsub('public');
    }

    addKillListClicks();
    /*var pathname = $(location).attr('pathname');
    console.log(pathname.substr(0,9));
    if (pathname != '/map/' && pathname.substr(0, 9) != '/account/') {
        $("a[href='/']").on('click', function(event) { doLoad($(this).attr('href')); return false; } );
        addPartials();
        console.log($(location).attr('pathname'));
    }*/
    //setTimeout('window.location = window.location', 3600000);
});

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
        setTimeout("location.reload();", (Math.random() * 300000));
    } else if (json.action === 'bigkill') {
        htmlNotify(json);
    } else if (json.action === 'lastHour') {
        $("#lasthour").text(json.kills);
    } else if (json.action === 'audio') {
        audio(json.uri);
    } else if (json.action === 'comment') {
        $("#commentblock").html(json.html);
    } else if (json.action === 'littlekill') {
        // Add the killmail to the kill list
        $.get("/cache/1hour/killlistrow/" + json.killID + "/", function(data) { 
            $(data).insertBefore("#killlist tbody tr:first").on('click', function(event) {
                if (event.which === 2) return false;
                window.location = '/kill/' + $(this).attr('killID') + '/';
                return false;
            });
            // Keep the page from growing too much...
            while ($("#killlist tbody tr").length > 50) $("#killlist tbody tr:last").remove();
            // Tell the user what's going on and not to expect sequential killmails
            if ($("#livefeednotif").length == 0) $("#killlist thead tr").after("<tr><td id='livefeednotif' colspan='7'><strong><em>Live feed - killmails may be out of order.</em></strong></td></tr>");
        });
    } else {
        console.log("Unknown action: " + json.action);
    }
}

function audio(uri)
{
    var audio = new Audio(uri);
    audio.volume = 0.1;
    audio.play();
}

function saveFitting(id) {
    $('#modalMessageBody').html('Saving fit....');
    $('#modalMessage').modal('show');

    var request = $.ajax({
        url: "/ccpsavefit/" + id + "/",
        type: "GET",
        dataType: "text"
    });

    request.done(function(msg) {
        $('#modalMessageBody').html(msg);
        $('#modalMessage').modal('show');
    });
}


function hideSortStuff(doHide)
{
    if (doHide) $(".hide-when-sorted").hide();
    else $(".hide-when-sorted").show();
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

function addToolTip(el, msg) {
    $('#tipmsg').html(msg);
    var tt = $('#ttooltip').first();

    var pos = el.offset();

    tt.css({
        position: 'absolute',
        top: pos.top + 45,
        left: pos.left - 200
    }).addClass('active').removeClass('hidden');
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
    $('#modalMessage').modal()
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
        $('#modalMessage').modal()
    });
}

function pubsub(channel)
{
    try {
        ws.send(JSON.stringify({'action':'sub', 'channel': channel}));
        console.log("subscribing to " + channel);
    } catch (e) {
        setTimeout("pubsub('" + channel + "');", 1000);
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
