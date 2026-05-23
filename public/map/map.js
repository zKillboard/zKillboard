document.addEventListener('DOMContentLoaded', () => {
	const config = {
		apiUrl: '',
		r2z2BaseUrl: 'https://r2z2.zkillboard.com/ephemeral',
		systemsUrl: '/data/mapSystems.jsonl',
		regionsUrl: '/data/mapRegions.jsonl',
		constellationsUrl: '/data/mapConstellations.jsonl',
		...(window.mapPageConfig || {})
	};
	const canvas = document.getElementById('mapCanvas');
	const context = canvas.getContext('2d', { alpha: true });
	const loading = document.getElementById('mapLoading');
	const hint = document.getElementById('mapHint');
	const liveFeed = document.getElementById('liveKillFeed');
	const systemCount = document.getElementById('mapSystemCount');
	const liveCount = document.getElementById('mapLiveCount');
	const refreshAt = document.getElementById('mapRefreshAt');
	const mapSequence = document.getElementById('mapSequence');
	const controls = document.getElementById('mapToolbar');
	const hudRegion = document.getElementById('hudRegion');
	const hudConstellation = document.getElementById('hudConstellation');
	const hudSystem = document.getElementById('hudSystem');
	const liveKillsPanel = document.getElementById('liveKillsPanel');
	const statusPanel = document.querySelector('.top-right');

	const SECURITY_COLORS = {
		'1.0': '#2c74e0',
		'0.9': '#3a9aeb',
		'0.8': '#4ecef8',
		'0.7': '#60d9a3',
		'0.6': '#71e554',
		'0.5': '#f3fd82',
		'0.4': '#DC6D07',
		'0.3': '#ce440f',
		'0.2': '#bc1117',
		'0.1': '#722020'
	};
	const KILL_TTL_MS = 15 * 60 * 1000;

	const state = {
		systems: [],
		systemById: new Map(),
		connections: [],
		regionLabels: [],
		constellationLabels: [],
		feed: [],
		heat: new Map(),
		seenSequences: new Set(),
		camera: {
			x: 0,
			y: 0,
			zoom: 1,
			minZoom: 0.1,
			maxZoom: 7
		},
		canvasWidth: 0,
		canvasHeight: 0,
		dpr: window.devicePixelRatio || 1,
		hoveredSystem: null,
		pointer: null,
		worldBounds: null,
		sequence: 0,
		retryAfterMs: 6000,
		pollTimer: null,
		animationFrame: null
	};

	function parseJsonl(text) {
		return text
			.split('\n')
			.map((line) => line.trim())
			.filter(Boolean)
			.map((line) => JSON.parse(line));
	}

	function asNumber(value) {
		const parsed = Number(value);
		return Number.isFinite(parsed) ? parsed : 0;
	}

	function clamp(value, min, max) {
		return Math.max(min, Math.min(max, value));
	}

	function formatIsk(value) {
		const amount = asNumber(value);
		if (amount < 10000) return amount.toLocaleString();
		const suffixes = ['', 'k', 'm', 'b', 't'];
		let scaled = amount;
		let suffixIndex = 0;
		while (scaled >= 1000 && suffixIndex < suffixes.length - 1) {
			scaled /= 1000;
			suffixIndex += 1;
		}
		return `${scaled.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}${suffixes[suffixIndex]}`;
	}

	function formatTime(value) {
		const date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
		if (Number.isNaN(date.getTime())) return '--';
		return `${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: 'UTC', hour12: false })} UTC`;
	}

	function getKillTimestamp(kill) {
		const uploadedAt = asNumber(kill.uploaded_at);
		if (uploadedAt > 0) return uploadedAt * 1000;
		if (kill.killmail_time) {
			const parsed = new Date(kill.killmail_time).getTime();
			if (Number.isFinite(parsed)) return parsed;
		}
		return Date.now();
	}

	function getKillImageUrl(shipTypeId) {
		const typeId = asNumber(shipTypeId);
		return typeId > 0
			? `https://images.evetech.net/types/${typeId}/icon?size=64`
			: '/img/eve-question.png';
	}

	function securityColor(securityStatus) {
		const rounded = clamp(Math.floor(Math.max(0, asNumber(securityStatus)) * 10) / 10, 0, 1).toFixed(1);
		return SECURITY_COLORS[rounded] || '#8d3264';
	}

	function hexToRgb(hex) {
		const normalized = hex.replace('#', '');
		const value = normalized.length === 3
			? normalized.split('').map((part) => part + part).join('')
			: normalized;
		const num = Number.parseInt(value, 16);
		return {
			r: (num >> 16) & 255,
			g: (num >> 8) & 255,
			b: num & 255
		};
	}

	function rgba(hex, alpha) {
		const rgb = hexToRgb(hex);
		return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
	}

	function requestDraw() {
		if (state.animationFrame) return;
		state.animationFrame = window.requestAnimationFrame(() => {
			state.animationFrame = null;
			draw();
		});
	}

	function resizeCanvas() {
		state.dpr = window.devicePixelRatio || 1;
		state.canvasWidth = window.innerWidth;
		state.canvasHeight = window.innerHeight;
		canvas.width = Math.floor(state.canvasWidth * state.dpr);
		canvas.height = Math.floor(state.canvasHeight * state.dpr);
		canvas.style.width = `${state.canvasWidth}px`;
		canvas.style.height = `${state.canvasHeight}px`;
		context.setTransform(state.dpr, 0, 0, state.dpr, 0, 0);
		if (state.worldBounds) fitCamera();
		requestDraw();
	}

	function getFitInsets() {
		if (window.innerWidth <= 1100) {
			return { left: 0, right: 0 };
		}

		const gutter = 28;
		const liveKillsRight = liveKillsPanel ? liveKillsPanel.getBoundingClientRect().right : 0;
		const statusRight = statusPanel ? statusPanel.getBoundingClientRect().right : 0;
		const left = Math.max(0, liveKillsRight, statusRight) + gutter;
		const right = 0;
		return { left, right };
	}

	function getFitTargetScreenX(insets) {
		if (window.innerWidth <= 1100) {
			return state.canvasWidth / 2;
		}

		const leftBound = insets.left;
		const rightBound = state.canvasWidth - insets.right;
		const target = state.canvasWidth * 0.60;
		return clamp(target, leftBound + 120, rightBound - 120);
	}

	function toScreen(point) {
		return {
			x: (point.x - state.camera.x) * state.camera.zoom + state.canvasWidth / 2,
			y: (point.y - state.camera.y) * state.camera.zoom + state.canvasHeight / 2
		};
	}

	function screenToWorld(x, y) {
		return {
			x: (x - state.canvasWidth / 2) / state.camera.zoom + state.camera.x,
			y: (y - state.canvasHeight / 2) / state.camera.zoom + state.camera.y
		};
	}

	function isDisplayRegion(regionId) {
		return asNumber(regionId) < 10099999;
	}

	function computeWorldLayout(systems, constellations, regions) {
		const regionById = new Map(regions.map((region) => [region.id, region.regionName]));
		const constellationById = new Map(constellations.map((constellation) => [constellation.id, constellation]));
		const visibleSystems = systems.filter((system) => {
			const constellation = constellationById.get(system.constellationID);
			return isDisplayRegion(constellation?.regionID);
		});

		let minX = Number.POSITIVE_INFINITY;
		let minY = Number.POSITIVE_INFINITY;
		let maxX = Number.NEGATIVE_INFINITY;
		let maxY = Number.NEGATIVE_INFINITY;

		for (const system of visibleSystems) {
			const px = asNumber(system.position?.x);
			const py = asNumber(system.position?.z);
			minX = Math.min(minX, px);
			minY = Math.min(minY, py);
			maxX = Math.max(maxX, px);
			maxY = Math.max(maxY, py);
		}

		const spanX = maxX - minX || 1;
		const spanY = maxY - minY || 1;
		const worldWidth = 4200;
		const worldHeight = worldWidth * (spanY / spanX);

		state.systems = visibleSystems.map((system) => {
			const constellation = constellationById.get(system.constellationID);
			return {
				...system,
				regionID: asNumber(constellation?.regionID),
				regionName: regionById.get(constellation?.regionID) ?? '',
				constellationName: constellation?.constellationName ?? '',
				worldX: ((asNumber(system.position?.x) - minX) / spanX - 0.5) * worldWidth,
				worldY: ((asNumber(system.position?.z) - minY) / spanY - 0.5) * worldHeight,
				color: securityColor(system.securityStatus)
			};
		});

		state.systemById = new Map(state.systems.map((system) => [system.id, system]));
		state.worldBounds = {
			minX: -worldWidth / 2,
			maxX: worldWidth / 2,
			minY: -worldHeight / 2,
			maxY: worldHeight / 2
		};

		const seenConnections = new Set();
		state.connections = [];
		for (const system of state.systems) {
			for (const linkedId of system.linkedSystemIDs ?? []) {
				const other = state.systemById.get(linkedId);
				if (!other) continue;
				const key = system.id < linkedId ? `${system.id}:${linkedId}` : `${linkedId}:${system.id}`;
				if (seenConnections.has(key)) continue;
				seenConnections.add(key);
				state.connections.push({ from: system, to: other });
			}
		}

		const regionAccumulator = new Map();
		for (const system of state.systems) {
			const regionEntry = regionAccumulator.get(system.regionName) || { x: 0, y: 0, count: 0 };
			regionEntry.x += system.worldX;
			regionEntry.y += system.worldY;
			regionEntry.count += 1;
			regionAccumulator.set(system.regionName, regionEntry);
		}

		state.regionLabels = Array.from(regionAccumulator.entries()).map(([name, entry]) => ({
			name,
			count: entry.count,
			x: entry.x / entry.count,
			y: entry.y / entry.count
		}));

		state.constellationLabels = [];
	}

	function fitCamera() {
		const margin = 48;
		const bounds = state.worldBounds;
		if (!bounds) return;
		const insets = getFitInsets();
		const width = bounds.maxX - bounds.minX;
		const height = bounds.maxY - bounds.minY;
		const worldCenterX = (bounds.minX + bounds.maxX) / 2;
		const targetScreenX = getFitTargetScreenX(insets);
		const screenOffsetX = targetScreenX - state.canvasWidth / 2;
		state.camera.y = (bounds.minY + bounds.maxY) / 2;
		state.camera.zoom = (state.canvasHeight - margin * 2) / height;
		state.camera.zoom = clamp(state.camera.zoom, state.camera.minZoom, state.camera.maxZoom);
		state.camera.x = worldCenterX - screenOffsetX / state.camera.zoom;
	}

	function isVisible(point, margin) {
		return point.x >= -margin && point.x <= state.canvasWidth + margin && point.y >= -margin && point.y <= state.canvasHeight + margin;
	}

	function currentHeatLevel(systemId) {
		const heat = state.heat.get(systemId);
		return heat ? heat.count : 0;
	}

	function rebuildHeat() {
		state.heat.clear();
		for (const kill of state.feed) {
			const systemId = asNumber(kill.solar_system_id);
			if (!systemId || !state.systemById.has(systemId)) continue;
			const heat = state.heat.get(systemId) || { count: 0 };
			heat.count = Math.min(heat.count + 2, 12);
			state.heat.set(systemId, heat);
		}
	}

	function pruneExpiredKills() {
		const cutoff = Date.now() - KILL_TTL_MS;
		const nextFeed = state.feed.filter((kill) => asNumber(kill.occurredAt) >= cutoff);
		if (nextFeed.length === state.feed.length) return false;
		state.feed = nextFeed;
		rebuildHeat();
		return true;
	}

	function getVisibleSystems() {
		const visibleSystems = [];
		for (const system of state.systems) {
			const point = toScreen({ x: system.worldX, y: system.worldY });
			if (!isVisible(point, 30)) continue;
			visibleSystems.push({ system, point });
		}
		return visibleSystems;
	}

	function getRegionLabelsForView() {
		const minimumDistance = clamp(140 + (1 / Math.max(state.camera.zoom, 0.12)) * 18, 140, 260);
		const visibleLabels = state.regionLabels
			.map((region) => ({ ...region, point: toScreen(region) }))
			.filter((region) => isVisible(region.point, 140))
			.sort((left, right) => right.count - left.count);

		const selected = [];
		for (const region of visibleLabels) {
			const tooClose = selected.some((picked) => {
				const dx = picked.point.x - region.point.x;
				const dy = picked.point.y - region.point.y;
				return Math.sqrt(dx * dx + dy * dy) < minimumDistance;
			});
			if (tooClose) continue;
			selected.push(region);
			if (selected.length >= 10) break;
		}
		return selected;
	}

	function drawConnections() {
		for (const connection of state.connections) {
			const from = toScreen({ x: connection.from.worldX, y: connection.from.worldY });
			const to = toScreen({ x: connection.to.worldX, y: connection.to.worldY });
			if (!isVisible(from, 40) && !isVisible(to, 40)) continue;

			const gradient = context.createLinearGradient(from.x, from.y, to.x, to.y);
			gradient.addColorStop(0, rgba(connection.from.color, 0.22));
			gradient.addColorStop(1, rgba(connection.to.color, 0.22));

			context.strokeStyle = gradient;
			context.lineWidth = clamp(0.6 + state.camera.zoom * 0.5, 0.6, 1.8);
			context.beginPath();
			context.moveTo(from.x, from.y);
			context.lineTo(to.x, to.y);
			context.stroke();
		}
	}

	function drawRegionLabels() {
		context.save();
		context.textAlign = 'center';
		context.textBaseline = 'middle';
		context.font = `${clamp(16 + state.camera.zoom * 5, 16, 28)}px Trebuchet MS, sans-serif`;
		for (const region of getRegionLabelsForView()) {
			const point = region.point;
			context.fillStyle = 'rgba(198, 218, 246, 0.18)';
			context.fillText(region.name.toUpperCase(), point.x, point.y);
		}
		context.restore();
	}

	function drawSystems() {
		const baseRadius = clamp(1.4 + state.camera.zoom * 0.8, 1.4, 4.5);
		const visibleSystems = getVisibleSystems();
		const allowSystemLabels = visibleSystems.length <= 30;
		const labeledSystems = allowSystemLabels ? visibleSystems.slice(0, 30) : [];
		const labeledSystemIds = new Set(labeledSystems.map((entry) => entry.system.id));
		const normalSystems = [];
		const hotSystems = [];

		for (const entry of visibleSystems) {
			if (currentHeatLevel(entry.system.id) > 0) hotSystems.push(entry);
			else normalSystems.push(entry);
		}

		const drawSystemNode = ({ system, point }) => {
			const heatLevel = currentHeatLevel(system.id);
			const glowRadius = heatLevel > 0
				? baseRadius * (5.2 + heatLevel * 0.22)
				: baseRadius * 2.2;
			const glow = context.createRadialGradient(point.x, point.y, 0, point.x, point.y, glowRadius);
			glow.addColorStop(0, heatLevel > 0 ? `rgba(255, 94, 94, ${clamp(0.36 + heatLevel * 0.04, 0.36, 0.8)})` : rgba(system.color, 0.2));
			if (heatLevel > 0) glow.addColorStop(0.45, `rgba(255, 168, 76, ${clamp(0.22 + heatLevel * 0.03, 0.22, 0.5)})`);
			glow.addColorStop(1, rgba(system.color, 0));
			context.save();
			if (heatLevel > 0) context.globalCompositeOperation = 'screen';
			context.fillStyle = glow;
			context.beginPath();
			context.arc(point.x, point.y, glowRadius, 0, Math.PI * 2);
			context.fill();
			context.restore();

			context.fillStyle = system.color;
			context.beginPath();
			context.arc(point.x, point.y, baseRadius + Math.min(heatLevel * 0.28, 2.4), 0, Math.PI * 2);
			context.fill();

			context.fillStyle = '#f8fcff';
			context.beginPath();
			context.arc(point.x, point.y, clamp(baseRadius * 0.42, 0.7, 1.8), 0, Math.PI * 2);
			context.fill();

			if (heatLevel > 0) {
				context.strokeStyle = `rgba(255, 108, 108, ${clamp(0.45 + heatLevel * 0.04, 0.45, 0.9)})`;
				context.lineWidth = 1.6;
				context.beginPath();
				context.arc(point.x, point.y, baseRadius * 2.8 + heatLevel * 0.7, 0, Math.PI * 2);
				context.stroke();

				context.strokeStyle = `rgba(255, 196, 116, ${clamp(0.2 + heatLevel * 0.03, 0.2, 0.55)})`;
				context.lineWidth = 1;
				context.beginPath();
				context.arc(point.x, point.y, baseRadius * 4 + heatLevel * 0.95, 0, Math.PI * 2);
				context.stroke();
			}

			if (labeledSystemIds.has(system.id) || heatLevel >= 4) {
				context.font = `${heatLevel > 0 ? 13 : 11}px Trebuchet MS, sans-serif`;
				context.textAlign = 'left';
				context.textBaseline = 'middle';
				context.lineWidth = 3;
				context.strokeStyle = 'rgba(4, 7, 10, 0.92)';
				context.fillStyle = heatLevel > 0 ? '#ffe5cf' : 'rgba(226, 237, 248, 0.78)';
				context.strokeText(system.name, point.x + baseRadius + 5, point.y - baseRadius - 2);
				context.fillText(system.name, point.x + baseRadius + 5, point.y - baseRadius - 2);
			}
		};

		for (const entry of normalSystems) drawSystemNode(entry);
		for (const entry of hotSystems) drawSystemNode(entry);
	}

	function drawHover() {
		if (!state.hoveredSystem) return;
		const point = toScreen({ x: state.hoveredSystem.worldX, y: state.hoveredSystem.worldY });
		context.strokeStyle = 'rgba(255, 255, 255, 0.95)';
		context.lineWidth = 1.4;
		context.beginPath();
		context.arc(point.x, point.y, clamp(6 + state.camera.zoom * 4, 6, 16), 0, Math.PI * 2);
		context.stroke();
	}

	function draw() {
		context.clearRect(0, 0, state.canvasWidth, state.canvasHeight);
		context.save();
		drawConnections();
		drawRegionLabels();
		drawSystems();
		drawHover();
		context.restore();
	}

	function updateHud(system) {
		hudSystem.textContent = system?.name || '-';
		hudConstellation.textContent = system?.constellationName || '-';
		hudRegion.textContent = system?.regionName || '-';
	}

	function showHint(screenX, screenY, system) {
		const heatLevel = currentHeatLevel(system.id);
		hint.innerHTML = `
			<div class="hint-name">${system.name}</div>
			<div>${system.regionName || '-'}</div>
			<div>${system.constellationName || '-'}</div>
			<div class="hint-muted">Security ${asNumber(system.securityStatus).toFixed(1)} | Gates ${(system.linkedSystemIDs || []).length} | Live heat ${heatLevel}</div>
		`;
		hint.style.display = 'block';
		hint.style.left = `${Math.min(screenX + 18, window.innerWidth - 270)}px`;
		hint.style.top = `${Math.min(screenY + 18, window.innerHeight - 120)}px`;
		updateHud(system);
	}

	function hideHint() {
		hint.style.display = 'none';
		updateHud(state.hoveredSystem);
	}

	function updateStats() {
		systemCount.textContent = String(state.systems.length);
		liveCount.textContent = String(Array.from(state.heat.values()).filter((entry) => entry.count > 0).length);
		refreshAt.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'UTC', hour12: false }) + ' UTC';
		mapSequence.textContent = state.sequence > 0 ? String(state.sequence) : '-';
	}

	function renderFeed() {
		pruneExpiredKills();
		liveFeed.replaceChildren();
		for (const kill of state.feed.slice(0, 11)) {
			const item = document.createElement('li');
			const imageUrl = getKillImageUrl(kill.victim_ship_type_id);
			item.innerHTML = `
				<div class="kill-row">
					<div class="kill-image"><img src="${imageUrl}" alt="" loading="lazy" decoding="async" fetchpriority="low"></div>
					<div class="kill-copy">
						<div class="kill-time">${formatTime(kill.killmail_time || kill.uploaded_at)}</div>
						<div class="kill-system">${kill.systemName || 'Unknown system'}</div>
						<div class="kill-meta">${kill.regionName ? `${kill.regionName} | ` : ''}${formatIsk(kill.total_value)} ISK | ${kill.attacker_count} attackers</div>
					</div>
				</div>
			`;
			liveFeed.appendChild(item);
		}
	}

	function pruneLiveState() {
		if (!pruneExpiredKills()) return;
		renderFeed();
		updateStats();
		requestDraw();
	}

	function addKill(kill) {
		if (!kill || state.seenSequences.has(kill.sequence_id)) return;
		state.seenSequences.add(kill.sequence_id);
		if (state.seenSequences.size > 2000) {
			const oldest = state.feed[state.feed.length - 1]?.sequence_id;
			if (oldest) state.seenSequences.delete(oldest);
		}

		const system = state.systemById.get(kill.solar_system_id);
		state.feed.unshift({
			...kill,
			occurredAt: Date.now(),
			systemName: system?.name || `System ${kill.solar_system_id}`,
			regionName: system?.regionName || ''
		});
		pruneExpiredKills();
		state.feed = state.feed.slice(0, 80);
		rebuildHeat();
	}

	function normalizeKillmail(doc) {
		const esi = doc?.esi || {};
		const zkb = doc?.zkb || {};
		return {
			sequence_id: asNumber(doc?.sequence_id),
			killmail_id: asNumber(doc?.killmail_id || esi?.killmail_id),
			uploaded_at: asNumber(doc?.uploaded_at),
			killmail_time: esi?.killmail_time || null,
			solar_system_id: asNumber(esi?.solar_system_id),
			victim_ship_type_id: asNumber(esi?.victim?.ship_type_id),
			attacker_count: asNumber(zkb?.attackerCount),
			total_value: asNumber(zkb?.totalValue),
			points: asNumber(zkb?.points),
			labels: Array.isArray(zkb?.labels) ? zkb.labels : Object.values(zkb?.labels || {})
		};
	}

	async function fetchJson(url) {
		const response = await fetch(url, { mode: 'cors', credentials: 'omit' });
		if (!response.ok) throw new Error(`HTTP ${response.status}`);
		return response.json();
	}

	async function pollKills(forceInitial) {
		window.clearTimeout(state.pollTimer);

		try {
			if (state.sequence == 0) {
				const sequenceDoc = await fetchJson(`${config.r2z2BaseUrl}/sequence.json`);
				state.sequence = asNumber(sequenceDoc?.sequence);
				console.log('Starting R2Z2 poll at sequence', state.sequence);
			}
			const url = `${config.r2z2BaseUrl}/${state.sequence}.json`;
			const data = await fetchJson(url);
			addKill(normalizeKillmail(data));
			state.retryAfterMs = 99;
			state.sequence++;

			renderFeed();
			updateStats();
			requestDraw();
		} catch (error) {
			console.error('Failed to poll R2Z2', error);
			state.retryAfterMs = 6666;
		} finally {
			state.pollTimer = window.setTimeout(() => pollKills(false), state.retryAfterMs);
		}
	}

	function findHoveredSystem(clientX, clientY) {
		let best = null;
		let bestDistance = Infinity;
		for (const system of state.systems) {
			const point = toScreen({ x: system.worldX, y: system.worldY });
			const dx = point.x - clientX;
			const dy = point.y - clientY;
			const distance = Math.sqrt(dx * dx + dy * dy);
			if (distance < bestDistance && distance <= 12) {
				best = system;
				bestDistance = distance;
			}
		}
		return best;
	}

	function bindCanvasInteractions() {
		canvas.addEventListener('pointerdown', (event) => {
			canvas.setPointerCapture(event.pointerId);
			state.pointer = {
				id: event.pointerId,
				startX: event.clientX,
				startY: event.clientY,
				cameraX: state.camera.x,
				cameraY: state.camera.y,
				dragging: false
			};
		});

		canvas.addEventListener('pointermove', (event) => {
			if (state.pointer && state.pointer.id === event.pointerId) {
				const dx = event.clientX - state.pointer.startX;
				const dy = event.clientY - state.pointer.startY;
				if (Math.abs(dx) > 2 || Math.abs(dy) > 2) state.pointer.dragging = true;
				if (state.pointer.dragging) {
					state.camera.x = state.pointer.cameraX - dx / state.camera.zoom;
					state.camera.y = state.pointer.cameraY - dy / state.camera.zoom;
					requestDraw();
					return;
				}
			}

			const hovered = findHoveredSystem(event.clientX, event.clientY);
			state.hoveredSystem = hovered;
			if (hovered) showHint(event.clientX, event.clientY, hovered);
			else hideHint();
			requestDraw();
		});

		const releasePointer = () => {
			state.pointer = null;
		};

		canvas.addEventListener('pointerup', releasePointer);
		canvas.addEventListener('pointercancel', releasePointer);
		canvas.addEventListener('pointerleave', () => {
			state.hoveredSystem = null;
			hideHint();
			requestDraw();
		});

		canvas.addEventListener('wheel', (event) => {
			event.preventDefault();
			const factor = event.deltaY < 0 ? 1.12 : 0.88;
			const worldBefore = screenToWorld(event.clientX, event.clientY);
			state.camera.zoom = clamp(state.camera.zoom * factor, state.camera.minZoom, state.camera.maxZoom);
			const worldAfter = screenToWorld(event.clientX, event.clientY);
			state.camera.x += worldBefore.x - worldAfter.x;
			state.camera.y += worldBefore.y - worldAfter.y;
			requestDraw();
		}, { passive: false });
	}

	function bindControls() {
		controls.addEventListener('click', (event) => {
			const button = event.target.closest('button[data-action]');
			if (!button) return;
			const action = button.dataset.action;
			if (action === 'fit') {
				fitCamera();
				requestDraw();
			}
			if (action === 'reset') {
				state.heat.clear();
				state.feed = [];
				renderFeed();
				updateStats();
				requestDraw();
			}
			if (action === 'refresh') pollKills(false);
		});
	}

	async function loadJsonl(url) {
		const response = await fetch(url, { credentials: 'same-origin' });
		return parseJsonl(await response.text());
	}

	async function init() {
		bindControls();
		bindCanvasInteractions();
		window.addEventListener('resize', resizeCanvas);
		resizeCanvas();

		try {
			const [systems, regions, constellations] = await Promise.all([
				loadJsonl(config.systemsUrl),
				loadJsonl(config.regionsUrl),
				loadJsonl(config.constellationsUrl)
			]);
			computeWorldLayout(systems, constellations, regions);
			fitCamera();
			updateStats();
			requestDraw();
			loading.remove();
			pollKills(true);
			window.setInterval(pruneLiveState, 15000);
		} catch (error) {
			console.error(error);
			loading.querySelector('span').textContent = 'Failed to load map data';
		}
	}

	init();
});
