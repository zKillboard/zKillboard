const ambientKillmail = {
    mode: 'off',
    ctx: undefined,
    masterGain: undefined,
    colorLowpass: undefined,
    compressor: undefined,
    echoDelay: undefined,
    echoFeedback: undefined,
    echoWet: undefined,
    reverbConvolver: undefined,
    reverbGain: undefined,
    lastPlayedAt: 0,
    voices: [],
    progressionStep: 0,
    lastRootMidi: 45,
    transportTimer: undefined,
    nextBeatAt: 0,
    beatStep: 0,
    beatBar: 0,
    beatHoldUntil: 0,
    beatInstrument: undefined,
    beatDarkFactor: 0.45,
    activeEvents: [],
    rainSeed: 0,
    bedPhase: 0,
    volumeScalar: 1
};

function isAmbientMapPath() {
    return window.location.pathname.indexOf('/map') === 0;
}

function initAmbientKillmailMusic() {
    if (isAmbientMapPath()) {
        ambientKillmail.mode = 'loud';
        ambientKillmail.volumeScalar = 0;
        ensureAmbientAudioReady();
        applyAmbientOutputLevel();
        return;
    }

    if (window.location.pathname !== '/') return;

    ambientKillmail.mode = 'off';
    ambientKillmail.volumeScalar = 1;

    bindMostRecentKillsHeading();

    updateAmbientToggle();
}

function bindMostRecentKillsHeading() {
    const title = document.getElementById('mostRecentKillsTitle');
    if (!title) return;

    title.addEventListener('click', function() {
        showAmbientToggle();
    });
}

function ensureAmbientToggleElement() {
    let toggle = document.getElementById('ambientKillmailToggle');
    if (toggle) return toggle;

    const host = document.getElementById('ambientKillmailHost');
    if (!host) return undefined;

    toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.id = 'ambientKillmailToggle';
    toggle.className = 'ambient-killmail-toggle';
    toggle.setAttribute('aria-live', 'polite');
    toggle.addEventListener('click', function(event) {
        event.stopPropagation();
        ambientKillmail.mode = nextAmbientMode(ambientKillmail.mode);
        if (ambientKillmail.mode !== 'off') ensureAmbientAudioReady();
        applyAmbientOutputLevel();
        updateAmbientToggle();
        if (ambientKillmail.mode === 'off') hideAmbientToggle();
    });

    host.appendChild(toggle);
    return toggle;
}

function showAmbientToggle() {
    const host = document.getElementById('ambientKillmailHost');
    if (!host) return;
    ensureAmbientToggleElement();
    host.style.display = 'block';
    updateAmbientToggle();
}

function hideAmbientToggle() {
    const host = document.getElementById('ambientKillmailHost');
    if (!host) return;
    host.style.display = 'none';
}

function nextAmbientMode(current) {
    if (current === 'off') return 'low';
    if (current === 'low') return 'loud';
    return 'off';
}

function updateAmbientToggle() {
    const toggle = ensureAmbientToggleElement();
    if (!toggle) return;
    toggle.textContent = 'Live ambience: ' + ambientKillmail.mode;
    toggle.classList.toggle('is-on', ambientKillmail.mode !== 'off');
    toggle.classList.toggle('is-low', ambientKillmail.mode === 'low');
}

function getAmbientMasterGain() {
    if (ambientKillmail.mode === 'loud') return 0.26 * ambientKillmail.volumeScalar;
    if (ambientKillmail.mode === 'low') return 0.14 * ambientKillmail.volumeScalar;
    return 0 * ambientKillmail.volumeScalar;
}

function getAmbientAmplitudeScale() {
    if (ambientKillmail.mode === 'loud') return 1.35;
    if (ambientKillmail.mode === 'low') return 0.88;
    return 0;
}

function applyAmbientOutputLevel() {
    if (!ambientKillmail.masterGain || !ambientKillmail.ctx) return;
    const now = ambientKillmail.ctx.currentTime;
    ambientKillmail.masterGain.gain.cancelScheduledValues(now);
    ambientKillmail.masterGain.gain.setTargetAtTime(getAmbientMasterGain(), now, 0.05);

    if (ambientKillmail.mode === 'off') {
        stopAmbientTransport();
    } else {
        startAmbientTransport();
    }
}

