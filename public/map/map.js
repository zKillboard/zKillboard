document.addEventListener('DOMContentLoaded', () => {
	const config = {
		apiUrl: '',
		websocketUrl: '/websocket/',
		systemsUrl: '/data/mapSystems.jsonl',
		systems2dUrl: '/data/mapSystems2d.jsonl',
		regionsUrl: '/data/mapRegions.jsonl',
		constellationsUrl: '/data/mapConstellations.jsonl',
		...(window.mapPageConfig || {})
	};
	const canvas = document.getElementById('mapCanvas');
	const context = canvas.getContext('2d', { alpha: true });
	const loading = document.getElementById('mapLoading');
	let hint = document.getElementById('mapHint');
	const recenterButton = document.getElementById('recenterButton');
	const projectionToggle = document.getElementById('projectionToggle');
	const regionLabelToggle = document.getElementById('regionLabelToggle');
	const ambientVolumeSlider = document.getElementById('ambientVolumeSlider');
	const regionActivity = document.getElementById('regionActivity');
	const regionActivityBody = document.getElementById('regionActivityBody');
	const systemActivity = document.getElementById('systemActivity');
	const systemActivityBody = document.getElementById('systemActivityBody');
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
	const KILL_TTL_MS = 60 * 60 * 1000;
	const LABEL_KILL_WINDOW_MS = 60 * 60 * 1000;
	const LABEL_KILL_THRESHOLD = 10;
	const MAX_FEED_KILLS = 500;
	const MAX_ACTIVE_REGIONS = 5;
	const MAX_ACTIVE_SYSTEMS = 5;
	const MODE_TRANSITION_MS = 1000;
	const DRAG_START_PX = 10;
	const SYSTEM_HOVER_RADIUS_PX = 12;
	const SYSTEM_CLICK_RADIUS_PX = 20;
	const CANVAS_FONT_FAMILY = '"Noto Sans", "Segoe UI", sans-serif';

	function canvasFont(sizePx, weight = 700) {
		return `italic ${weight} ${Math.round(sizePx)}px ${CANVAS_FONT_FAMILY}`;
	}

	const state = {
		systems: [],
		systemById: new Map(),
		systemIdByName: new Map(),
		connections: [],
		regionLabels: [],
		constellationLabels: [],
		feed: [],
		heat: new Map(),
		recentKillCounts: new Map(),
		bootstrapRecentKillCounts: new Map(),
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
		worldBounds3d: null,
		worldBounds2d: null,
		sequence: 0,
		showRegionLabels: false,
		projectionMode: '2d',
		modeTransition: null,
		cameraTransition: null,
		ws: null,
		wsReconnectMs: 1000,
		wsReconnectTimer: null,
		pendingKillIds: new Set(),
		animationFrame: null,
		lastVisibleSystems: []
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

	function lerp(start, end, t) {
		return start + (end - start) * t;
	}

	function easeInOut(t) {
		return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
	}

	function getModeBlend() {
		const transition = state.modeTransition;
		if (!transition) return state.projectionMode === '2d' ? 1 : 0;

		const elapsed = performance.now() - transition.start;
		const raw = clamp(elapsed / transition.duration, 0, 1);
		const eased = easeInOut(raw);
		const blend = lerp(transition.fromBlend, transition.toBlend, eased);
		if (raw >= 1) {
			state.modeTransition = null;
			state.projectionMode = transition.toMode;
			return transition.toBlend;
		}

		requestDraw();
		return blend;
	}

	function getSystemWorldPosition(system) {
		const blend = getModeBlend();
		return {
			x: lerp(asNumber(system.worldX3d), asNumber(system.worldX2d), blend),
			y: lerp(asNumber(system.worldY3d), asNumber(system.worldY2d), blend)
		};
	}

	function getRegionWorldPosition(region) {
		const blend = getModeBlend();
		return {
			x: lerp(asNumber(region.x3d), asNumber(region.x2d), blend),
			y: lerp(asNumber(region.y3d), asNumber(region.y2d), blend)
		};
	}

	function getWorldBoundsForBlend(blend) {
		if (!state.worldBounds3d || !state.worldBounds2d) return state.worldBounds;
		return {
			minX: lerp(state.worldBounds3d.minX, state.worldBounds2d.minX, blend),
			maxX: lerp(state.worldBounds3d.maxX, state.worldBounds2d.maxX, blend),
			minY: lerp(state.worldBounds3d.minY, state.worldBounds2d.minY, blend),
			maxY: lerp(state.worldBounds3d.maxY, state.worldBounds2d.maxY, blend)
		};
	}

	function getCurrentWorldBounds() {
		return getWorldBoundsForBlend(getModeBlend());
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
		state.dpr = Math.min(window.devicePixelRatio || 1, 2);
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

	function getFitTargetScreenX(insets, blendOverride) {
		if (window.innerWidth <= 1100) {
			return state.canvasWidth / 2;
		}

		const leftBound = insets.left;
		const rightBound = state.canvasWidth - insets.right;
		const blend = blendOverride == null ? getModeBlend() : blendOverride;
		const target = state.canvasWidth * (blend ? 0.50 : 0.55);
		return clamp(target, leftBound + 120, rightBound - 120);
	}

	function computeFitCameraState(blend) {
		const margin = 48;
		const bounds = getWorldBoundsForBlend(blend);
		if (!bounds) return null;
		const insets = getFitInsets();
		const width = bounds.maxX - bounds.minX;
		const height = bounds.maxY - bounds.minY;
		const worldCenterX = (bounds.minX + bounds.maxX) / 2;
		const targetScreenX = getFitTargetScreenX(insets, blend);
		const screenOffsetX = targetScreenX - state.canvasWidth / 2;
		const zoom = clamp((state.canvasHeight - margin * 2) / height, state.camera.minZoom, state.camera.maxZoom);
		return {
			x: worldCenterX - screenOffsetX / zoom,
			y: (bounds.minY + bounds.maxY) / 2,
			zoom
		};
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

	function buildWorldCoordinates(systems, getRawX, getRawY) {
		let minX = Number.POSITIVE_INFINITY;
		let minY = Number.POSITIVE_INFINITY;
		let maxX = Number.NEGATIVE_INFINITY;
		let maxY = Number.NEGATIVE_INFINITY;

		for (const system of systems) {
			const x = getRawX(system);
			const y = getRawY(system);
			minX = Math.min(minX, x);
			minY = Math.min(minY, y);
			maxX = Math.max(maxX, x);
			maxY = Math.max(maxY, y);
		}

		const spanX = maxX - minX || 1;
		const spanY = maxY - minY || 1;
		const worldWidth = 4200;
		const worldHeight = worldWidth * (spanY / spanX || 1);
		const pointById = new Map();

		for (const system of systems) {
			const x = ((getRawX(system) - minX) / spanX - 0.5) * worldWidth;
			const y = (0.5 - (getRawY(system) - minY) / spanY) * worldHeight;
			pointById.set(system.id, { x, y });
		}

		return {
			pointById,
			bounds: {
				minX: -worldWidth / 2,
				maxX: worldWidth / 2,
				minY: -worldHeight / 2,
				maxY: worldHeight / 2
			}
		};
	}

	function computeWorldLayout(systems3d, systems2d, constellations, regions) {
		const regionById = new Map(regions.map((region) => [region.id, region.regionName]));
		const constellationById = new Map(constellations.map((constellation) => [constellation.id, constellation]));
		const visibleSystems3d = systems3d.filter((system) => {
			const constellation = constellationById.get(system.constellationID);
			return isDisplayRegion(constellation?.regionID);
		});
		const systems2dById = new Map(systems2d.map((system) => [system.id, system]));

		const layout3d = buildWorldCoordinates(
			visibleSystems3d,
			(system) => asNumber(system.position?.x),
			(system) => asNumber(system.position?.z)
		);

		const layout2d = buildWorldCoordinates(
			visibleSystems3d,
			(system) => {
				const system2d = systems2dById.get(system.id);
				return asNumber(system2d?.position?.x ?? system.position?.x);
			},
			(system) => {
				const system2d = systems2dById.get(system.id);
				return asNumber(system2d?.position?.z ?? system.position?.z);
			}
		);

		state.systems = visibleSystems3d.map((system) => {
			const constellation = constellationById.get(system.constellationID);
			const point3d = layout3d.pointById.get(system.id) || { x: 0, y: 0 };
			const point2d = layout2d.pointById.get(system.id) || point3d;
			return {
				...system,
				regionID: asNumber(constellation?.regionID),
				regionName: regionById.get(constellation?.regionID) ?? '',
				constellationName: constellation?.constellationName ?? '',
				worldX3d: point3d.x,
				worldY3d: point3d.y,
				worldX2d: point2d.x,
				worldY2d: point2d.y,
				color: securityColor(system.securityStatus)
			};
		});

		state.systemById = new Map(state.systems.map((system) => [system.id, system]));
		state.systemIdByName = new Map(state.systems.map((system) => [String(system.name || '').trim().toLowerCase(), system.id]));
		state.worldBounds3d = layout3d.bounds;
		state.worldBounds2d = layout2d.bounds;
		state.worldBounds = layout3d.bounds;

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
			const regionEntry = regionAccumulator.get(system.regionName) || { x3d: 0, y3d: 0, x2d: 0, y2d: 0, count: 0 };
			regionEntry.x3d += system.worldX3d;
			regionEntry.y3d += system.worldY3d;
			regionEntry.x2d += system.worldX2d;
			regionEntry.y2d += system.worldY2d;
			regionEntry.count += 1;
			regionAccumulator.set(system.regionName, regionEntry);
		}

		state.regionLabels = Array.from(regionAccumulator.entries()).map(([name, entry]) => ({
			name,
			count: entry.count,
			x3d: entry.x3d / entry.count,
			y3d: entry.y3d / entry.count,
			x2d: entry.x2d / entry.count,
			y2d: entry.y2d / entry.count
		}));

		state.constellationLabels = [];
	}

	function fitCamera() {
		const cameraState = computeFitCameraState(getModeBlend());
		if (!cameraState) return;
		state.camera.x = cameraState.x;
		state.camera.y = cameraState.y;
		state.camera.zoom = cameraState.zoom;
	}

	function updateCameraTransition() {
		const transition = state.cameraTransition;
		if (!transition) return;
		const elapsed = performance.now() - transition.start;
		const raw = clamp(elapsed / transition.duration, 0, 1);
		const eased = easeInOut(raw);
		state.camera.x = lerp(transition.from.x, transition.to.x, eased);
		state.camera.y = lerp(transition.from.y, transition.to.y, eased);
		state.camera.zoom = lerp(transition.from.zoom, transition.to.zoom, eased);
		if (raw >= 1) {
			state.cameraTransition = null;
		}
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
		state.recentKillCounts.clear();
		const recentCutoff = Date.now() - LABEL_KILL_WINDOW_MS;

		for (const [systemId, count] of state.bootstrapRecentKillCounts.entries()) {
			state.recentKillCounts.set(systemId, count);
		}

		for (const kill of state.feed) {
			const systemId = asNumber(kill.solar_system_id);
			if (!systemId || !state.systemById.has(systemId)) continue;

			if (getKillTimestamp(kill) >= recentCutoff) {
				state.recentKillCounts.set(systemId, (state.recentKillCounts.get(systemId) || 0) + 1);
			}

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

	function buildFrameProjection(blend) {
		const visibleSystems = [];
		const projectedBySystemId = new Map();

		for (const system of state.systems) {
			const worldX = lerp(system.worldX3d, system.worldX2d, blend);
			const worldY = lerp(system.worldY3d, system.worldY2d, blend);
			const screenX = (worldX - state.camera.x) * state.camera.zoom + state.canvasWidth / 2;
			const screenY = (worldY - state.camera.y) * state.camera.zoom + state.canvasHeight / 2;
			const entry = {
				system,
				x: screenX,
				y: screenY,
				heatLevel: currentHeatLevel(system.id)
			};
			projectedBySystemId.set(system.id, entry);
			if (!isVisible({ x: screenX, y: screenY }, 30)) continue;
			visibleSystems.push(entry);
		}

		return {
			blend,
			visibleSystems,
			projectedBySystemId
		};
	}

	function getRegionLabelsForView(blend) {
		return state.regionLabels
			.map((region) => ({
				...region,
				point: toScreen({
					x: lerp(region.x3d, region.x2d, blend),
					y: lerp(region.y3d, region.y2d, blend)
				})
			}))
			.filter((region) => isVisible(region.point, 140))
			.sort((left, right) => right.count - left.count);
	}

	function drawConnections(frame) {
		const useSimpleLines = state.camera.zoom < 0.55 || frame.visibleSystems.length > 380;
		context.lineWidth = clamp(0.6 + state.camera.zoom * 0.5, 0.6, 1.8);

		if (useSimpleLines) {
			context.strokeStyle = 'rgba(170, 200, 240, 0.18)';
			context.beginPath();
			for (const connection of state.connections) {
				const from = frame.projectedBySystemId.get(connection.from.id);
				const to = frame.projectedBySystemId.get(connection.to.id);
				if (!from || !to) continue;
				if (!isVisible({ x: from.x, y: from.y }, 40) && !isVisible({ x: to.x, y: to.y }, 40)) continue;
				context.moveTo(from.x, from.y);
				context.lineTo(to.x, to.y);
			}
			context.stroke();
			return;
		}

		for (const connection of state.connections) {
			const from = frame.projectedBySystemId.get(connection.from.id);
			const to = frame.projectedBySystemId.get(connection.to.id);
			if (!from || !to) continue;
			if (!isVisible({ x: from.x, y: from.y }, 40) && !isVisible({ x: to.x, y: to.y }, 40)) continue;

			const gradient = context.createLinearGradient(from.x, from.y, to.x, to.y);
			gradient.addColorStop(0, rgba(connection.from.color, 0.22));
			gradient.addColorStop(1, rgba(connection.to.color, 0.22));

			context.strokeStyle = gradient;
			context.beginPath();
			context.moveTo(from.x, from.y);
			context.lineTo(to.x, to.y);
			context.stroke();
		}
	}

	function drawRegionLabels(blend) {
		if (!state.showRegionLabels) return;
		context.save();
		context.textAlign = 'center';
		context.textBaseline = 'middle';
		context.font = canvasFont(clamp(16 + state.camera.zoom * 5, 16, 28), 700);
		context.lineWidth = 3.2;
		context.strokeStyle = 'rgba(0, 0, 0, 0.94)';
		for (const region of getRegionLabelsForView(blend)) {
			const point = region.point;
			context.fillStyle = 'rgba(255, 255, 255, 0.85)';
			context.strokeText(region.name, point.x, point.y);
			context.fillText(region.name, point.x, point.y);
		}
		context.restore();
	}

	function drawSystems(frame) {
		const baseRadius = clamp(1.4 + state.camera.zoom * 0.8, 1.4, 4.5);
		const visibleSystems = frame.visibleSystems;
		const lowDetail = visibleSystems.length > 500 || state.camera.zoom < 0.45;
		const normalSystems = [];
		const hotSystems = [];
		const forcedLabels = [];

		for (const entry of visibleSystems) {
			if (entry.heatLevel > 0) hotSystems.push(entry);
			else normalSystems.push(entry);
		}

		const drawSystemNode = ({ system, x, y, heatLevel }) => {
			const recentKillCount = state.recentKillCounts.get(system.id) || 0;
			const shouldShowLabel = recentKillCount >= LABEL_KILL_THRESHOLD;
			if (lowDetail) {
				context.fillStyle = heatLevel > 0 ? '#ff866e' : system.color;
				context.beginPath();
				context.arc(x, y, baseRadius + Math.min(heatLevel * 0.2, 1.6), 0, Math.PI * 2);
				context.fill();

				if (shouldShowLabel) {
					forcedLabels.push({ system, x, y, heatLevel });
				}

				return;
			}

			const glowRadius = heatLevel > 0
				? baseRadius * (5.2 + heatLevel * 0.22)
				: baseRadius * 2.2;
			const glow = context.createRadialGradient(x, y, 0, x, y, glowRadius);
			glow.addColorStop(0, heatLevel > 0 ? `rgba(255, 94, 94, ${clamp(0.36 + heatLevel * 0.04, 0.36, 0.8)})` : rgba(system.color, 0.2));
			if (heatLevel > 0) glow.addColorStop(0.45, `rgba(255, 168, 76, ${clamp(0.22 + heatLevel * 0.03, 0.22, 0.5)})`);
			glow.addColorStop(1, rgba(system.color, 0));
			context.save();
			if (heatLevel > 0) context.globalCompositeOperation = 'screen';
			context.fillStyle = glow;
			context.beginPath();
			context.arc(x, y, glowRadius, 0, Math.PI * 2);
			context.fill();
			context.restore();

			context.fillStyle = system.color;
			context.beginPath();
			context.arc(x, y, baseRadius + Math.min(heatLevel * 0.28, 2.4), 0, Math.PI * 2);
			context.fill();

			context.fillStyle = '#f8fcff';
			context.beginPath();
			context.arc(x, y, clamp(baseRadius * 0.42, 0.7, 1.8), 0, Math.PI * 2);
			context.fill();

			if (heatLevel > 0) {
				context.strokeStyle = `rgba(255, 108, 108, ${clamp(0.45 + heatLevel * 0.04, 0.45, 0.9)})`;
				context.lineWidth = 1.6;
				context.beginPath();
				context.arc(x, y, baseRadius * 2.8 + heatLevel * 0.7, 0, Math.PI * 2);
				context.stroke();

				context.strokeStyle = `rgba(255, 196, 116, ${clamp(0.2 + heatLevel * 0.03, 0.2, 0.55)})`;
				context.lineWidth = 1;
				context.beginPath();
				context.arc(x, y, baseRadius * 4 + heatLevel * 0.95, 0, Math.PI * 2);
				context.stroke();
			}

			if (shouldShowLabel) {
				context.font = canvasFont(heatLevel > 0 ? 13 : 11, 700);
				context.textAlign = 'left';
				context.textBaseline = 'middle';
				context.lineWidth = 3;
				context.strokeStyle = 'rgba(0, 0, 0, 0.94)';
				context.fillStyle = heatLevel > 0 ? '#ffe5cf' : 'rgba(226, 237, 248, 0.78)';
				context.strokeText(system.name, x + baseRadius + 5, y - baseRadius - 2);
				context.fillText(system.name, x + baseRadius + 5, y - baseRadius - 2);
			}
		};

		for (const entry of normalSystems) drawSystemNode(entry);
		for (const entry of hotSystems) drawSystemNode(entry);

		if (forcedLabels.length > 0) {
			context.font = canvasFont(13, 700);
			context.textAlign = 'left';
			context.textBaseline = 'middle';
			context.lineWidth = 3.2;
			context.strokeStyle = 'rgba(0, 0, 0, 0.94)';
			for (const label of forcedLabels) {
				context.fillStyle = label.heatLevel > 0 ? '#ffd9b3' : '#eef6ff';
				context.strokeText(label.system.name, label.x + baseRadius + 4, label.y - baseRadius - 2);
				context.fillText(label.system.name, label.x + baseRadius + 4, label.y - baseRadius - 2);
			}
		}
	}

	function drawHover(frame) {
		if (!state.hoveredSystem) return;
		const point = frame.projectedBySystemId.get(state.hoveredSystem.id);
		if (!point) return;
		context.strokeStyle = 'rgba(255, 255, 255, 0.95)';
		context.lineWidth = 1.4;
		context.beginPath();
		context.arc(point.x, point.y, clamp(6 + state.camera.zoom * 4, 6, 16), 0, Math.PI * 2);
		context.stroke();
	}

	function draw() {
		updateCameraTransition();
		const blend = getModeBlend();
		const frame = buildFrameProjection(blend);
		state.lastVisibleSystems = frame.visibleSystems;
		context.clearRect(0, 0, state.canvasWidth, state.canvasHeight);
		context.save();
		drawConnections(frame);
		drawSystems(frame);
		drawRegionLabels(blend);
		drawHover(frame);
		context.restore();
	}

	function updateHud(system) {
		if (!hudSystem || !hudConstellation || !hudRegion) return;
		hudSystem.textContent = system?.name || '-';
		hudConstellation.textContent = system?.constellationName || '-';
		hudRegion.textContent = system?.regionName || '-';
	}

	function ensureHintElement() {
		if (hint) return;
		hint = document.createElement('div');
		hint.id = 'mapHint';
		hint.style.position = 'fixed';
		hint.style.display = 'none';
		hint.style.zIndex = '7';
		hint.style.pointerEvents = 'none';
		hint.style.minWidth = '180px';
		hint.style.maxWidth = '260px';
		hint.style.padding = '10px 12px';
		hint.style.borderRadius = '12px';
		hint.style.background = 'rgba(8, 12, 18, 0.88)';
		hint.style.border = '1px solid rgba(126, 179, 255, 0.24)';
		hint.style.color = '#eff6ff';
		hint.style.font = 'italic 700 12px/1.35 "Noto Sans", "Segoe UI", sans-serif';
		hint.style.backdropFilter = 'blur(8px)';
		hint.style.boxShadow = '0 10px 26px rgba(0, 0, 0, 0.42)';
		document.body.appendChild(hint);
	}

	function getSystemKillCount(systemId) {
		if (!systemId) return 0;
		let count = 0;
		for (const kill of state.feed) {
			if (asNumber(kill.solar_system_id) === systemId) count += 1;
		}
		return count;
	}

	function showHint(screenX, screenY, system) {
		if (!hint) return;
		const killCount = getSystemKillCount(system.id);
		hint.innerHTML = `
			<div class="hint-name">${system.name}</div>
			<div>${system.regionName || '-'}</div>
			<div class="hint-muted">Kills: ${killCount}</div>
		`;
		hint.style.display = 'block';
		hint.style.left = `${Math.min(screenX + 18, window.innerWidth - 270)}px`;
		hint.style.top = `${Math.min(screenY + 18, window.innerHeight - 120)}px`;
		updateHud(system);
	}

	function hideHint() {
		if (!hint) return;
		hint.style.display = 'none';
		updateHud(state.hoveredSystem);
	}

	function updateStats() {
		if (!systemCount || !liveCount || !refreshAt || !mapSequence) return;
		systemCount.textContent = String(state.systems.length);
		liveCount.textContent = String(Array.from(state.heat.values()).filter((entry) => entry.count > 0).length);
		refreshAt.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'UTC', hour12: false }) + ' UTC';
		mapSequence.textContent = state.sequence > 0 ? String(state.sequence) : '-';
	}

	function getFeedRenderCount() {
		if (!liveFeed) return 0;
		const availableHeight = liveFeed.clientHeight || liveKillsPanel?.clientHeight || window.innerHeight;
		const estimatedRowHeight = 58;
		return clamp(Math.floor(availableHeight / estimatedRowHeight), 1, MAX_FEED_KILLS);
	}

	function renderFeed() {
		pruneExpiredKills();
		if (!liveFeed) return;
		const renderCount = getFeedRenderCount();
		liveFeed.replaceChildren();
		for (const kill of state.feed.slice(0, renderCount)) {
			const item = document.createElement('li');
			const imageUrl = getKillImageUrl(kill.victim_ship_type_id);
			const killId = asNumber(kill.killmail_id || kill.sequence_id);
			const killUrl = killId > 0 ? `https://zkillboard.com/kill/${killId}/` : '#';
			item.innerHTML = `
				<a href="${killUrl}" target="_blank" rel="noopener noreferrer">
					<div class="kill-row">
						<div class="kill-image"><img src="${imageUrl}" alt="" loading="lazy" decoding="async" fetchpriority="low"></div>
						<div class="kill-copy">
							<div class="kill-time">${formatTime(kill.killmail_time || kill.uploaded_at)}</div>
							<div class="kill-system">${kill.systemName || 'Unknown system'}</div>
							<div class="kill-meta">${kill.regionName ? `${kill.regionName} | ` : ''}${formatIsk(kill.total_value)} ISK | ${kill.attacker_count} attackers</div>
						</div>
					</div>
				</a>
			`;
			liveFeed.appendChild(item);
		}
		updateRegionActivity();
		updateSystemActivity();
	}

	function updateRegionActivity() {
		if (!regionActivity || !regionActivityBody) return;

		const counts = new Map();
		for (const kill of state.feed) {
			const region = String(kill.regionName || kill.region_name || '').trim();
			if (!region) continue;
			counts.set(region, (counts.get(region) || 0) + 1);
		}

		const topRegions = Array.from(counts.entries())
			.sort((left, right) => {
				if (right[1] !== left[1]) return right[1] - left[1];
				return left[0].localeCompare(right[0]);
			})
			.slice(0, MAX_ACTIVE_REGIONS);

		if (topRegions.length === 0) {
			regionActivity.hidden = true;
			regionActivityBody.replaceChildren();
			return;
		}

		regionActivity.hidden = false;
		regionActivityBody.replaceChildren();
		for (const [region, count] of topRegions) {
			const row = document.createElement('tr');
			row.dataset.regionName = region;
			row.title = `Focus region: ${region}`;
			const nameCell = document.createElement('td');
			const countCell = document.createElement('td');
			nameCell.textContent = region;
			countCell.textContent = String(count);
			row.appendChild(nameCell);
			row.appendChild(countCell);
			regionActivityBody.appendChild(row);
		}
	}

	function updateSystemActivity() {
		if (!systemActivity || !systemActivityBody) return;

		const counts = new Map();
		for (const kill of state.feed) {
			const system = String(kill.systemName || kill.system_name || '').trim();
			if (!system) continue;
			counts.set(system, (counts.get(system) || 0) + 1);
		}

		const topSystems = Array.from(counts.entries())
			.sort((left, right) => {
				if (right[1] !== left[1]) return right[1] - left[1];
				return left[0].localeCompare(right[0]);
			})
			.slice(0, MAX_ACTIVE_SYSTEMS);

		if (topSystems.length === 0) {
			systemActivity.hidden = true;
			systemActivityBody.replaceChildren();
			return;
		}

		systemActivity.hidden = false;
		systemActivityBody.replaceChildren();
		for (const [system, count] of topSystems) {
			const row = document.createElement('tr');
			row.dataset.systemName = system;
			row.title = `Focus system: ${system}`;
			const nameCell = document.createElement('td');
			const countCell = document.createElement('td');
			nameCell.textContent = system;
			countCell.textContent = String(count);
			row.appendChild(nameCell);
			row.appendChild(countCell);
			systemActivityBody.appendChild(row);
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
		const parsedSystemName = String(kill.system_name || kill.systemName || '').trim();
		const parsedRegionName = String(kill.region_name || kill.regionName || '').trim();
		const fallbackSystemName = kill.solar_system_id > 0 ? `System ${kill.solar_system_id}` : 'Unknown system';
		state.feed.unshift({
			...kill,
			occurredAt: Date.now(),
			systemName: parsedSystemName || system?.name || fallbackSystemName,
			regionName: parsedRegionName || system?.regionName || ''
		});
		pruneExpiredKills();
		state.feed = state.feed.slice(0, MAX_FEED_KILLS);
		rebuildHeat();

		if (typeof window.playAmbientKillmailNote === 'function') {
			window.playAmbientKillmailNote(kill);
		}
	}

	function bindAmbientVolume() {
		if (!ambientVolumeSlider) return;

		const applyVolume = (value) => {
			if (typeof window.setAmbientKillmailVolume !== 'function') return;
			window.setAmbientKillmailVolume(value);
		};

		applyVolume(Number(ambientVolumeSlider.value || 0));
		ambientVolumeSlider.addEventListener('input', () => {
			applyVolume(Number(ambientVolumeSlider.value || 0));
		});
	}

	function getWebSocketUrl() {
		const configured = String(config.websocketUrl || '/websocket/').trim();
		if (configured.startsWith('ws://') || configured.startsWith('wss://')) return configured;
		if (configured.startsWith('http://')) return `ws://${configured.slice('http://'.length)}`;
		if (configured.startsWith('https://')) return `wss://${configured.slice('https://'.length)}`;
		const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
		const path = configured.startsWith('/') ? configured : `/${configured}`;
		return `${protocol}//${window.location.host}${path}`;
	}

	function parseAttackerCount(row) {
		const finalBlowCell = row?.querySelector('td.finalBlow');
		if (!finalBlowCell) return 0;
		const text = finalBlowCell.textContent || '';
		if (text.includes('1000+')) return 1000;
		if (text.includes('100+')) return 100;
		const match = text.match(/\((\d+)\)/);
		return match ? asNumber(match[1]) : 0;
	}

	function parseFinalBlowLabels(row) {
		const finalBlowCell = row?.querySelector('td.finalBlow');
		const text = String(finalBlowCell?.textContent || '').toUpperCase();
		if (!text) return '';

		const labels = [];
		if (text.includes('SOLO')) labels.push('SOLO');
		if (text.includes('GANKED')) labels.push('GANKED');
		if (text.includes('NPC')) labels.push('NPC');
		if (text.includes('PADDING') || text.includes('PAD')) labels.push('PADDING');
		return labels.join(' ');
	}

	function parseShipTypeId(row) {
		const img = row?.querySelector('.shipImageSpan img');
		const src = img?.getAttribute('src') || '';
		const match = src.match(/\/(\d+)\/(?:render|icon)(?:\?|$)/);
		return match ? asNumber(match[1]) : 0;
	}

	function parseSystemId(row) {
		const systemLink = row?.querySelector('td.location a[href^="/system/"]');
		const href = systemLink?.getAttribute('href') || '';
		const fromHref = asNumber(href.split('/')[2]);
		if (fromHref > 0) return fromHref;
		const systemName = String(systemLink?.textContent || '').trim().toLowerCase();
		return state.systemIdByName.get(systemName) || 0;
	}

	function parseSystemName(row) {
		const systemLink = row?.querySelector('td.location a[href^="/system/"]');
		return String(systemLink?.textContent || '').trim();
	}

	function parseSecurityStatus(row) {
		const locationCell = row?.querySelector('td.location');
		if (!locationCell) return null;

		const firstSpan = locationCell.querySelector('span');
		const text = String(firstSpan?.textContent || '').trim();
		if (!text || /^[A-Za-z]/.test(text)) return null;

		const numeric = Number.parseFloat(text);
		if (!Number.isFinite(numeric)) return null;
		return clamp(numeric, -1, 1);
	}

	function parseRegionName(row) {
		const regionLink = row?.querySelector('td.location a[href^="/region/"]');
		return String(regionLink?.textContent || '').trim();
	}

	function parseKillmailRow(html, fallbackKillId) {
		const parser = new DOMParser();
		const doc = parser.parseFromString(`<table><tbody>${html}</tbody></table>`, 'text/html');
		const row = doc.querySelector('tr.kltbd');
		if (!row) return null;

		const killId = asNumber(row.getAttribute('killID')) || asNumber(fallbackKillId);
		const unix = asNumber(row.getAttribute('date'));
		const valueRaw = row.querySelector('[format="format-isk-once"]')?.getAttribute('raw');
		const systemId = parseSystemId(row);
		const parsedSecurity = parseSecurityStatus(row);
		const system = state.systemById.get(systemId);
		const securityStatus = parsedSecurity != null
			? parsedSecurity
			: (Number.isFinite(system?.securityStatus) ? system.securityStatus : null);
		return {
			sequence_id: killId,
			killmail_id: killId,
			uploaded_at: unix,
			killmail_time: unix > 0 ? new Date(unix * 1000).toISOString() : null,
			solar_system_id: systemId,
			system_name: parseSystemName(row),
			region_name: parseRegionName(row),
			victim_ship_type_id: parseShipTypeId(row),
			attacker_count: parseAttackerCount(row),
			total_value: asNumber(valueRaw),
			labels: parseFinalBlowLabels(row),
			security_status: securityStatus
		};
	}

	async function fetchKillmailRow(killId) {
		const response = await fetch(`/cache/24hour/killlistrow/${killId}/`, { credentials: 'same-origin' });
		if (!response.ok) throw new Error(`HTTP ${response.status}`);
		const html = await response.text();
		return parseKillmailRow(html, killId);
	}

	async function ingestLittleKill(killId) {
		if (!killId || state.seenSequences.has(killId) || state.pendingKillIds.has(killId)) return;
		state.pendingKillIds.add(killId);
		try {
			const kill = await fetchKillmailRow(killId);
			if (!kill) return;
			addKill(kill);
			state.sequence = Math.max(state.sequence, killId);
			renderFeed();
			updateStats();
			requestDraw();
		} catch (error) {
			console.error(`Failed to load killmail ${killId}`, error);
		} finally {
			state.pendingKillIds.delete(killId);
		}
	}

	async function loadBootstrapRecentKillCounts() {
		try {
			const response = await fetch('/cache/1hour/killlist/', { credentials: 'same-origin' });
			if (!response.ok) return;
			const html = await response.text();
			const parser = new DOMParser();
			const doc = parser.parseFromString(html, 'text/html');
			const counts = new Map();

			for (const row of doc.querySelectorAll('tr.kltbd')) {
				const systemId = parseSystemId(row);
				if (!systemId || !state.systemById.has(systemId)) continue;
				counts.set(systemId, (counts.get(systemId) || 0) + 1);
			}

			state.bootstrapRecentKillCounts = counts;
			rebuildHeat();
			updateStats();
			requestDraw();
		} catch (error) {
			console.warn('Unable to load 1-hour killlist bootstrap for map labels', error);
		}
	}

	function scheduleReconnect() {
		if (state.wsReconnectTimer) return;
		const delay = state.wsReconnectMs;
		state.wsReconnectMs = Math.min(Math.floor(state.wsReconnectMs * 1.8), 30000);
		state.wsReconnectTimer = window.setTimeout(() => {
			state.wsReconnectTimer = null;
			connectKillStream();
		}, delay);
	}

	function handleSocketMessage(raw) {
		if (raw === 'ping' || raw === 'pong') return;
		let message;
		try {
			message = JSON.parse(raw);
		} catch {
			return;
		}
		if (message?.action !== 'littlekill') return;
		ingestLittleKill(asNumber(message.killID || message.kill_id));
	}

	function connectKillStream() {
		if (state.ws && (state.ws.readyState === WebSocket.CONNECTING || state.ws.readyState === WebSocket.OPEN)) return;
		const socket = new WebSocket(getWebSocketUrl());
		state.ws = socket;

		socket.addEventListener('open', () => {
			state.wsReconnectMs = 1000;
			socket.send(JSON.stringify({ action: 'sub', channel: 'public' }));
			socket.send(JSON.stringify({ action: 'sub', channel: 'all:*' }));
			console.log('Connected to site websocket stream');
		});

		socket.addEventListener('message', (event) => {
			handleSocketMessage(event.data);
		});

		socket.addEventListener('error', () => {
			scheduleReconnect();
		});

		socket.addEventListener('close', () => {
			if (state.ws === socket) state.ws = null;
			scheduleReconnect();
		});
	}

	function findHoveredSystem(clientX, clientY, maxDistance = SYSTEM_HOVER_RADIUS_PX) {
		const maxDistanceSquared = maxDistance * maxDistance;
		let best = null;
		let bestDistanceSquared = Number.POSITIVE_INFINITY;
		for (const entry of state.lastVisibleSystems) {
			const dx = entry.x - clientX;
			const dy = entry.y - clientY;
			const distanceSquared = dx * dx + dy * dy;
			if (distanceSquared < bestDistanceSquared && distanceSquared <= maxDistanceSquared) {
				best = entry.system;
				bestDistanceSquared = distanceSquared;
			}
		}
		return best;
	}

	function applyCameraTarget(centerX, centerY, zoom) {
		const insets = getFitInsets();
		const targetScreenX = getFitTargetScreenX(insets);
		const screenOffsetX = targetScreenX - state.canvasWidth / 2;
		state.camera.zoom = clamp(zoom, state.camera.minZoom, state.camera.maxZoom);
		state.camera.x = centerX - screenOffsetX / state.camera.zoom;
		state.camera.y = centerY;
		requestDraw();
	}

	function focusSystem(system) {
		if (!system) return false;
		const targetZoom = Math.max(state.camera.zoom, 2.8);
		const point = getSystemWorldPosition(system);
		applyCameraTarget(point.x, point.y, targetZoom);
		return true;
	}

	function findRegionLabelAtPoint(clientX, clientY) {
		if (!state.showRegionLabels) return null;
		const labels = getRegionLabelsForView(getModeBlend());
		if (labels.length === 0) return null;

		const fontSize = clamp(16 + state.camera.zoom * 5, 16, 28);
		context.save();
		context.font = canvasFont(fontSize, 700);
		for (const region of labels) {
			const text = region.name.toUpperCase();
			const width = context.measureText(text).width;
			const halfW = width / 2 + 10;
			const halfH = fontSize / 2 + 7;
			if (Math.abs(clientX - region.point.x) <= halfW && Math.abs(clientY - region.point.y) <= halfH) {
				context.restore();
				return region;
			}
		}
		context.restore();
		return null;
	}

	function focusRegion(regionName) {
		const systems = state.systems.filter((system) => system.regionName === regionName);
		if (systems.length === 0) return false;

		if (systems.length === 1) {
			return focusSystem(systems[0]);
		}

		let minX = Number.POSITIVE_INFINITY;
		let minY = Number.POSITIVE_INFINITY;
		let maxX = Number.NEGATIVE_INFINITY;
		let maxY = Number.NEGATIVE_INFINITY;
		for (const system of systems) {
			const point = getSystemWorldPosition(system);
			minX = Math.min(minX, point.x);
			minY = Math.min(minY, point.y);
			maxX = Math.max(maxX, point.x);
			maxY = Math.max(maxY, point.y);
		}

		const width = Math.max(120, maxX - minX);
		const height = Math.max(120, maxY - minY);
		const margin = 100;
		const zoomX = (state.canvasWidth - margin * 2) / width;
		const zoomY = (state.canvasHeight - margin * 2) / height;
		const targetZoom = Math.min(zoomX, zoomY);
		applyCameraTarget((minX + maxX) / 2, (minY + maxY) / 2, targetZoom);
		return true;
	}

	function handleMapClick(clientX, clientY) {
		const system = findHoveredSystem(clientX, clientY, SYSTEM_CLICK_RADIUS_PX);
		if (system) {
			focusSystem(system);
			return;
		}

		const region = findRegionLabelAtPoint(clientX, clientY);
		if (region) {
			focusRegion(region.name);
		}
	}

	function bindCanvasInteractions() {
		canvas.addEventListener('pointerdown', (event) => {
			canvas.setPointerCapture(event.pointerId);
			state.pointer = {
				id: event.pointerId,
				startX: event.clientX,
				startY: event.clientY,
				lastX: event.clientX,
				lastY: event.clientY,
				cameraX: state.camera.x,
				cameraY: state.camera.y,
				dragging: false
			};
		});

		canvas.addEventListener('pointermove', (event) => {
			if (state.pointer && state.pointer.id === event.pointerId) {
				const dx = event.clientX - state.pointer.startX;
				const dy = event.clientY - state.pointer.startY;
				state.pointer.lastX = event.clientX;
				state.pointer.lastY = event.clientY;
				if (Math.abs(dx) > DRAG_START_PX || Math.abs(dy) > DRAG_START_PX) state.pointer.dragging = true;
				if (state.pointer.dragging) {
					state.camera.x = state.pointer.cameraX - dx / state.camera.zoom;
					state.camera.y = state.pointer.cameraY - dy / state.camera.zoom;
					requestDraw();
					return;
				}
			}

			const previousHovered = state.hoveredSystem;
			const hovered = findHoveredSystem(event.clientX, event.clientY);
			state.hoveredSystem = hovered;
			if (hovered) showHint(event.clientX, event.clientY, hovered);
			else if (previousHovered) hideHint();
			if (hovered !== previousHovered) requestDraw();
		});

		const releasePointer = (event) => {
			if (!state.pointer || state.pointer.id !== event.pointerId) {
				state.pointer = null;
				return;
			}
			const dx = event.clientX - state.pointer.startX;
			const dy = event.clientY - state.pointer.startY;
			const movedBeyondClick = Math.hypot(dx, dy) > DRAG_START_PX;
			const wasDragging = state.pointer.dragging || movedBeyondClick;
			state.pointer = null;
			if (!wasDragging) handleMapClick(event.clientX, event.clientY);
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

		canvas.addEventListener('dblclick', (event) => {
			event.preventDefault();
			state.pointer = null;
			fitCamera();
			requestDraw();
		});
	}

	function bindControls() {
		if (!controls) return;
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
			if (action === 'refresh') {
				if (state.ws) state.ws.close();
				state.ws = null;
				connectKillStream();
			}
		});
	}

	function bindRecenterButton() {
		if (!recenterButton) return;
		recenterButton.addEventListener('click', () => {
			fitCamera();
			requestDraw();
		});
	}

	function bindActivityFocus() {
		if (regionActivityBody) {
			regionActivityBody.addEventListener('click', (event) => {
				const row = event.target.closest('tr[data-region-name]');
				if (!row) return;
				const regionName = String(row.dataset.regionName || '').trim();
				if (!regionName) return;
				focusRegion(regionName);
			});
		}

		if (systemActivityBody) {
			systemActivityBody.addEventListener('click', (event) => {
				const row = event.target.closest('tr[data-system-name]');
				if (!row) return;
				const systemName = String(row.dataset.systemName || '').trim().toLowerCase();
				if (!systemName) return;
				const systemId = state.systemIdByName.get(systemName);
				const system = state.systemById.get(systemId);
				if (!system) return;
				focusSystem(system);
			});
		}
	}

	function updateProjectionToggleUi() {
		if (!projectionToggle) return;
		projectionToggle.dataset.mode = state.projectionMode;
		projectionToggle.textContent = state.projectionMode === '2d' ? '2D' : '3D';
		projectionToggle.setAttribute('aria-pressed', state.projectionMode === '2d' ? 'true' : 'false');
	}

	function setProjectionMode(nextMode) {
		if (nextMode !== '2d' && nextMode !== '3d') return;
		const currentBlend = getModeBlend();
		const targetBlend = nextMode === '2d' ? 1 : 0;
		if (Math.abs(currentBlend - targetBlend) < 0.001) {
			state.modeTransition = null;
			state.projectionMode = nextMode;
			state.cameraTransition = null;
			fitCamera();
			updateProjectionToggleUi();
			requestDraw();
			return;
		}

		const cameraTarget = computeFitCameraState(targetBlend);
		if (cameraTarget) {
			state.cameraTransition = {
				start: performance.now(),
				duration: MODE_TRANSITION_MS,
				from: {
					x: state.camera.x,
					y: state.camera.y,
					zoom: state.camera.zoom
				},
				to: cameraTarget
			};
		}

		state.modeTransition = {
			start: performance.now(),
			duration: MODE_TRANSITION_MS,
			fromBlend: currentBlend,
			toBlend: targetBlend,
			toMode: nextMode
		};
		state.projectionMode = nextMode;
		updateProjectionToggleUi();
		requestDraw();
	}

	function bindProjectionToggle() {
		if (!projectionToggle) return;
		updateProjectionToggleUi();
		projectionToggle.addEventListener('click', () => {
			const nextMode = state.projectionMode === '3d' ? '2d' : '3d';
			setProjectionMode(nextMode);
		});
	}

	function updateRegionLabelToggleUi() {
		if (!regionLabelToggle) return;
		regionLabelToggle.textContent = 'RG';
		regionLabelToggle.title = state.showRegionLabels ? 'Region labels: on' : 'Region labels: off';
		regionLabelToggle.setAttribute('aria-pressed', state.showRegionLabels ? 'true' : 'false');
	}

	function bindRegionLabelToggle() {
		if (!regionLabelToggle) return;
		updateRegionLabelToggleUi();
		regionLabelToggle.addEventListener('click', () => {
			state.showRegionLabels = !state.showRegionLabels;
			updateRegionLabelToggleUi();
			requestDraw();
		});
	}

	async function loadJsonl(url) {
		const response = await fetch(url, { credentials: 'same-origin' });
		return parseJsonl(await response.text());
	}

	async function init() {
		ensureHintElement();
		bindControls();
		bindRecenterButton();
		bindActivityFocus();
		bindProjectionToggle();
		bindRegionLabelToggle();
		bindAmbientVolume();
		bindCanvasInteractions();
		window.addEventListener('resize', () => {
			resizeCanvas();
			renderFeed();
		});
		resizeCanvas();

		try {
			const [systems3d, systems2d, regions, constellations] = await Promise.all([
				loadJsonl(config.systemsUrl),
				loadJsonl(config.systems2dUrl || config.systemsUrl),
				loadJsonl(config.regionsUrl),
				loadJsonl(config.constellationsUrl)
			]);
			computeWorldLayout(systems3d, systems2d, constellations, regions);
			fitCamera();
			loadBootstrapRecentKillCounts();
			updateStats();
			requestDraw();
			loading.remove();
			connectKillStream();
			window.setInterval(pruneLiveState, 15000);
		} catch (error) {
			console.error(error);
			loading.querySelector('span').textContent = 'Failed to load map data';
		}
	}

	init();
});
