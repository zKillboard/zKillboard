$(document).ready(function() {
    // Check to see if the user has ads enabled
    if ( $("iframe").length == 0 ) {
        //$("#adsensetop, #adsensebottom").html("<hr/><center>Blocking ads? That's fine, ads suck anyway. <a href='/information/payments'>Block them with ISK and get a golden wreck too.</a></center><hr/>");
    }

    if ($("[rel=tooltip]").length) {
        $("[rel=tooltip]").tooltip({
            placement: "bottom",
            animation: false
        });
    }

    //
    $('.dropdown-toggle').dropdown();
    $("abbr.timeago").timeago();
    $(".alert").alert()

    // Javascript to enable link to tab
    var url = document.location.toString();
    if (url.match('#')) {
        $('.nav-pills a[href=#'+url.split('#')[1]+']').tab('show') ;
    }

    // Change hash for page-reload
    $('.nav-pills a').on('shown', function (e) {
        window.location.hash = e.target.hash;
    })

    // hide #back-top first
    $("#back-top").hide();

    // fade in #back-top
    $(function () {
        $(window).scroll(function () {
            if ($(this).scrollTop() > 500) {
                $('#back-top').fadeIn();
            } else {
                $('#back-top').fadeOut();
            }
        });

        // scroll body to 0px on click
        $('#back-top a').click(function () {
            $('body,html').animate({
                scrollTop: 0
            }, 100);
            return false;
        });
    });

    //add the autocomplete search thing
    $('#searchbox').zz_search( function(data, event) { window.location = '/' + data.type + '/' + data.id + '/'; event.preventDefault(); } );

    //and for the tracker entity lookup
    $('#addentitybox').zz_search( function(data) { 
        $('#addentity input[name="entitymetadata"]').val(JSON.stringify(data));
        $('#addentity input[name="addentitybox"]').val(data.name);
        $('#addentity').submit();
    });

    // prevent firing of window.location in table rows if a link is clicked directly
    $('.killListRow a').click(function(e) {
        e.stopPropagation();
    });

    $('a.openMenu').click(function(e){
        $('.content').toggleClass('opened');
        $('.mobileNav').toggleClass('opened');
        e.preventDefault();
    });

    // auto show comments tab on detail page
    if(window.location.hash.match(/comment/)) {
        $('a[href="#comment"]').tab('show');
    }

    if (top !== self) {
        $("#iframed").modal('show');
    }

    $("#killmailurl").bind('paste', function(event) {
        //console.log(event);
        setTimeout(sendCrestUrl, 1);
    });

      // setup websocket with callbacks
    var ws = new ReconnectingWebSocket('wss://zkillboard.com:2096/', '', {maxReconnectAttempts: 15});
    ws.onmessage = function(event) {
        wslog(event.data);
    };

    addKillListClicks();
    //$("a[href='/']").on('click', function(event) { doLoad($(this).attr('href')); return false; } );
    //addPartials();
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
                window.focus();
                window.location = data.url;
            };
        }
    }
}

function wslog(msg)
{
    if (msg == 'ping' || msg == 'pong') return;
    json = JSON.parse(msg);
    console.log(json);
    if (json.action == 'tqStatus') {
        tqStatus = json.tqStatus;
        tqCount = json.tqCount;
        if (tqStatus == 'OFFLINE') {
            html = '<span class="red">TQ ' + tqCount + "</span>";
        } else {
            html = '<span class="green">TQ ' + tqCount + "</span>";
        }
        $("#tqStatus").html(html);
        $("#lasthour").text(json.kills);
    } else if (json.action == 'reload') {
        setTimeout("location.reload();", (Math.random() * 300000));
    } else if (json.action == 'bigkill') {
        htmlNotify(json);
    }
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

$('body').on('touchstart.dropdown', '.dropdown-menu', function (e) { e.stopPropagation(); });

function hideSortStuff(doHide)
{
    if (doHide) $(".hide-when-sorted").hide();
    else $(".hide-when-sorted").show();
}

function sendCrestUrl() {
    str = $("#killmailurl").val();
    strSplit = str.split("/");
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
    //var partials = ['kill', 'character', 'corporation', 'alliance', 'faction', 'system', 'region', 'group', 'ship', 'location'];
    var partials = ['kill', 'faction', 'system', 'region', 'group', 'ship', 'location'];
    for (partial of partials) {
        $(".pagecontent a[href^='/" + partial + "/']").on('click', function(event) { doLoad($(this).attr('href')); return false; } );
    }
}

function loadCompleted() {
    window.scrollTo(0, 0);
    NProgress.done();
    addPartials();
    addKillListClicks();
}

function doLoad(url) {
    //console.log("Loading: " + url);
    var pathname = window.location.pathname;
    var state = { 'href' : pathname };
    NProgress.start();
    $(".pagecontent").load('/partial' + url, null, loadCompleted);
    $("#adsensetop").load('/google/');
    $("#adsensebottom").load('/google/');
    history.pushState(state, null, url);
}

// Revert to a previously saved state
window.addEventListener('popstate', function(event) {
    window.location = window.location;
});

function addKillListClicks()
{
    $(".killListRow").on('click', function(event) {
        if (event.which == 2) return false;
        console.log($(this).attr('killID'));
        //onclick="if (event.which == 2) return false; window.location='/kill/{{kill.killID}}/'"
        //window.location = '/kill/' + $(this).attr('killID') + '/';
        doLoad('/kill/' + $(this).attr('killID') + '/');
        return false;
    });
}

function doSponsor(url)
{
console.log(url);
    $('#modalMessageBody').load(url);
    $('#modalTitle').text('Sponsor this killmail');
    $('#modalMessage').modal()
}