function ensureAmbientAudioReady() {
    if (ambientKillmail.ctx) {
        if (ambientKillmail.ctx.state === 'suspended') ambientKillmail.ctx.resume();
        return;
    }
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) return;

    ambientKillmail.ctx = new Ctor();
    ambientKillmail.masterGain = ambientKillmail.ctx.createGain();
    ambientKillmail.masterGain.gain.value = getAmbientMasterGain();
    ambientKillmail.colorLowpass = ambientKillmail.ctx.createBiquadFilter();
    ambientKillmail.colorLowpass.type = 'lowpass';
    ambientKillmail.colorLowpass.frequency.value = 2350;
    ambientKillmail.colorLowpass.Q.value = 0.92;
    ambientKillmail.compressor = ambientKillmail.ctx.createDynamicsCompressor();
    ambientKillmail.compressor.threshold.value = -30;
    ambientKillmail.compressor.knee.value = 20;
    ambientKillmail.compressor.ratio.value = 3.1;
    ambientKillmail.compressor.attack.value = 0.02;
    ambientKillmail.compressor.release.value = 0.32;
    ambientKillmail.echoDelay = ambientKillmail.ctx.createDelay(1.0);
    ambientKillmail.echoDelay.delayTime.value = 0.26;
    ambientKillmail.echoFeedback = ambientKillmail.ctx.createGain();
    ambientKillmail.echoFeedback.gain.value = 0.48;
    ambientKillmail.echoWet = ambientKillmail.ctx.createGain();
    ambientKillmail.echoWet.gain.value = 0.24;
    ambientKillmail.reverbConvolver = ambientKillmail.ctx.createConvolver();
    ambientKillmail.reverbConvolver.buffer = createAmbientImpulse(ambientKillmail.ctx, 5.2, 2.7);
    ambientKillmail.reverbGain = ambientKillmail.ctx.createGain();
    ambientKillmail.reverbGain.gain.value = 0.31;

    ambientKillmail.masterGain.connect(ambientKillmail.colorLowpass);
    ambientKillmail.colorLowpass.connect(ambientKillmail.compressor);
    ambientKillmail.compressor.connect(ambientKillmail.ctx.destination);

    ambientKillmail.masterGain.connect(ambientKillmail.echoDelay);
    ambientKillmail.echoDelay.connect(ambientKillmail.echoFeedback);
    ambientKillmail.echoFeedback.connect(ambientKillmail.echoDelay);
    ambientKillmail.echoDelay.connect(ambientKillmail.echoWet);
    ambientKillmail.echoWet.connect(ambientKillmail.colorLowpass);

    ambientKillmail.masterGain.connect(ambientKillmail.reverbConvolver);
    ambientKillmail.reverbConvolver.connect(ambientKillmail.reverbGain);
    ambientKillmail.reverbGain.connect(ambientKillmail.colorLowpass);

    if (ambientKillmail.ctx.state === 'suspended') ambientKillmail.ctx.resume();
    startAmbientTransport();
}

function registerAmbientVoice(stopAt, nodes, oscs) {
    ambientKillmail.voices.push({
        stopAt: stopAt,
        nodes: nodes || [],
        oscs: oscs || []
    });
}

function startAmbientTransport() {
    if (!ambientKillmail.ctx || ambientKillmail.mode === 'off') return;
    if (ambientKillmail.transportTimer) return;

    const now = ambientKillmail.ctx.currentTime;
    ambientKillmail.nextBeatAt = Math.max(ambientKillmail.nextBeatAt || 0, now + 0.05);
    ambientKillmail.transportTimer = window.setInterval(function() {
        scheduleAmbientBeatWindow();
        cleanupAmbientVoices();
    }, 160);
}

function stopAmbientTransport() {
    if (ambientKillmail.transportTimer) {
        window.clearInterval(ambientKillmail.transportTimer);
        ambientKillmail.transportTimer = undefined;
    }
    if (ambientKillmail.ctx) {
        const now = ambientKillmail.ctx.currentTime;
        ambientKillmail.beatHoldUntil = now;
        ambientKillmail.nextBeatAt = now;
        ambientKillmail.beatStep = 0;
        ambientKillmail.beatBar = 0;
        ambientKillmail.activeEvents = [];
    } else {
        ambientKillmail.beatHoldUntil = 0;
        ambientKillmail.nextBeatAt = 0;
    }
}

function getAmbientBeatBpm(instrument) {
    const base = ambientKillmail.mode === 'loud' ? 112 : 102;
    if (!instrument) return base;
    if (instrument.name === 'drums') return base + 6;
    if (instrument.name === 'lead') return base + 2;
    if (instrument.name === 'pluck') return base;
    if (instrument.name === 'bass') return base - 4;
    if (instrument.name === 'drone') return base - 10;
    if (instrument.name === 'pad') return base - 8;
    return base;
}

function triggerAmbientKick(stepTime, velocity) {
    if (!ambientKillmail.ctx || !ambientKillmail.masterGain) return;
    const ctx = ambientKillmail.ctx;
    const osc = ctx.createOscillator();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(132, stepTime);
    osc.frequency.exponentialRampToValueAtTime(43, stepTime + 0.14);

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, stepTime);
    gain.gain.exponentialRampToValueAtTime(0.12 * velocity, stepTime + 0.008);
    gain.gain.exponentialRampToValueAtTime(0.0001, stepTime + 0.18);

    osc.connect(gain);
    gain.connect(ambientKillmail.masterGain);
    osc.start(stepTime);
    osc.stop(stepTime + 0.2);
    registerAmbientVoice(stepTime + 0.25, [gain], [osc]);
}

function triggerAmbientHat(stepTime, velocity, darkFactor) {
    if (!ambientKillmail.ctx || !ambientKillmail.masterGain) return;
    const ctx = ambientKillmail.ctx;
    const noise = ctx.createBufferSource();
    noise.buffer = createAmbientImpulse(ctx, 0.05, 1.2);

    const hp = ctx.createBiquadFilter();
    hp.type = 'highpass';
    hp.frequency.setValueAtTime(4600 + (darkFactor * 400), stepTime);

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, stepTime);
    gain.gain.exponentialRampToValueAtTime(0.03 * velocity, stepTime + 0.003);
    gain.gain.exponentialRampToValueAtTime(0.0001, stepTime + 0.045);

    noise.connect(hp);
    hp.connect(gain);
    gain.connect(ambientKillmail.masterGain);
    noise.start(stepTime);
    noise.stop(stepTime + 0.05);
    registerAmbientVoice(stepTime + 0.12, [hp, gain], [noise]);
}

