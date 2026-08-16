(() => {
	const COLORS = ['#4dabf7', '#51cf66', '#ffd43b', '#ff6b6b'];
	let resizeObserver;

	function parseJsonl(text) {
		return text
			.split('\n')
			.map((line) => line.trim())
			.filter(Boolean)
			.map((line) => JSON.parse(line));
	}

	function number(value) {
		const parsed = Number(value);
		return Number.isFinite(parsed) ? parsed : 0;
	}

	function ownerColors(ownerBySystem, systems) {
		const neighbors = new Map();
		for (const ownerID of ownerBySystem.values()) neighbors.set(ownerID, new Set());

		for (const system of systems) {
			const ownerID = ownerBySystem.get(system.id);
			if (!ownerID) continue;
			for (const linkedID of system.linkedSystemIDs ?? []) {
				const linkedOwnerID = ownerBySystem.get(number(linkedID));
				if (!linkedOwnerID || linkedOwnerID === ownerID) continue;
				neighbors.get(ownerID).add(linkedOwnerID);
				neighbors.get(linkedOwnerID).add(ownerID);
			}
		}

		const colors = new Map();
		while (colors.size < neighbors.size) {
			const uncolored = [...neighbors.keys()].filter((ownerID) => !colors.has(ownerID));
			uncolored.sort((left, right) => {
				const leftColors = new Set([...neighbors.get(left)].map((ownerID) => colors.get(ownerID)).filter((color) => color != null));
				const rightColors = new Set([...neighbors.get(right)].map((ownerID) => colors.get(ownerID)).filter((color) => color != null));
				return rightColors.size - leftColors.size || neighbors.get(right).size - neighbors.get(left).size || left - right;
			});

			const ownerID = uncolored[0];
			const used = new Set([...neighbors.get(ownerID)].map((neighborID) => colors.get(neighborID)).filter((color) => color != null));
			let color = COLORS.findIndex((unused, index) => !used.has(index));
			if (color < 0) color = Math.abs(ownerID) % COLORS.length;
			colors.set(ownerID, color);
		}

		return colors;
	}

	async function initialize(component) {
		const canvas = component.querySelector('.sovereignty-map');
		const frame = component.querySelector('.sovereignty-map-frame');
		const loading = component.querySelector('.sovereignty-map-loading');
		const tooltip = component.querySelector('.sovereignty-map-tooltip');
		const reset = component.querySelector('[data-sovereignty-map-reset]');
		const dataElement = component.querySelector('.sovereignty-map-data');
		const context = canvas.getContext('2d');
		const data = JSON.parse(dataElement.content.textContent || '{}');
		const allianceID = number(data.allianceID);
		const ownerBySystem = new Map();
		for (const [ownerID, sovereignty] of Object.entries(data.sovereignty || {})) {
			for (const system of sovereignty.systems || []) ownerBySystem.set(number(system.solarSystemID), number(ownerID));
		}
		for (const system of data.systems || []) ownerBySystem.set(number(system.solarSystemID), allianceID);

		const allianceNames = new Map();
		for (const alliance of data.alliances || []) {
			const ticker = alliance.ticker ? ` <${alliance.ticker}>` : '';
			allianceNames.set(number(alliance.allianceID), `${alliance.allianceName}${ticker}`);
		}
		if (allianceID > 0 && data.allianceName) allianceNames.set(allianceID, data.allianceName);

		try {
			const [systemsResponse, constellationsResponse, regionsResponse] = await Promise.all([
				fetch(canvas.dataset.systemsUrl),
				fetch(canvas.dataset.constellationsUrl),
				fetch(canvas.dataset.regionsUrl)
			]);
			if (!systemsResponse.ok || !constellationsResponse.ok || !regionsResponse.ok) throw new Error('Map data unavailable');

			const [rawSystems, constellations, regions] = await Promise.all([
				systemsResponse.text().then(parseJsonl),
				constellationsResponse.text().then(parseJsonl),
				regionsResponse.text().then(parseJsonl)
			]);
			if (!component.isConnected) return;
			const regionByConstellation = new Map(constellations.map((constellation) => [number(constellation.id), number(constellation.regionID)]));
			const regionNames = new Map(regions.map((region) => [number(region.id), region.regionName]));
			const systems = rawSystems.filter((system) => regionByConstellation.get(number(system.constellationID)) < 10099999);
			const minX = Math.min(...systems.map((system) => number(system.position?.x)));
			const maxX = Math.max(...systems.map((system) => number(system.position?.x)));
			const maxZ = Math.max(...systems.map((system) => number(system.position?.z)));
			const span = maxX - minX || 1;

			for (const system of systems) {
				system.id = number(system.id);
				system.regionID = regionByConstellation.get(number(system.constellationID));
				system.x = (number(system.position?.x) - minX) / span;
				system.y = (maxZ - number(system.position?.z)) / span;
			}

			const systemByID = new Map(systems.map((system) => [system.id, system]));
			const shownSystems = systems.filter((system) => {
				const ownerID = ownerBySystem.get(system.id);
				return ownerID && (!allianceID || ownerID === allianceID);
			});
			if (shownSystems.length === 0) throw new Error('No sovereignty systems to map');

			const colorsByOwner = ownerColors(ownerBySystem, systems);
			const connections = [];
			const seenConnections = new Set();
			for (const system of systems) {
				for (const linkedIDValue of system.linkedSystemIDs ?? []) {
					const linkedID = number(linkedIDValue);
					const linked = systemByID.get(linkedID);
					if (!linked) continue;
					const key = system.id < linkedID ? `${system.id}:${linkedID}` : `${linkedID}:${system.id}`;
					if (seenConnections.has(key)) continue;
					seenConnections.add(key);
					connections.push([system, linked]);
				}
			}

			const regionTotals = new Map();
			for (const system of systems) {
				const region = regionTotals.get(system.regionID) || { x: 0, y: 0, count: 0 };
				region.x += system.x;
				region.y += system.y;
				region.count += 1;
				regionTotals.set(system.regionID, region);
			}
			const regionLabels = [...regionTotals.entries()].map(([regionID, region]) => ({
				name: regionNames.get(regionID) || '',
				x: region.x / region.count,
				y: region.y / region.count
			}));

			const state = {
				width: 0,
				height: 0,
				dpr: 1,
				centerX: 0,
				centerY: 0,
				scale: 1,
				fitScale: 1,
				hovered: null,
				drag: null
			};

			function screenPoint(system) {
				return {
					x: (system.x - state.centerX) * state.scale + state.width / 2,
					y: (system.y - state.centerY) * state.scale + state.height / 2
				};
			}

			function visible(point, margin = 20) {
				return point.x >= -margin && point.x <= state.width + margin && point.y >= -margin && point.y <= state.height + margin;
			}

			function fit() {
				let selectedMinX = Math.min(...shownSystems.map((system) => system.x));
				let selectedMaxX = Math.max(...shownSystems.map((system) => system.x));
				let selectedMinY = Math.min(...shownSystems.map((system) => system.y));
				let selectedMaxY = Math.max(...shownSystems.map((system) => system.y));
				const selectedSpanX = Math.max(selectedMaxX - selectedMinX, 0.06);
				const selectedSpanY = Math.max(selectedMaxY - selectedMinY, 0.06);
				state.centerX = (selectedMinX + selectedMaxX) / 2;
				state.centerY = (selectedMinY + selectedMaxY) / 2;
				state.fitScale = Math.min(state.width * 0.84 / selectedSpanX, state.height * 0.84 / selectedSpanY);
				state.scale = state.fitScale;
				draw();
			}

			function drawBackground() {
				context.fillStyle = '#04070b';
				context.fillRect(0, 0, state.width, state.height);
				const glow = context.createRadialGradient(state.width * 0.2, state.height * 0.2, 0, state.width * 0.2, state.height * 0.2, state.width * 0.65);
				glow.addColorStop(0, 'rgba(72, 108, 154, 0.18)');
				glow.addColorStop(1, 'rgba(4, 7, 11, 0)');
				context.fillStyle = glow;
				context.fillRect(0, 0, state.width, state.height);
				context.strokeStyle = 'rgba(130, 170, 220, 0.025)';
				context.lineWidth = 1;
				for (let x = 0; x < state.width; x += 80) {
					context.beginPath();
					context.moveTo(x, 0);
					context.lineTo(x, state.height);
					context.stroke();
				}
				for (let y = 0; y < state.height; y += 80) {
					context.beginPath();
					context.moveTo(0, y);
					context.lineTo(state.width, y);
					context.stroke();
				}
			}

			function draw() {
				context.clearRect(0, 0, state.width, state.height);
				drawBackground();
				context.lineWidth = 0.7;
				context.strokeStyle = 'rgba(170, 200, 240, 0.12)';
				context.beginPath();
				for (const [from, to] of connections) {
					const fromPoint = screenPoint(from);
					const toPoint = screenPoint(to);
					if (!visible(fromPoint, 40) && !visible(toPoint, 40)) continue;
					context.moveTo(fromPoint.x, fromPoint.y);
					context.lineTo(toPoint.x, toPoint.y);
				}
				context.stroke();

				context.fillStyle = 'rgba(210, 225, 240, 0.18)';
				for (const system of systems) {
					const point = screenPoint(system);
					if (!visible(point)) continue;
					context.fillRect(point.x - 0.6, point.y - 0.6, 1.2, 1.2);
				}

				context.lineWidth = 1.5;
				for (const [from, to] of connections) {
					const fromOwner = ownerBySystem.get(from.id);
					const toOwner = ownerBySystem.get(to.id);
					if (!fromOwner || !toOwner || (allianceID && (fromOwner !== allianceID || toOwner !== allianceID))) continue;
					const fromPoint = screenPoint(from);
					const toPoint = screenPoint(to);
					if (!visible(fromPoint, 40) && !visible(toPoint, 40)) continue;
					const gradient = context.createLinearGradient(fromPoint.x, fromPoint.y, toPoint.x, toPoint.y);
					gradient.addColorStop(0, COLORS[colorsByOwner.get(fromOwner)]);
					gradient.addColorStop(1, COLORS[colorsByOwner.get(toOwner)]);
					context.globalAlpha = 0.42;
					context.strokeStyle = gradient;
					context.beginPath();
					context.moveTo(fromPoint.x, fromPoint.y);
					context.lineTo(toPoint.x, toPoint.y);
					context.stroke();
				}
				context.globalAlpha = 1;

				context.textAlign = 'center';
				context.textBaseline = 'middle';
				context.font = '700 12px "Droid Sans", sans-serif';
				context.lineWidth = 3;
				for (const region of regionLabels) {
					const point = screenPoint(region);
					if (!visible(point, 80)) continue;
					context.strokeStyle = 'rgba(0, 0, 0, 0.9)';
					context.fillStyle = 'rgba(225, 237, 248, 0.35)';
					context.strokeText(region.name, point.x, point.y);
					context.fillText(region.name, point.x, point.y);
				}

				for (const system of shownSystems) {
					const point = screenPoint(system);
					if (!visible(point)) continue;
					const ownerID = ownerBySystem.get(system.id);
					const color = COLORS[colorsByOwner.get(ownerID)];
					const radius = state.hovered === system ? 5 : 3;
					context.fillStyle = color;
					context.strokeStyle = 'rgba(0, 0, 0, 0.9)';
					context.lineWidth = 1.5;
					context.beginPath();
					context.arc(point.x, point.y, radius, 0, Math.PI * 2);
					context.fill();
					context.stroke();
					context.fillStyle = '#f8fcff';
					context.beginPath();
					context.arc(point.x, point.y, 1, 0, Math.PI * 2);
					context.fill();
				}

				const vignette = context.createRadialGradient(state.width / 2, state.height / 2, Math.min(state.width, state.height) * 0.35, state.width / 2, state.height / 2, Math.max(state.width, state.height) * 0.72);
				vignette.addColorStop(0, 'rgba(2, 4, 7, 0)');
				vignette.addColorStop(1, 'rgba(2, 4, 7, 0.55)');
				context.fillStyle = vignette;
				context.fillRect(0, 0, state.width, state.height);
			}

			function resize() {
				const bounds = frame.getBoundingClientRect();
				state.width = bounds.width;
				state.height = bounds.height;
				state.dpr = Math.min(window.devicePixelRatio || 1, 2);
				canvas.width = Math.round(state.width * state.dpr);
				canvas.height = Math.round(state.height * state.dpr);
				context.setTransform(state.dpr, 0, 0, state.dpr, 0, 0);
				fit();
			}

			function nearestSystem(x, y) {
				let nearest = null;
				let nearestDistance = 12;
				for (const system of shownSystems) {
					const point = screenPoint(system);
					const distance = Math.hypot(point.x - x, point.y - y);
					if (distance >= nearestDistance) continue;
					nearest = system;
					nearestDistance = distance;
				}
				return nearest;
			}

			canvas.addEventListener('pointerdown', (event) => {
				const bounds = canvas.getBoundingClientRect();
				state.hovered = nearestSystem(event.clientX - bounds.left, event.clientY - bounds.top);
				canvas.setPointerCapture(event.pointerId);
				state.drag = { x: event.clientX, y: event.clientY, moved: false };
				canvas.style.cursor = 'grabbing';
			});

			canvas.addEventListener('pointermove', (event) => {
				const bounds = canvas.getBoundingClientRect();
				if (state.drag) {
					const deltaX = event.clientX - state.drag.x;
					const deltaY = event.clientY - state.drag.y;
					if (Math.abs(deltaX) + Math.abs(deltaY) > 2) state.drag.moved = true;
					state.centerX -= deltaX / state.scale;
					state.centerY -= deltaY / state.scale;
					state.drag.x = event.clientX;
					state.drag.y = event.clientY;
					draw();
					return;
				}

				state.hovered = nearestSystem(event.clientX - bounds.left, event.clientY - bounds.top);
				canvas.style.cursor = state.hovered ? 'pointer' : 'grab';
				if (state.hovered) {
					const ownerID = ownerBySystem.get(state.hovered.id);
					tooltip.textContent = `${state.hovered.name} · ${allianceNames.get(ownerID) || `Alliance ${ownerID}`}`;
					tooltip.style.left = `${event.clientX - bounds.left + 12}px`;
					tooltip.style.top = `${event.clientY - bounds.top + 12}px`;
					tooltip.classList.remove('d-none');
				} else {
					tooltip.classList.add('d-none');
				}
				draw();
			});

			canvas.addEventListener('pointerup', () => {
				if (state.drag && !state.drag.moved && state.hovered) window.location.href = `/system/${state.hovered.id}/`;
				state.drag = null;
				canvas.style.cursor = state.hovered ? 'pointer' : 'grab';
			});

			canvas.addEventListener('pointerleave', () => {
				if (!state.drag) {
					state.hovered = null;
					tooltip.classList.add('d-none');
					draw();
				}
			});

			canvas.addEventListener('wheel', (event) => {
				event.preventDefault();
				const bounds = canvas.getBoundingClientRect();
				const x = event.clientX - bounds.left;
				const y = event.clientY - bounds.top;
				const worldX = state.centerX + (x - state.width / 2) / state.scale;
				const worldY = state.centerY + (y - state.height / 2) / state.scale;
				const nextScale = Math.max(state.fitScale * 0.5, Math.min(state.fitScale * 20, state.scale * Math.exp(-event.deltaY * 0.001)));
				state.centerX = worldX - (x - state.width / 2) / nextScale;
				state.centerY = worldY - (y - state.height / 2) / nextScale;
				state.scale = nextScale;
				draw();
			}, { passive: false });

			reset.addEventListener('click', fit);
			resizeObserver = new ResizeObserver(resize);
			resizeObserver.observe(frame);
			loading.classList.add('d-none');
			resize();
		} catch (error) {
			loading.textContent = error.message || 'Map unavailable';
			loading.classList.remove('text-body-secondary');
			loading.classList.add('text-warning');
		}
	}

	function zkbInitSovereigntyMap() {
		for (const component of document.querySelectorAll('.sovereignty-map-component')) {
			if (component.dataset.sovereigntyMapInitialized) continue;
			component.dataset.sovereigntyMapInitialized = '1';
			initialize(component);
		}
		window.zkbPageCleanup = function () {
			if (resizeObserver) resizeObserver.disconnect();
			resizeObserver = undefined;
		};
	}

	window.zkbInitSovereigntyMap = zkbInitSovereigntyMap;
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', zkbInitSovereigntyMap);
	else zkbInitSovereigntyMap();
})();
