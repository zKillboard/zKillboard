const validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

var overviewStats = undefined;

function updateStats(stats) {
    overviewStats = stats;
    console.log('stats updated');
    if (topsLoaded === false) loadTops();
    loadKms();
}

var kmLoaded = false;
function loadKms() {
    if (kmLoaded == true) return;

    kmLoaded = true;
    if ($("#killlist").length > 0) $.get('/cache/bypass/killlist/?u=' + window.location.pathname, prepKills)
        .fail(function(jqXHR, textStatus, errorThrown) {
            kmLoaded = false;
            console.error('Failed to load kill list!');
            setTimeout(loadKms, 1000);
        });
}

var topsLoaded = false;
var topsLoadedStats = {};
function loadTops() {
    if (window.location.pathname.includes('/page/')) return;

    setTimeout(loadTops, 60000);
    let now = new Date();
    if (topsLoaded == true && now.getMinutes() % 15 != 0) return; 

    let ksa = overviewStats.ksa;
    let kea = overviewStats.kea;

    $("#topset-isk").load("/cache/bypass/statstopisk/?u=" + window.location.pathname + "&ks=" + ksa + "&ke=" + kea);
    validTopTypes.forEach((t) => $("#topset-" + t).load("/cache/bypass/statstop10/?u=" + window.location.pathname + "&t=" + t + "&ks=" + ksa + "&ke=" + kea));

    topsLoaded = true;
    console.log('tops loaded');
}

console.log('overview.js loaded');