function triggerAmbientSnare(stepTime, velocity, darkFactor) {
    if (!ambientKillmail.ctx || !ambientKillmail.masterGain) return;
    const ctx = ambientKillmail.ctx;
    const noise = ctx.createBufferSource();
    noise.buffer = createAmbientImpulse(ctx, 0.12, 1.5);

    const bp = ctx.createBiquadFilter();
    bp.type = 'bandpass';
    bp.frequency.setValueAtTime(1800 + (darkFactor * 260), stepTime);
    bp.Q.setValueAtTime(1.1, stepTime);

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, stepTime);
    gain.gain.exponentialRampToValueAtTime(0.055 * velocity, stepTime + 0.005);
    gain.gain.exponentialRampToValueAtTime(0.0001, stepTime + 0.1);

    noise.connect(bp);
    bp.connect(gain);
    gain.connect(ambientKillmail.masterGain);
    noise.start(stepTime);
    noise.stop(stepTime + 0.11);

    const tone = ctx.createOscillator();
    tone.type = 'triangle';
    tone.frequency.setValueAtTime(245, stepTime);
    tone.frequency.exponentialRampToValueAtTime(175, stepTime + 0.09);
    const toneGain = ctx.createGain();
    toneGain.gain.setValueAtTime(0.0001, stepTime);
    toneGain.gain.exponentialRampToValueAtTime(0.02 * velocity, stepTime + 0.006);
    toneGain.gain.exponentialRampToValueAtTime(0.0001, stepTime + 0.09);
    tone.connect(toneGain);
    toneGain.connect(ambientKillmail.masterGain);
    tone.start(stepTime);
    tone.stop(stepTime + 0.1);

    registerAmbientVoice(stepTime + 0.2, [bp, gain, toneGain], [noise, tone]);
}

function scheduleAmbientBeatWindow() {
    if (!ambientKillmail.ctx || ambientKillmail.mode === 'off') return;
    const ctx = ambientKillmail.ctx;
    const now = ctx.currentTime;
    const lookAhead = 0.45;
    const holdUntil = ambientKillmail.beatHoldUntil || 0;
    if (now > holdUntil + 0.4) return;

    ambientKillmail.activeEvents = ambientKillmail.activeEvents.filter(function(event) {
        return event.repeatUntil > now - 0.2;
    });

    const instrument = ambientKillmail.beatInstrument || {
        name: 'default',
        band: 'small',
        amp: 0.08
    };
    const bpm = getAmbientBeatBpm(instrument);
    const stepDur = (60 / bpm) / 4;
    if (!ambientKillmail.nextBeatAt || ambientKillmail.nextBeatAt < now) {
        ambientKillmail.nextBeatAt = now + 0.02;
    }

    const kickPattern = [1, 0, 0, 0, 0, 0, 1, 0, 1, 0, 0, 0, 0, 0, 1, 0];
    const snarePattern = [0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 1, 0, 1, 0];
    const hatPattern = [1, 0, 1, 0, 0, 1, 0, 1, 1, 0, 1, 0, 0, 1, 0, 1];

    while (ambientKillmail.nextBeatAt < now + lookAhead && ambientKillmail.nextBeatAt < holdUntil) {
        const step = ambientKillmail.beatStep % 16;
        const t = ambientKillmail.nextBeatAt;
        const baseVel = ambientKillmail.mode === 'loud' ? 1.42 : 1.0;
        const decayFactor = Math.max(0.75, Math.min(1.02, (holdUntil - t) / 46));
        const velocity = baseVel * decayFactor;
        const darkFactor = ambientKillmail.beatDarkFactor;

        if (kickPattern[step]) triggerAmbientKick(t, velocity);
        if (snarePattern[step]) triggerAmbientSnare(t, velocity, darkFactor);
        if (hatPattern[step]) triggerAmbientHat(t, velocity, darkFactor);

        if (step === 2 || step === 6 || step === 10 || step === 14) {
            triggerAmbientRainPulse(t + (stepDur * 0.5), darkFactor, velocity);
        }
        if ((ambientKillmail.beatBar % 2) === 0 && (step === 0 || step === 8)) {
            triggerAmbientBed(t, darkFactor);
        }

        const active = ambientKillmail.activeEvents.slice(0, 10);
        active.forEach(function(event) {
            if (!event || !event.instrument) return;
            if (!shouldPlayAmbientEventStep(event, step)) return;
            if (((step + event.stepOffset) % event.stepModulo) !== 0) return;

            for (let i = 0; i < event.repeatsPerStep; i++) {
                const offset = (i * stepDur) / Math.max(1, event.repeatsPerStep);
                const hitVelocity = velocity * event.level * Math.max(0.5, 1 - (i * 0.16));
                triggerAmbientInstrumentHit(t + offset, step, event, hitVelocity);
                ambientKillmail.progressionStep = (ambientKillmail.progressionStep + 1) % 128;
            }
        });

        ambientKillmail.nextBeatAt += stepDur;
        ambientKillmail.beatStep = (ambientKillmail.beatStep + 1) % 16;
        if (ambientKillmail.beatStep === 0) ambientKillmail.beatBar += 1;
    }
}

