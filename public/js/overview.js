$(document).ready(loadOverview);

const validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

function loadOverview() {
    if (typeof $ === 'undefined') return setTimeout(loadOverview, 1);

    if ($("#killlist").length > 0) $.get('/cache/bypass/killlist/?u=' + window.location.pathname, prepKills);
    loadTops();
}

var topsLoaded = false;
function loadTops() {
    setTimeout(loadTops, 60000);

    let now = new Date();
    if (topsLoaded == true && now.getMinutes() % 15 != 0) return; 

    $("#topset-isk").load("/cache/bypass/statstopisk/?u=" + window.location.pathname);
    validTopTypes.forEach((t) => $("#topset-" + t).load("/cache/bypass/statstop10/?u=" + window.location.pathname + "&t=" + t));
    topsLoaded = true;
}
