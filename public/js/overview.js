const validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

var overviewStats = undefined;
var overviewLoadToken = 0;

document.addEventListener('click', overviewTopSortClick);
zkbInitOverview();
function zkbInitOverview() {
	overviewStats = undefined;
	kmLoaded = false;
	topsLoaded = false;
	topsLoadedStats = {};
	overviewLoadToken++;
	loadKms(overviewLoadToken, window.location.pathname);
	loadTops(overviewLoadToken, window.location.pathname, entityID);
	loadCharacterEsiInformation(overviewLoadToken, window.location.pathname, entityID);
}

window.zkbInitOverview = zkbInitOverview;
async function loadCharacterEsiInformation(token, pagePath, pageEntityID) {
	let securityTarget = document.querySelector('[data-zkb-character-security]');
	let titleTarget = document.querySelector('[data-zkb-character-title]');
	let characterID = Number(securityTarget ? securityTarget.dataset.characterId : (titleTarget ? titleTarget.dataset.characterId : pageEntityID));
	if ((!securityTarget && !titleTarget) || !(characterID > 0)) return;

	let key = `characters:${characterID}`;
	let cached = await getCachedEsiInfo([key]);
	let response = cached[key];
	if (!response || !response.detail || !Object.prototype.hasOwnProperty.call(response.detail, 'character_title_id')) {
		try {
			let esiResponse = await fetch(`https://esi.evetech.net/characters/${characterID}/?datasource=tranquility`, {
				headers: {'Accept': 'application/json', 'X-Compatibility-Date': '2026-07-21', 'X-User-Agent': 'zkillboard.com Character Page'}
			});
			if (!esiResponse.ok) return;
			let detail = await esiResponse.json();
			response = {
				entity: {endpoint: 'characters', id: characterID},
				detail: {
					name: detail.name,
					corporation_id: detail.corporation_id,
					alliance_id: detail.alliance_id,
					faction_id: detail.faction_id,
					security_status: detail.security_status,
					character_title_id: detail.character_title_id || null
				}
			};
			await cacheEsiInfo([{key: key, data: response}]);
		} catch (e) {
			return;
		}
	}

	if (!isCurrentOverviewLoad(token, pagePath)) return;
	if (securityTarget && securityTarget.isConnected && Number(securityTarget.dataset.characterId) == characterID && response.detail.security_status != null && response.detail.security_status !== '') {
		let security = Number(response.detail.security_status);
		if (Number.isFinite(security)) {
			let calcStatus = Math.min(1, (Math.max(-5, Math.min(5, security)) / 5) + 0.8).toFixed(1);
			let securityColors = {
				'1.0': '#2c74e0', '0.9': '#3a9aeb', '0.8': '#4ecef8', '0.7': '#60d9a3', '0.6': '#71e554',
				'0.5': '#f3fd82', '0.4': '#DC6D07', '0.3': '#ce440f', '0.2': '#bc1117', '0.1': '#722020'
			};
			securityTarget.textContent = security.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
			securityTarget.style.color = securityColors[calcStatus] || '#8d3264';
		}
	}

	if (!titleTarget || !titleTarget.isConnected || Number(titleTarget.dataset.characterId) != characterID) return;
	let titleRow = titleTarget.closest('[data-zkb-character-title-row]');
	let titleID = response.detail.character_title_id || '';
	if (titleID == '') {
		titleTarget.textContent = '';
		titleTarget.dataset.characterTitleId = '';
		if (titleRow) titleRow.classList.add('d-none');
		return;
	}

	if (titleTarget.dataset.characterTitleId != titleID || titleTarget.textContent.trim() == '' || titleTarget.textContent.trim() == titleID) {
		let titleName = titleID;
		try {
			let titleResponse = await fetch(`/api/character-title/${encodeURIComponent(titleID)}/`);
			if (titleResponse.ok) {
				let titleDetail = await titleResponse.json();
				if (titleDetail.name) titleName = titleDetail.name;
			}
		} catch (e) {}
		if (!isCurrentOverviewLoad(token, pagePath) || !titleTarget.isConnected || Number(titleTarget.dataset.characterId) != characterID) return;
		titleTarget.textContent = titleName;
	}
	titleTarget.dataset.characterTitleId = titleID;
	if (titleRow) titleRow.classList.remove('d-none');
}

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

async function overviewTopSortClick(event) {
	const button = event.target.closest('[data-zkb-top-sort]');
	if (!button) return;

	event.preventDefault();
	event.stopPropagation();
	if (button.classList.contains('active')) return;

	const topList = button.closest('[data-zkb-top-list]');
	if (!topList) return;

	const sortBy = button.getAttribute('data-zkb-top-sort') == 'isk' ? 'isk' : 'kills';
	const sortUri = topList.getAttribute('data-zkb-top-sort-uri');
	const sortType = topList.getAttribute('data-zkb-top-sort-type');
	if (!sortUri || !sortType) return;

	const topListButtons = topList.querySelectorAll('[data-zkb-top-sort]');
	topListButtons.forEach(function(button) { button.disabled = true; });
	topList.setAttribute('aria-busy', 'true');

	try {
		const params = new URLSearchParams({u: sortUri, t: sortType, s: sortBy});
		const response = await fetch('/cache/tagged/statstop10/?' + params.toString());
		if (response.status >= 400) throw new Error('Unexpected status ' + response.status);

		const html = await response.text();
		if (!topList.isConnected) return;
		const template = document.createElement('template');
		template.innerHTML = html.trim();
		const refreshedTopList = template.content.firstElementChild;
		if (!refreshedTopList) throw new Error('Empty top stats response');

		topList.replaceWith(refreshedTopList);
		const focusButton = refreshedTopList.querySelector('[data-zkb-top-sort="' + sortBy + '"]');
		if (focusButton) focusButton.focus();
		doFormats();
		setTimeout(prepTippy, 1);
	} catch (error) {
		console.error('Failed to sort top stats:', error);
		topListButtons.forEach(function(button) { button.disabled = false; });
		topList.removeAttribute('aria-busy');
	}
}

function isCurrentOverviewLoad(token, pagePath) {
	return token === overviewLoadToken
		&& pagePath === window.location.pathname
		&& !!document.querySelector('#killlist');
}