function createAmbientImpulse(ctx, seconds, decay) {
    const sampleRate = ctx.sampleRate;
    const length = Math.max(1, Math.floor(sampleRate * seconds));
    const impulse = ctx.createBuffer(2, length, sampleRate);

    for (let ch = 0; ch < 2; ch++) {
        const data = impulse.getChannelData(ch);
        for (let i = 0; i < length; i++) {
            const t = i / length;
            const envelope = Math.pow(1 - t, decay);
            data[i] = ((Math.random() * 2) - 1) * envelope;
        }
    }
    return impulse;
}

function parseSecurityStatus($row) {
    const securityText = ($row.find('.location span').first().text() || '').trim();
    if (!securityText) return -1;
    if (/^[A-Za-z]/.test(securityText)) return -1;

    const security = Number.parseFloat(securityText);
    if (Number.isNaN(security)) return -1;
    return Math.max(-1, Math.min(1, security));
}

function parseSecurityStatusFromValue(value) {
    const numeric = Number.parseFloat(value);
    if (Number.isNaN(numeric)) return -1;
    return Math.max(-1, Math.min(1, numeric));
}

function extractKillmailMusicInfo($row) {
    if (!$row) return null;

    const isJqueryRow = typeof $row.find === 'function' && typeof $row.attr === 'function';
    if (!isJqueryRow) {
        const killID = Number($row.killmail_id || $row.sequence_id || $row.killID || $row.kill_id || 0);
        const epochFromUploaded = Number($row.uploaded_at || 0);
        const epochFromTime = $row.killmail_time
            ? Math.floor(new Date($row.killmail_time).getTime() / 1000)
            : 0;
        const epoch = epochFromUploaded > 0 ? epochFromUploaded : epochFromTime;
        const valueRaw = Number($row.total_value || $row.valueRaw || 0);
        const attackerCount = Number($row.attacker_count || $row.attackerCount || 1) || 1;
        const labelsText = String($row.labels || '').toUpperCase();

        return {
            killID: killID,
            epoch: epoch,
            valueRaw: valueRaw,
            attackerCount: attackerCount,
            isSolo: attackerCount <= 1 || labelsText.indexOf('SOLO') >= 0,
            isGanked: labelsText.indexOf('GANKED') >= 0 || attackerCount >= 8,
            isNpc: labelsText.indexOf('NPC') >= 0,
            isPadding: labelsText.indexOf('PADDING') >= 0 || labelsText.indexOf('PAD') >= 0,
            securityStatus: parseSecurityStatusFromValue($row.security_status)
        };
    }

    if ($row.length === 0) return null;

    const killID = Number($row.attr('data-kill-id') || $row.attr('killid') || $row.attr('killID') || 0);
    const epoch = Number($row.attr('data-kill-date') || $row.attr('date') || 0);
    const valueField = $row.find("span[data-format='format-isk-once'], span[format='format-isk-once']");
    const valueRaw = Number(valueField.attr('data-raw') || valueField.attr('raw') || 0);

    let attackerCount = 1;
    const finalBlow = $row.find('.finalBlow').text() || '';
    const labelsText = finalBlow.toUpperCase();
    const countMatch = finalBlow.match(/\((\d+)\)/);
    if (countMatch) attackerCount = Number(countMatch[1]);
    else if (finalBlow.indexOf('1000+') >= 0) attackerCount = 1000;
    else if (finalBlow.indexOf('100+') >= 0) attackerCount = 100;

    const isSolo = labelsText.indexOf('SOLO') >= 0;
    const isGanked = labelsText.indexOf('GANKED') >= 0;
    const isNpc = labelsText.indexOf('NPC') >= 0;
    const isPadding = labelsText.indexOf('PADDING') >= 0 || labelsText.indexOf('PAD') >= 0;

    return {
        killID: killID,
        epoch: epoch,
        valueRaw: valueRaw,
        attackerCount: attackerCount,
        isSolo: isSolo,
        isGanked: isGanked,
        isNpc: isNpc,
        isPadding: isPadding,
        securityStatus: parseSecurityStatus($row)
    };
}

function getAmbientIskBand(valueRaw) {
    if (valueRaw >= 10000000000) return 'colossal';
    if (valueRaw >= 1000000000) return 'large';
    if (valueRaw >= 100000000) return 'medium';
    if (valueRaw >= 10000000) return 'small';
    return 'micro';
}

function getAmbientInstrumentName(info) {
    if (info.isPadding) return 'pad';
    if (info.isNpc) return 'drone';
    if (info.isGanked && info.attackerCount >= 15) return 'drums';
    if (info.isSolo) return 'drone';

    const seed = Math.abs((info.killID * 3) + Math.floor(info.valueRaw / 1000000) + info.attackerCount);
    const names = ['bass', 'drone', 'pad', 'drums'];
    return names[seed % names.length];
}

