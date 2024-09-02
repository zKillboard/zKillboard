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

    if ($("#killlist").length > 0) $.get('/cache/1hour/killlist/?s=' + overviewStats.sequence + '&u=' + window.location.pathname, prepKills);
    kmLoaded = true;
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

    $("#topset-isk").load("/cache/1hour/statstopisk?u=" + window.location.pathname + "&ks=" + ksa + "&ke=" + kea);
    validTopTypes.forEach((t) => $("#topset-" + t).load("/cache/24hour/statstop10?u=" + window.location.pathname + "&t=" + t + "&ks=" + ksa + "&ke=" + kea));

    topsLoaded = true;
    console.log('tops loaded');
}
