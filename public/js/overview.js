const validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

var overviewStats = undefined;

function updateStats(stats) {
    overviewStats = stats;
    console.log('stats updated');
    if (topsLoaded === false) loadTops();
}

var kmLoaded = false;

function loadKmsFromKilllistCache() {
	if (!$) return setTimeout(loadKmsFromKilllistCache, 100);

	kmLoaded = true;
	if ($("#killlist").length > 0) {
		$.get('/cache/tagged/killlist/?u=' + window.location.pathname, prepKills)
			.fail(function () {
				kmLoaded = false;
				console.error('Failed to load kill list!');
				setTimeout(loadKms, 1000);
			});
	}

	return true;
}

async function loadKms() {
	try {
		if (kmLoaded == true) return;
	
		const pathRegex = /^\/[^\/]+\/\d+\/(kills|losses|solo)?\/?$/;
		const currentPath = window.location.pathname;
		if (pathRegex.test(currentPath)) {
			kmLoaded = true;
			const pathMatch = currentPath.match(/^\/[^\/]+\/\d+\/(kills|losses|solo)?\/?$/);
			let type = 'mixed';
			if (pathMatch && pathMatch[1]) {
				type = pathMatch[1];
			}
			// remove kills/ or losses/ or solo/ from currentPath
			const usePath = currentPath.replace(/(kills|losses|solo)\/?/, '');
			const url = `${z3}${usePath}${type}.json`;
			let res = await fetch(url);
			if (res.ok) {
				const data = await res.json();
				await prepKills(data);
				kmLoaded = true;
			} else {
				if (res.status === 404) {
					loadKmsFromKilllistCache();
					return;
				}

				kmLoaded = false;
				console.error('Failed to load kill list JSON!');
				setTimeout(loadKms, 1000);
			}
			return;
		}

		loadKmsFromKilllistCache();
	} catch (e) {
		console.error("Error in loadKms:", e);
	}
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