function getAmbientInstrument(info) {
    const band = getAmbientIskBand(info.valueRaw);
    const name = getAmbientInstrumentName(info);
    const base = {
        name: name,
        band: band,
        oscA: 'square',
        oscB: 'triangle',
        subMix: 0.24,
        detuneA: -3,
        detuneB: 4,
        harmonicB: 1.5,
        duration: 1.8,
        filterBase: 980,
        filterQ: 1.05,
        attack: 0.02,
        decay: 0.23,
        sustain: 0.46,
        release: 0.44,
        amp: 0.076,
        vibratoRate: 0,
        vibratoDepth: 0,
        driftCents: 4
    };

    if (name === 'lead') {
        base.oscA = 'square';
        base.oscB = 'square';
        base.detuneA = -4;
        base.detuneB = 4;
        base.harmonicB = 1;
        base.duration = 1.6;
        base.filterBase = 900;
        base.attack = 0.035;
        base.decay = 0.2;
        base.sustain = 0.34;
        base.release = 0.42;
        base.amp = 0.065;
        base.vibratoRate = 2.2;
        base.vibratoDepth = 2;
    } else if (name === 'pluck') {
        base.oscA = 'square';
        base.oscB = 'square';
        base.detuneA = -6;
        base.detuneB = 6;
        base.harmonicB = 1;
        base.duration = 1.1;
        base.filterBase = 760;
        base.filterQ = 1.1;
        base.attack = 0.02;
        base.decay = 0.2;
        base.sustain = 0.28;
        base.release = 0.32;
        base.amp = 0.062;
        base.driftCents = 7;
    } else if (name === 'bass') {
        base.oscA = 'square';
        base.oscB = 'sine';
        base.detuneA = -2;
        base.detuneB = 0;
        base.harmonicB = 0.5;
        base.subMix = 0.38;
        base.duration = 0.9;
        base.filterBase = 620;
        base.filterQ = 1.08;
        base.attack = 0.008;
        base.decay = 0.14;
        base.sustain = 0.3;
        base.release = 0.2;
        base.amp = 0.09;
        base.driftCents = 5;
    } else if (name === 'drone') {
        base.oscA = 'triangle';
        base.oscB = 'triangle';
        base.detuneA = 0;
        base.detuneB = 0;
        base.harmonicB = 0.5;
        base.subMix = 0.12;
        base.duration = 2.6;
        base.filterBase = 700;
        base.filterQ = 0.6;
        base.attack = 0.05;
        base.decay = 0.35;
        base.sustain = 0.58;
        base.release = 0.9;
        base.amp = 0.055;
        base.vibratoRate = 2.4;
        base.vibratoDepth = 3;
    } else if (name === 'pad') {
        base.oscA = 'triangle';
        base.oscB = 'triangle';
        base.detuneA = -5;
        base.detuneB = 5;
        base.harmonicB = 1;
        base.subMix = 0.3;
        base.duration = 3.1;
        base.filterBase = 780;
        base.filterQ = 0.95;
        base.attack = 0.09;
        base.decay = 0.45;
        base.sustain = 0.62;
        base.release = 1.1;
        base.amp = 0.06;
        base.vibratoRate = 3.5;
        base.vibratoDepth = 5;
    } else if (name === 'drums') {
        base.oscA = 'square';
        base.oscB = 'square';
        base.detuneA = -10;
        base.detuneB = 10;
        base.harmonicB = 1;
        base.subMix = 0;
        base.duration = 0.45;
        base.filterBase = 820;
        base.filterQ = 1.2;
        base.attack = 0.002;
        base.decay = 0.07;
        base.sustain = 0.18;
        base.release = 0.12;
        base.amp = 0.05;
        base.vibratoRate = 0;
        base.vibratoDepth = 0;
        base.driftCents = 8;
    }

    if (band === 'small') {
        base.duration *= 1.1;
        base.amp *= 0.92;
        base.filterBase *= 0.95;
    } else if (band === 'medium') {
        base.duration *= 1.25;
        base.amp *= 1.04;
        base.filterBase *= 0.9;
    } else if (band === 'large') {
        base.duration *= 1.45;
        base.amp *= 1.14;
        base.filterBase *= 0.83;
        base.release *= 1.2;
    } else if (band === 'colossal') {
        base.duration *= 1.8;
        base.amp *= 1.22;
        base.filterBase *= 0.72;
        base.release *= 1.5;
        base.subMix = Math.min(0.42, base.subMix + 0.1);
    }

    return base;
}

function getAmbientHourTransposeMultiplier(epochSeconds) {
    const utcHour = epochSeconds > 0
        ? new Date(epochSeconds * 1000).getUTCHours()
        : new Date().getUTCHours();
    // Move through nearby keys over a day with darker range: -4..+1.
    const semitoneShift = Math.floor(utcHour / 4) - 4;
    return Math.pow(2, semitoneShift / 12);
}

function midiToHz(midi) {
    return 440 * Math.pow(2, (midi - 69) / 12);
}

function getAmbientPitch(info, band, step) {
    const valueLog = Math.log10(Math.max(10000, info.valueRaw));
    const center = 38 + Math.min(18, Math.floor((valueLog - 4) * 2.4));
    const attackerOffset = Math.min(6, Math.floor(Math.log2(Math.max(1, info.attackerCount))));
    const rootMidi = Math.max(30, Math.min(70, center - attackerOffset));
    ambientKillmail.lastRootMidi = Math.round((ambientKillmail.lastRootMidi * 0.65) + (rootMidi * 0.35));

    const micro = [0, 1, 3, 6, 10];
    const small = [0, 1, 3, 5, 8, 10];
    const medium = [0, 1, 3, 6, 8, 10];
    const large = [0, 1, 4, 6, 9, 10];
    const colossal = [0, 1, 6, 7, 9, 10];
    const table = {
        micro: micro,
        small: small,
        medium: medium,
        large: large,
        colossal: colossal
    };

    const intervals = table[band] || medium;
    const seed = Math.abs((info.killID * 11) + Math.floor(info.valueRaw / 1000000) + (info.attackerCount * 17) + step);
    const interval = intervals[seed % intervals.length];
    const octave = band === 'large' ? (seed % 2) : 0;
    return ambientKillmail.lastRootMidi + interval + (octave * 12);
}

