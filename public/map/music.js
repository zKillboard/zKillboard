document.addEventListener('DOMContentLoaded', () => {
	// EVE soundtrack playlist: https://ashy.vargur.dev/jukebox.html
	const tracks = [
		["Miner Stories", "Miner%20Stories.mp3"],
		["Hidden Mementos", "Hidden%20Mementos.mp3"],
		["War Relics", "War%20Relics.mp3"],
		["Red Glowing Dust", "Red%20Glowing%20Dust.mp3"],
		["Costs of Conflict", "Costs%20of%20Conflict.mp3"],
		["Jovian Riddle", "Jovian%20Riddle.mp3"],
		["Lost Wormhole", "Lost%20Wormhole.mp3"],
		["Below the Asteroids", "Below%20the%20Asteroids.mp3"],
		["Forsaken Ruins", "Forsaken%20Ruins.mp3"],
		["Surplus of Rare Artifacts", "Surplus%20of%20Rare%20Artifacts.mp3"],
		["The Green Nebula", "The%20Green%20Nebula.mp3"],
		["Primordial Star Clouds", "Primordial%20Star%20Clouds.mp3"],
		["The Jovian Front", "The%20Jovian%20Front.mp3"],
		["My Other Residency", "My%20Other%20Residency.mp3"],
		["Merchants, Looters, and Ghosts", "Merchants,%20Looters,%20and%20Ghosts.mp3"],
		["Safe Trade Routes", "Safe%20Trade%20Routes.mp3"],
		["Last Power Cell", "Last%20Power%20Cell.mp3"],
		["EVE of Battle", "EVE%20of%20Battle.mp3"],
		["Nouvelle Rouvenor Hero", "Nouvelle%20Rouvenor%20Hero.mp3"],
		["Inter-Spatial-Flexure", "Inter-Spatial-Flexure.mp3"],
		["Infinite Distortion of Space and Time", "Infinite%20Distortion%20of%20Space%20and%20Time.mp3"],
		["Tomorrows Neverending Yesterday", "Tomorrows%20Neverending%20Yesterday.mp3"],
		["Gallentean Refuge", "Gallentean%20Refuge.mp3"],
		["Love, Honour and Obey", "Love,%20Honour%20and%20Obey.mp3"],
		["Everyday Zone", "Everyday%20Zone.mp3"],
		["Object Almost Accomplished", "Object%20Almost%20Accomplished.mp3"],
		["Ride Out the Storm", "Ride%20Out%20the%20Storm.mp3"],
		["At the End of the Your Journey", "At%20the%20End%20of%20the%20Your%20Journey.mp3"],
		["Do You Know Where You Are?", "Do%20You%20Know%20Where%20You%20Are_.mp3"],
		["Home at Last", "Home%20at%20Last.mp3"],
		["Miners Heaven", "Miners%20Heaven.mp3"],
		["Quaesitum Finished", "Quaesitum%20Finished.mp3"],
		["My Own Binary System", "My%20Own%20Binary%20System.mp3"],
		["Something Old, Something New", "Something%20Old,%20Something%20New.mp3"],
		["I Saw Your Ship", "I%20Saw%20Your%20Ship.mp3"],
		["We Fight Proud for the Holder", "We%20Fight%20Proud%20for%20the%20Holder.mp3"],
		["The Hunt", "The%20Hunt.mp3"],
		["Amongst Allies", "Amongst%20Allies.mp3"],
		["Theme of the Universe", "Theme%20of%20the%20Universe.mp3"],
		["Defenders of the Orphic World", "Defenders%20of%20the%20Orphic%20World.mp3"],
		["Treasure the Obscure", "Treasure%20the%20Obscure.mp3"],
		["Rose of Victory", "Rose%20of%20Victory.mp3"],
		["It's Full of Stars", "It's%20Full%20of%20Stars.mp3"],
		["Smoke From Down Below", "Smoke%20From%20Down%20Below.mp3"],
		["...But Still We Go On", "But%20Still%20We%20Go%20On.mp3"],
		["In the Depth of Space", "In%20the%20Depth%20of%20Space.mp3"],
		["Minmatar Rebel Alliance", "Minmatar%20Rebel%20Alliance.mp3"],
		["All Which Was Lost Has Now Been Regained", "All%20Which%20Was%20Lost%20Has%20Now%20Been%20Regained.mp3"],
		["Borderlines", "Borderlines.mp3"],
		["Wonderlands of the Mind", "Wonderlands%20of%20the%20Mind.mp3"],
		["Omens", "Omens.mp3"],
		["The Solitary Pilot", "The%20Solitary%20Pilot.mp3"],
		["Times of Sanguinity", "Times%20of%20Sanguinity.mp3"],
		["Doomed Forever", "Doomed%20Forever.mp3"],
		["Path of the Cursed", "Path%20of%20the%20Cursed.mp3"],
		["On the Outskirts", "On%20the%20Outskirts.mp3"],
		["Forgotten Places", "Forgotten%20Places.mp3"],
		["Precious Ore", "Precious%20Ore.mp3"],
		["It Ends Here", "It%20Ends%20Here.mp3"],
		["Shifting the Balance of Power", "Shifting%20the%20Balance%20of%20Power.mp3"],
		["Where Evil Lurks", "Where%20Evil%20Lurks.mp3"],
		["Point of No Return", "Point%20of%20No%20Return.mp3"],
		["Theme From Jita", "Theme%20From%20Jita.mp3"],
		["New Moon", "New%20Moon.mp3"],
		["Seek and You Shall Find", "Seek%20and%20You%20Shall%20Find.mp3"],
		["Learning From the Past", "Learning%20From%20the%20Past.mp3"],
		["Close to a Holy Place", "Close%20to%20a%20Holy%20Place.mp3"]
	];
	const slider = document.getElementById('ambientVolumeSlider');
	const control = slider.closest('.map-ambient-volume');
	const panel = document.getElementById('mapJukebox');
	const toggle = document.getElementById('jukeboxToggle');
	const playButton = document.getElementById('jukeboxPlay');
	const shuffleButton = document.getElementById('jukeboxShuffle');
	const volumeSlider = document.getElementById('jukeboxVolume');
	const seek = document.getElementById('jukeboxSeek');
	const search = document.getElementById('jukeboxSearch');
	const list = document.getElementById('jukeboxTracks');
	const nowPlaying = document.getElementById('jukeboxNowPlaying');
	const status = document.getElementById('jukeboxStatus');
	const elapsed = document.getElementById('jukeboxElapsed');
	const duration = document.getElementById('jukeboxDuration');
	const audio = new Audio();
	audio.preload = 'none';
	audio.volume = 0;
	audio.preservesPitch = true;
	let remaining = tracks.map((track, index) => index);
	let current = -1;
	let history = [];
	let shuffle = true;
	let userPaused = false;
	let lastVolume = 0.35;
	let failures = 0;
	let activity = 0;
	let activityAt = Date.now();
	let intensity = 0;
	let updatedAt = Date.now();

	function setPanelOpen(open) {
		panel.hidden = !open;
		toggle.setAttribute('aria-expanded', String(open));
		toggle.setAttribute('aria-label', open ? 'Close jukebox' : 'Open jukebox');
		toggle.title = open ? 'Close jukebox' : 'Open jukebox';
		(open ? search : toggle).focus();
	}

	function updatePlayback() {
		playButton.textContent = audio.paused ? '▶' : '⏸';
		playButton.setAttribute('aria-label', audio.paused ? 'Play' : 'Pause');
		status.textContent = failures >= 3 ? 'Music is unavailable. Press play to retry.' : audio.volume === 0 ? 'Muted' : audio.paused ? 'Paused' : 'Playing';
		control.title = current < 0 ? 'EVE soundtrack · open jukebox or raise volume to start' : `${tracks[current][0]} · EVE soundtrack · slide to 0 to mute`;
	}

	function formatTrackTime(seconds) {
		const value = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0;
		return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`;
	}

	function updateProgress() {
		seek.disabled = !Number.isFinite(audio.duration) || audio.duration <= 0;
		seek.max = seek.disabled ? '0' : String(audio.duration);
		seek.value = String(audio.currentTime || 0);
		elapsed.textContent = formatTrackTime(audio.currentTime);
		duration.textContent = formatTrackTime(audio.duration);
	}

	function enablePlayback() {
		userPaused = false;
		failures = 0;
		if (audio.volume === 0) audio.volume = lastVolume;
		slider.value = volumeSlider.value = String(audio.volume);
	}

	function playTrack(index = current, remember = true) {
		if (audio.volume === 0 || userPaused) return;
		if (index < 0) index = tracks.findIndex((track) => track[0] === 'Below the Asteroids');
		if (index !== current || audio.error || audio.ended) {
			if (remember && current >= 0 && index !== current) {
				history.push(current);
				history = history.slice(-tracks.length);
			}
			current = index;
			remaining = remaining.filter((entry) => entry !== current);
			audio.src = `/map/music/${tracks[current][1]}`;
			nowPlaying.textContent = tracks[current][0];
			for (const button of list.children) {
				const selected = Number(button.dataset.track) === current;
				button.classList.toggle('active', selected);
				if (selected) button.setAttribute('aria-current', 'true');
				else button.removeAttribute('aria-current');
			}
			updateProgress();
		}
		const source = audio.src;
		audio.play().catch((error) => {
			if (source !== audio.src || audio.volume === 0 || error.name === 'AbortError') return;
			if (error.name === 'NotAllowedError') status.textContent = 'Press play to start playback';
		});
		updatePlayback();
	}

	function nextTrack() {
		if (current < 0) {
			playTrack();
		} else if (!shuffle) {
			playTrack((current + 1) % tracks.length);
		} else {
			if (remaining.length === 0) remaining = tracks.map((track, index) => index).filter((index) => index !== current);
			playTrack(remaining[Math.floor(Math.random() * remaining.length)]);
		}
	}

	toggle.addEventListener('click', () => setPanelOpen(panel.hidden));
	document.getElementById('jukeboxClose').addEventListener('click', () => setPanelOpen(false));
	panel.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.stopPropagation();
			setPanelOpen(false);
		}
	});
	playButton.addEventListener('click', () => {
		if (audio.paused) {
			enablePlayback();
			playTrack();
		} else {
			userPaused = true;
			audio.pause();
			updatePlayback();
		}
	});
	document.getElementById('jukeboxNext').addEventListener('click', () => {
		enablePlayback();
		nextTrack();
	});
	document.getElementById('jukeboxPrevious').addEventListener('click', () => {
		enablePlayback();
		if (current >= 0 && audio.currentTime > 3) {
			audio.currentTime = 0;
			playTrack();
		} else {
			playTrack(history.length ? history.pop() : current < 0 ? -1 : (current + tracks.length - 1) % tracks.length, false);
		}
	});
	shuffleButton.addEventListener('click', () => {
		shuffle = !shuffle;
		shuffleButton.setAttribute('aria-pressed', String(shuffle));
		shuffleButton.classList.toggle('active', shuffle);
	});
	seek.addEventListener('input', () => {
		if (!seek.disabled) {
			audio.currentTime = Math.max(0, Math.min(audio.duration, Number(seek.value)));
			updateProgress();
		}
	});
	tracks.forEach((track, index) => {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'list-group-item list-group-item-action py-2 small text-start';
		button.dataset.track = String(index);
		button.textContent = `${String(index + 1).padStart(2, '0')}  ${track[0]}`;
		button.addEventListener('click', () => {
			enablePlayback();
			playTrack(index);
		});
		list.appendChild(button);
	});
	search.addEventListener('input', () => {
		const query = search.value.trim().toLowerCase();
		let matches = 0;
		for (const button of list.children) {
			button.hidden = !tracks[Number(button.dataset.track)][0].toLowerCase().includes(query);
			if (!button.hidden) matches += 1;
		}
		document.getElementById('jukeboxEmpty').hidden = matches > 0;
	});

	audio.addEventListener('ended', nextTrack);
	audio.addEventListener('loadedmetadata', updateProgress);
	audio.addEventListener('durationchange', updateProgress);
	audio.addEventListener('pause', updatePlayback);
	audio.addEventListener('waiting', () => {
		if (audio.volume > 0 && !userPaused) status.textContent = 'Buffering…';
	});
	audio.addEventListener('playing', () => {
		failures = 0;
		updatePlayback();
	});
	audio.addEventListener('error', () => {
		if (audio.volume === 0 || userPaused) return;
		failures += 1;
		if (failures < 3) {
			nextTrack();
		} else {
			audio.volume = 0;
			audio.pause();
			slider.value = volumeSlider.value = '0';
			updatePlayback();
		}
	});
	audio.addEventListener('timeupdate', () => {
		updateProgress();
		const now = Date.now();
		const target = Math.min(1, activity * Math.exp(-(now - activityAt) / 30000) / 24);
		const delta = Math.max(0, Math.min(1, (now - updatedAt) / 1000));
		intensity += (target - intensity) * (1 - Math.exp(-delta / (target > intensity ? 4 : 18)));
		updatedAt = now;
		// Keep the recording's pitch while activity gently lifts its tempo.
		audio.playbackRate = 1 + intensity * 0.12;
	});

	window.playAmbientKillmailNote = () => {
		const now = Date.now();
		activity = Math.min(60, activity * Math.exp(-(now - activityAt) / 30000) + 1);
		activityAt = now;
	};
	window.setAmbientKillmailVolume = (value) => {
		const volume = Number(value);
		if (!Number.isFinite(volume)) return;
		audio.volume = Math.max(0, Math.min(1, volume));
		slider.value = volumeSlider.value = String(audio.volume);
		if (audio.volume === 0) {
			audio.pause();
		} else {
			lastVolume = audio.volume;
			failures = 0;
			if (!userPaused) {
				if (audio.ended || audio.error) nextTrack();
				else if (audio.paused) playTrack();
			}
		}
		updatePlayback();
	};
	volumeSlider.addEventListener('input', () => window.setAmbientKillmailVolume(volumeSlider.value));
});
