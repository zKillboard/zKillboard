const validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

var overviewStats = undefined;
var overviewLoadToken = 0;

zkbInitOverview();
function zkbInitOverview() {
	overviewStats = undefined;
	kmLoaded = false;
	topsLoaded = false;
	topsLoadedStats = {};
	overviewLoadToken++;
	loadKms(overviewLoadToken, window.location.pathname);
	loadTops(overviewLoadToken, window.location.pathname, entityID);
}

window.zkbInitOverview = zkbInitOverview;
function updateStats(stats) {
	overviewStats = stats;

	console.log(stats);
	console.log('stats updated');
return;
	const kills = stats['s-a-sd'] || 0;
	const losses = stats['s-a-sl'] || 0;
	const total = kills + losses;

	const valueToUse = window.location.pathname.includes('/losses/') ? losses : window.location.pathname.includes('/kills/') ? kills : total;
	for (i = 10; i > 1; i--) {
		let els = document.getElementsByClassName(`pagination-li-${i}`);

		if (valueToUse < ((i - 1) * 100)) {
			$(els).hide();
		} else {
			$(els).show();
		}
	}
}

var kmLoaded = false;

function loadKmsFromKilllistCache(token, pagePath) {
	if (!isCurrentOverviewLoad(token, pagePath)) return false;
	kmLoaded = true;
	const killlistElement = document.querySelector("#killlist");
	if (killlistElement) {
		fetch('/cache/tagged/killlist/?u=' + pagePath)
			.then(response => {
				if (!response.ok) throw new Error("Unexpected status " + response.status);
				const contentType = response.headers.get("content-type") || "";
				if (!contentType.includes("application/json")) throw new Error("Unexpected content type " + contentType);
				return response.json();
			})
			.then(data => {
				if (!isCurrentOverviewLoad(token, pagePath)) return;
				prepKills(data);
			})
			.catch(error => {
				if (!isCurrentOverviewLoad(token, pagePath)) return;
				kmLoaded = false;
				console.error('Failed to load kill list!', error);
				setTimeout(function() { loadKms(token, pagePath); }, 1000);
			});
	}

	return true;
}

async function loadKms(token, pagePath) {
	token = token || overviewLoadToken;
	pagePath = pagePath || window.location.pathname;
	if (!isCurrentOverviewLoad(token, pagePath)) return;
	try {
		if (kmLoaded == true) return;

		const pathRegex = /^\/[^\/]+\/\d+\/(kills|losses|solo)?\/?$/;
		const currentPath = pagePath;
		if (false && pathRegex.test(currentPath)) {
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
					loadKmsFromKilllistCache(token, pagePath);
					return;
				}

				kmLoaded = false;
				console.error('Failed to load kill list JSON!');
				setTimeout(function() { loadKms(token, pagePath); }, 1000);
			}
			return;
		}

		loadKmsFromKilllistCache(token, pagePath);
	} catch (e) {
		console.error("Error in loadKms:", e);
	}
}

var topsLoaded = false;
var topsLoadedStats = {};
async function loadTops(token, pagePath, pageEntityID) {
	token = token || overviewLoadToken;
	pagePath = pagePath || window.location.pathname;
	pageEntityID = pageEntityID || entityID;
	if (!isCurrentOverviewLoad(token, pagePath)) return;
	if (pagePath.includes('/page/')) return;

	try {
		// Load ISK top stats
		try {
			const response = await fetch("/cache/tagged/statstopisk/?u=" + pagePath);
			if (response.status >= 400) throw new Error("Unexpected status " + response.status);
			const html = await response.text();
			if (!isCurrentOverviewLoad(token, pagePath)) return;
			const element = document.querySelector("#topset-isk");
			if (element) element.innerHTML = html;
		} catch (error) {
			if (!isCurrentOverviewLoad(token, pagePath)) return;
			console.error('Failed to load ISK stats:', error);
		}

		// Load top types
		for (const t of validTopTypes) {
			try {
				const response = await fetch("/cache/tagged/statstop10/?u=" + pagePath + "&t=" + t);
				if (response.status >= 400) throw new Error("Unexpected status " + response.status);
				const html = await response.text();
				if (!isCurrentOverviewLoad(token, pagePath)) return;
				const element = document.querySelector("#topset-" + t);
				if (element) element.innerHTML = html;
			} catch (error) {
				if (!isCurrentOverviewLoad(token, pagePath)) return;
				console.error('Failed to load top stats for ' + t + ':', error);
			}
		}

		topsLoaded = true;
		console.log('tops loaded');
	} finally {
		if (!isCurrentOverviewLoad(token, pagePath)) return;
		// Calculate next time the modulus will match
		let currentUnixTime = Math.floor(Date.now() / 1000);
		let entityMod = Number(pageEntityID) % 900;
		let currentMod = currentUnixTime % 900;
		let secondsUntilNextMatch = entityMod > currentMod ?
			(entityMod - currentMod) :
			(900 - currentMod + entityMod);

		// Basic error checking, just in case
		if (isNaN(secondsUntilNextMatch)) secondsUntilNextMatch = 900;

		// Schedule for the exact next match time
		console.log(`scheduling next tops load in ${secondsUntilNextMatch} seconds`);
		setTimeout(function() { loadTops(token, pagePath, pageEntityID); }, secondsUntilNextMatch * 1000);
	}
}

console.log('overview.js loaded');

function isOverviewPath() {
	return isOverviewPathname(window.location.pathname);
}

function isOverviewPathname(pathname) {
	return /^\/(?:character|corporation|alliance|faction|ship|group|system|region|location)\/\d+\/(?:(?:kills|losses|solo|stats|wars|supers|trophies|ranks|top|topalltime|streambox|corpstats)\/?)?(?:page\/\d+\/?)?$/.test(pathname);
}

function isCurrentOverviewLoad(token, pagePath) {
	return token === overviewLoadToken && pagePath === window.location.pathname && isOverviewPathname(pagePath);
}
