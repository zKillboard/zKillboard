const validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

var overviewStats = undefined;

loadKms();
loadTops();
function updateStats(stats) {
    overviewStats = stats;
    console.log('stats updated');
}

var kmLoaded = false;

function loadKmsFromKilllistCache() {
	kmLoaded = true;
	const killlistElement = document.querySelector("#killlist");
	if (killlistElement) {
		fetch('/cache/tagged/killlist/?u=' + window.location.pathname)
			.then(response => response.json())
			.then(data => prepKills(data))
			.catch(error => {
				kmLoaded = false;
				console.error('Failed to load kill list!', error);
				setTimeout(loadKms, 1000);
			});
	}

	return true;
}

async function loadKms() {
	if (topsLoaded) return;
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
async function loadTops() {
    if (window.location.pathname.includes('/page/')) return;

	try {
		// Load ISK top stats
		try {
			const response = await fetch("/cache/tagged/statstopisk/?u=" + window.location.pathname);
			const html = await response.text();
			const element = document.querySelector("#topset-isk");
			if (element) element.innerHTML = html;
		} catch (error) {
			console.error('Failed to load ISK stats:', error);
		}

		// Load top types
		for (const t of validTopTypes) {
			try {
				const response = await fetch("/cache/tagged/statstop10/?u=" + window.location.pathname + "&t=" + t);
				const html = await response.text();
				const element = document.querySelector("#topset-" + t);
				if (element) element.innerHTML = html;
			} catch (error) {
				console.error('Failed to load top stats for ' + t + ':', error);
			}
		}

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