function applyAmbientEnvelope(gainNode, t0, duration, instrument, ampScale) {
    const peak = Math.max(0.004, instrument.amp * ampScale);
    const sustainValue = Math.max(0.002, peak * instrument.sustain);
    const attackEnd = t0 + Math.max(0.002, instrument.attack);
    const decayEnd = attackEnd + Math.max(0.01, instrument.decay);
    const releaseStart = Math.max(decayEnd + 0.03, t0 + duration - Math.max(0.05, instrument.release));
    const end = releaseStart + Math.max(0.05, instrument.release);

    gainNode.gain.setValueAtTime(0.0001, t0);
    gainNode.gain.exponentialRampToValueAtTime(peak, attackEnd);
    gainNode.gain.exponentialRampToValueAtTime(sustainValue, decayEnd);
    gainNode.gain.setValueAtTime(sustainValue, releaseStart);
    gainNode.gain.exponentialRampToValueAtTime(0.0001, end);

    return end;
}

function getAmbientRepeatConfig(valueRaw) {
    const minLog = 6;
    const maxLog = 12;
    const valueLog = Math.log10(Math.max(1000000, valueRaw || 0));
    const norm = Math.max(0, Math.min(1, (valueLog - minLog) / (maxLog - minLog)));

    return {
        durationSec: Math.round(120 + (norm * 780)),
        stepModulo: Math.max(1, 4 - Math.floor(norm * 3)),
        repeatsPerStep: 1 + Math.floor(norm * 2),
        level: 0.9 + (norm * 0.65)
    };
}

function shouldPlayAmbientInstrumentStep(instrumentName, step) {
    if (instrumentName === 'lead') return [0, 4, 6, 8, 12, 14].indexOf(step) >= 0;
    if (instrumentName === 'pluck') return [0, 2, 4, 6, 8, 10, 12, 14].indexOf(step) >= 0;
    if (instrumentName === 'bass') return [0, 4, 8, 12].indexOf(step) >= 0;
    if (instrumentName === 'drone') return step === 0 || step === 8;
    if (instrumentName === 'pad') return step === 0 || step === 8;
    if (instrumentName === 'drums') return [4, 12].indexOf(step) >= 0;
    return step === 0 || step === 8;
}

function getAmbientInstrumentPattern(instrumentName, variant) {
    const patterns = {
        lead: [
            [1,0,0,0,1,0,1,0,1,0,0,0,1,0,1,0],
            [1,0,0,0,1,0,0,0,1,0,1,0,1,0,0,0],
            [1,0,1,0,1,0,0,0,1,0,1,0,1,0,1,0]
        ],
        pluck: [
            [1,0,1,0,1,0,1,0,1,0,1,0,1,0,1,0],
            [1,0,0,1,1,0,0,1,1,0,0,1,1,0,0,1],
            [1,1,0,1,1,0,1,0,1,1,0,1,1,0,1,0]
        ],
        bass: [
            [1,0,0,0,1,0,0,0,1,0,0,0,1,0,0,0],
            [1,0,0,0,0,0,1,0,1,0,0,0,0,0,1,0],
            [1,0,0,0,1,0,0,0,0,0,1,0,1,0,0,0]
        ],
        drone: [
            [1,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0],
            [1,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0],
            [1,0,0,0,1,0,0,0,1,0,0,0,1,0,0,0]
        ],
        pad: [
            [1,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0],
            [1,0,0,0,1,0,0,0,1,0,0,0,1,0,0,0],
            [1,0,0,0,0,0,0,0,1,0,0,0,1,0,0,0]
        ],
        drums: [
            [0,0,0,0,1,0,0,0,0,0,0,0,1,0,0,0],
            [0,0,1,0,1,0,0,0,0,0,1,0,1,0,0,0],
            [0,0,0,0,1,0,1,0,0,0,0,0,1,0,1,0]
        ]
    };

    const bank = patterns[instrumentName] || patterns.pluck;
    return bank[variant % bank.length];
}

function shouldPlayAmbientEventStep(event, step) {
    const pattern = getAmbientInstrumentPattern(event.instrument.name, event.patternVariant || 0);
    return pattern[step] === 1;
}

function triggerAmbientTom(stepTime, velocity) {
    if (!ambientKillmail.ctx || !ambientKillmail.masterGain) return;
    const ctx = ambientKillmail.ctx;
    const osc = ctx.createOscillator();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(170, stepTime);
    osc.frequency.exponentialRampToValueAtTime(95, stepTime + 0.1);

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, stepTime);
    gain.gain.exponentialRampToValueAtTime(0.045 * velocity, stepTime + 0.006);
    gain.gain.exponentialRampToValueAtTime(0.0001, stepTime + 0.12);

    osc.connect(gain);
    gain.connect(ambientKillmail.masterGain);
    osc.start(stepTime);
    osc.stop(stepTime + 0.14);
    registerAmbientVoice(stepTime + 0.2, [gain], [osc]);
}

