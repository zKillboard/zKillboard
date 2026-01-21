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
    if ($("#killlist").length > 0) $.get('/cache/tagged/killlist/?u=' + window.location.pathname, prepKills)
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

	try {
		let ksa = overviewStats.ksa;
		let kea = overviewStats.kea;

		$("#topset-isk").load("/cache/tagged/statstopisk/?u=" + window.location.pathname);
		validTopTypes.forEach((t) => $("#topset-" + t).load("/cache/tagged/statstop10/?u=" + window.location.pathname + "&t=" + t));

		topsLoaded = true;
		console.log('tops loaded');
	} finally {
		// Calculate next time the modulus will match
		let currentUnixTime = Math.floor(Date.now() / 1000);
		let entityMod = Number(entityID) % 900;
		let currentMod = currentUnixTime % 900;
		let secondsUntilNextMatch = entityMod > currentMod ? 
			(entityMod - currentMod) : 
			(900 - currentMod + entityMod);
		
		// Basic error checking, just in case
		if (isNaN(secondsUntilNextMatch)) secondsUntilNextMatch = 900;
		
		// Schedule for the exact next match time
		console.log(`scheduling next tops load in ${secondsUntilNextMatch} seconds`);
		setTimeout(loadTops, secondsUntilNextMatch * 1000);
	}
}

console.log('overview.js loaded');