function triggerAmbientRainPulse(stepTime, darkFactor, velocity) {
    if (!ambientKillmail.ctx || !ambientKillmail.masterGain) return;
    const ctx = ambientKillmail.ctx;
    ambientKillmail.rainSeed = (ambientKillmail.rainSeed + 1) % 32;

    const shimmer = ctx.createOscillator();
    shimmer.type = 'square';
    const base = 1680 + ((ambientKillmail.rainSeed % 7) * 190);
    shimmer.frequency.setValueAtTime(base - (darkFactor * 130), stepTime);

    const band = ctx.createBiquadFilter();
    band.type = 'bandpass';
    band.frequency.setValueAtTime(base + 260, stepTime);
    band.Q.setValueAtTime(6.5, stepTime);

    const hp = ctx.createBiquadFilter();
    hp.type = 'highpass';
    hp.frequency.setValueAtTime(1400 + (darkFactor * 500), stepTime);

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, stepTime);
    gain.gain.exponentialRampToValueAtTime(0.015 * velocity, stepTime + 0.004);
    gain.gain.exponentialRampToValueAtTime(0.0001, stepTime + 0.09);

    shimmer.connect(band);
    band.connect(hp);
    hp.connect(gain);
    gain.connect(ambientKillmail.masterGain);
    shimmer.start(stepTime);
    shimmer.stop(stepTime + 0.1);

    registerAmbientVoice(stepTime + 0.16, [band, hp, gain], [shimmer]);
}

function triggerAmbientBed(stepTime, darkFactor) {
    if (!ambientKillmail.ctx || !ambientKillmail.masterGain) return;
    const ctx = ambientKillmail.ctx;
    ambientKillmail.bedPhase = (ambientKillmail.bedPhase + 1) % 6;

    const root = 52 + (ambientKillmail.bedPhase * 7);
    const droneA = ctx.createOscillator();
    const droneB = ctx.createOscillator();
    droneA.type = 'triangle';
    droneB.type = 'sine';
    droneA.frequency.setValueAtTime(root, stepTime);
    droneB.frequency.setValueAtTime(root * 0.5, stepTime);

    const lp = ctx.createBiquadFilter();
    lp.type = 'lowpass';
    lp.frequency.setValueAtTime(520 - (darkFactor * 180), stepTime);
    lp.Q.setValueAtTime(0.7, stepTime);

    const gain = ctx.createGain();
    const end = stepTime + 3.8;
    gain.gain.setValueAtTime(0.0001, stepTime);
    gain.gain.exponentialRampToValueAtTime(0.03, stepTime + 0.45);
    gain.gain.setValueAtTime(0.022, stepTime + 2.2);
    gain.gain.exponentialRampToValueAtTime(0.0001, end);

    droneA.connect(lp);
    droneB.connect(lp);
    lp.connect(gain);
    gain.connect(ambientKillmail.masterGain);
    droneA.start(stepTime);
    droneB.start(stepTime);
    droneA.stop(end + 0.05);
    droneB.stop(end + 0.05);

    registerAmbientVoice(end + 0.1, [lp, gain], [droneA, droneB]);
}

function triggerAmbientInstrumentHit(stepTime, step, event, velocity) {
    if (!event || !event.info || !event.instrument || !ambientKillmail.ctx || !ambientKillmail.masterGain) return;

    const info = event.info;
    const instrument = event.instrument;
    const amplitudeScale = event.amplitudeScale;
    const darkFactor = event.darkFactor;

    if (instrument.name === 'drums') {
        if (step === 2 || step === 10) triggerAmbientTom(stepTime, velocity);
        return;
    }

    const ctx = ambientKillmail.ctx;
    const hitDuration = Math.max(0.2, Math.min(2.2, instrument.duration * 0.68));
    const pitchMidi = getAmbientPitch(info, instrument.band, ambientKillmail.progressionStep + step);
    const transpose = getAmbientHourTransposeMultiplier(info.epoch);
    const baseHz = midiToHz(pitchMidi) * transpose;
    const secondHz = baseHz * instrument.harmonicB;
    const subHz = Math.max(24, baseHz * 0.5);
    const t0 = stepTime;

    const voiceGain = ctx.createGain();
    const hitInstrument = {
        amp: instrument.amp * Math.max(0.68, velocity * 1.18),
        sustain: instrument.sustain,
        attack: instrument.attack,
        decay: instrument.decay,
        release: instrument.release
    };
    const stopAt = applyAmbientEnvelope(voiceGain, t0, hitDuration, hitInstrument, amplitudeScale);

    const toneFilter = ctx.createBiquadFilter();
    toneFilter.type = 'lowpass';
    toneFilter.frequency.setValueAtTime(Math.max(180, instrument.filterBase * (1 - (darkFactor * 0.52))), t0);
    toneFilter.Q.setValueAtTime(instrument.filterQ + (darkFactor * 0.28), t0);

    const voicePan = ctx.createStereoPanner();
    const panSeed = ((info.killID % 200) / 100) - 1;
    voicePan.pan.setValueAtTime(Math.max(-0.5, Math.min(0.5, panSeed * 0.4)), t0);

    voiceGain.connect(toneFilter);
    toneFilter.connect(voicePan);
    voicePan.connect(ambientKillmail.masterGain);

    const buildOsc = function(freq, type, detune) {
        const osc = ctx.createOscillator();
        osc.type = type;
        osc.frequency.setValueAtTime(freq, t0);
        const drift = (((info.killID + Math.floor(freq) + step) % 17) - 8) * instrument.driftCents * 0.1;
        osc.detune.setValueAtTime(detune + drift, t0);
        osc.connect(voiceGain);
        osc.start(t0);
        osc.stop(stopAt + 0.05);
        return osc;
    };

    const voices = [
        buildOsc(baseHz, instrument.oscA, instrument.detuneA),
        buildOsc(secondHz, instrument.oscB, instrument.detuneB)
    ];

    if (instrument.subMix > 0.01) {
        const subGain = ctx.createGain();
        subGain.gain.setValueAtTime(Math.max(0.001, instrument.subMix * 0.14 * amplitudeScale * velocity), t0);
        subGain.gain.exponentialRampToValueAtTime(0.0001, stopAt);
        const subOsc = ctx.createOscillator();
        subOsc.type = 'sine';
        subOsc.frequency.setValueAtTime(subHz, t0);
        subOsc.connect(subGain);
        subGain.connect(toneFilter);
        subOsc.start(t0);
        subOsc.stop(stopAt + 0.04);
        voices.push(subOsc);
        registerAmbientVoice(stopAt, [subGain], []);
    }

    if (instrument.vibratoRate > 0 && instrument.vibratoDepth > 0) {
        const lfo = ctx.createOscillator();
        const lfoGain = ctx.createGain();
        lfo.type = 'sine';
        lfo.frequency.setValueAtTime(instrument.vibratoRate + (darkFactor * 0.8), t0);
        lfoGain.gain.setValueAtTime(instrument.vibratoDepth * 0.6, t0);
        lfo.connect(lfoGain);
        voices.forEach(function(osc) {
            if (!osc.detune) return;
            lfoGain.connect(osc.detune);
        });
        lfo.start(t0);
        lfo.stop(stopAt + 0.05);
        registerAmbientVoice(stopAt + 0.05, [lfoGain], [lfo]);
    }

    registerAmbientVoice(stopAt, [voiceGain, toneFilter, voicePan], voices);
}

function playAmbientKillmailNote($row) {
    if (ambientKillmail.mode === 'off') return;
    ensureAmbientAudioReady();
    if (!ambientKillmail.ctx || !ambientKillmail.masterGain) return;

    const nowMs = Date.now();
    if (nowMs - ambientKillmail.lastPlayedAt < 170) return;
    ambientKillmail.lastPlayedAt = nowMs;

    const info = extractKillmailMusicInfo($row);
    if (!info) return;
    const amplitudeScale = getAmbientAmplitudeScale();
    const darkFactor = (1 - info.securityStatus) / 2;
    const instrument = getAmbientInstrument(info);
    ambientKillmail.beatInstrument = instrument;
    ambientKillmail.beatDarkFactor = darkFactor;
    ambientKillmail.beatHoldUntil = Math.max(
        ambientKillmail.beatHoldUntil || 0,
        ambientKillmail.ctx.currentTime + 120
    );

    const repeatConfig = getAmbientRepeatConfig(info.valueRaw);
    const now = ambientKillmail.ctx.currentTime;
    const repeatUntil = now + repeatConfig.durationSec;

    ambientKillmail.activeEvents.push({
        info: info,
        instrument: instrument,
        darkFactor: darkFactor,
        amplitudeScale: amplitudeScale,
        queuedAt: now,
        repeatUntil: repeatUntil,
        stepModulo: repeatConfig.stepModulo,
        repeatsPerStep: repeatConfig.repeatsPerStep,
        level: repeatConfig.level,
        stepOffset: 0,
        patternVariant: Math.abs(info.killID) % 3
    });
    if (ambientKillmail.activeEvents.length > 24) {
        ambientKillmail.activeEvents = ambientKillmail.activeEvents.slice(ambientKillmail.activeEvents.length - 24);
    }

    ambientKillmail.beatHoldUntil = Math.max(ambientKillmail.beatHoldUntil || 0, repeatUntil);

    startAmbientTransport();

    const t0 = ambientKillmail.ctx.currentTime;
    if (ambientKillmail.colorLowpass) {
        ambientKillmail.colorLowpass.frequency.setValueAtTime(2200 - (darkFactor * 1100), t0);
    }
    if (ambientKillmail.echoDelay) ambientKillmail.echoDelay.delayTime.setValueAtTime(0.24 + (darkFactor * 0.1), t0);
    if (ambientKillmail.echoWet) ambientKillmail.echoWet.gain.setValueAtTime(0.18 + (instrument.release * 0.05), t0);
    if (ambientKillmail.reverbGain) ambientKillmail.reverbGain.gain.setValueAtTime(0.2 + (instrument.release * 0.1), t0);
}

function setAmbientKillmailVolume(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return;
    ambientKillmail.volumeScalar = Math.max(0, Math.min(1, numeric));
    if (ambientKillmail.ctx) applyAmbientOutputLevel();
}

function cleanupAmbientVoices() {
    if (!ambientKillmail.ctx) return;
    const now = ambientKillmail.ctx.currentTime;
    ambientKillmail.voices = ambientKillmail.voices.filter(function(voice) {
        if (voice.stopAt > now + 0.2) return true;
        (voice.oscs || []).forEach(function(osc) {
            try { osc.disconnect(); } catch (e) {}
        });
        (voice.nodes || []).forEach(function(node) {
            try { node.disconnect(); } catch (e) {}
        });
        return false;
    });
}

window.playAmbientKillmailNote = playAmbientKillmailNote;
window.setAmbientKillmailVolume = setAmbientKillmailVolume;

if (window.jQuery && typeof window.jQuery === 'function') {
    window.jQuery(document).ready(function() {
        initAmbientKillmailMusic();
    });
} else {
    document.addEventListener('DOMContentLoaded', function() {
        initAmbientKillmailMusic();
    });
}
