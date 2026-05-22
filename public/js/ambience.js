const ambientKillmail = {
    mode: 'off',
    ctx: undefined,
    masterGain: undefined,
    reverbConvolver: undefined,
    reverbLowpass: undefined,
    reverbGain: undefined,
    lastPlayedAt: 0,
    voices: [],
    progressionStep: 0
};

function initAmbientKillmailMusic() {
    if (window.location.pathname !== '/') return;

    ambientKillmail.mode = 'off';

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
    if (ambientKillmail.mode === 'loud') return 0.16;
    if (ambientKillmail.mode === 'low') return 0.082;
    return 0;
}

function getAmbientAmplitudeScale() {
    if (ambientKillmail.mode === 'loud') return 1;
    if (ambientKillmail.mode === 'low') return 0.58;
    return 0;
}

function applyAmbientOutputLevel() {
    if (!ambientKillmail.masterGain || !ambientKillmail.ctx) return;
    const now = ambientKillmail.ctx.currentTime;
    ambientKillmail.masterGain.gain.cancelScheduledValues(now);
    ambientKillmail.masterGain.gain.setTargetAtTime(getAmbientMasterGain(), now, 0.05);
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
    ambientKillmail.reverbConvolver = ambientKillmail.ctx.createConvolver();
    ambientKillmail.reverbConvolver.buffer = createAmbientImpulse(ambientKillmail.ctx, 6.2, 2.4);
    ambientKillmail.reverbLowpass = ambientKillmail.ctx.createBiquadFilter();
    ambientKillmail.reverbLowpass.type = 'lowpass';
    ambientKillmail.reverbLowpass.frequency.value = 3200;
    ambientKillmail.reverbGain = ambientKillmail.ctx.createGain();
    ambientKillmail.reverbGain.gain.value = 0.34;

    ambientKillmail.masterGain.connect(ambientKillmail.ctx.destination);
    ambientKillmail.masterGain.connect(ambientKillmail.reverbLowpass);
    ambientKillmail.reverbLowpass.connect(ambientKillmail.reverbConvolver);
    ambientKillmail.reverbConvolver.connect(ambientKillmail.reverbGain);
    ambientKillmail.reverbGain.connect(ambientKillmail.ctx.destination);

    if (ambientKillmail.ctx.state === 'suspended') ambientKillmail.ctx.resume();
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

function extractKillmailMusicInfo($row) {
    if (!$row || $row.length === 0) return null;

    const killID = Number($row.attr('killid') || $row.attr('killID') || 0);
    const epoch = Number($row.attr('date') || 0);
    const valueRaw = Number($row.find("span[format='format-isk-once']").attr('raw') || 0);

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

    return {
        killID: killID,
        epoch: epoch,
        valueRaw: valueRaw,
        attackerCount: attackerCount,
        isSolo: isSolo,
        isGanked: isGanked,
        isNpc: isNpc,
        securityStatus: parseSecurityStatus($row)
    };
}

function getAmbientProfile(info) {
    if (info.isNpc) {
        return {
            name: 'npc',
            oscA: 'triangle',
            oscB: 'sine',
            detuneA: 0,
            detuneB: 0,
            harmonicB: 0.5,
            brightness: 700,
            q: 0.6,
            ampScale: 0.84,
            durScale: 1.2
        };
    }
    if (info.isGanked) {
        return {
            name: 'ganked',
            oscA: 'triangle',
            oscB: 'triangle',
            detuneA: 0,
            detuneB: 0,
            harmonicB: 2,
            brightness: 1350,
            q: 0.95,
            ampScale: 1.05,
            durScale: 0.92
        };
    }
    if (info.isSolo) {
        return {
            name: 'solo',
            oscA: 'sine',
            oscB: 'triangle',
            detuneA: 0,
            detuneB: 0,
            harmonicB: 1.5,
            brightness: 880,
            q: 0.7,
            ampScale: 0.94,
            durScale: 1.3
        };
    }
    return {
        name: 'default',
        oscA: 'sine',
        oscB: 'triangle',
        detuneA: 0,
        detuneB: 0,
        harmonicB: 1.5,
        brightness: 940,
        q: 0.75,
        ampScale: 1,
        durScale: 1
    };
}

function getAmbientHourTransposeMultiplier(epochSeconds) {
    const utcHour = epochSeconds > 0
        ? new Date(epochSeconds * 1000).getUTCHours()
        : new Date().getUTCHours();
    // Move through nearby keys over a day: -2, -1, 0, 1, 2, 3 semitones.
    const semitoneShift = Math.floor(utcHour / 4) - 2;
    return Math.pow(2, semitoneShift / 12);
}

function playAmbientKillmailNote($row) {
    if (ambientKillmail.mode === 'off' || window.location.pathname !== '/') return;
    ensureAmbientAudioReady();
    if (!ambientKillmail.ctx || !ambientKillmail.masterGain) return;

    const nowMs = Date.now();
    if (nowMs - ambientKillmail.lastPlayedAt < 360) return;
    ambientKillmail.lastPlayedAt = nowMs;

    const info = extractKillmailMusicInfo($row);
    if (!info) return;
    const profile = getAmbientProfile(info);
    const amplitudeScale = getAmbientAmplitudeScale();
    const darkFactor = (1 - info.securityStatus) / 2;

    const valueFactor = Math.max(1, Math.log10(Math.max(10, info.valueRaw)));
    const duration = Math.min(60, (18 + valueFactor * 4.8) * profile.durScale * (1 + darkFactor * 0.35));
    const amplitude = Math.min(0.26, (0.048 + valueFactor * 0.013) * profile.ampScale * amplitudeScale);
    const attackerFactor = Math.max(1, Math.min(12, Math.log10(Math.max(1, info.attackerCount)) + 1));

    const progression = [
        [130.81, 196.0, 261.63],
        [146.83, 220.0, 293.66],
        [164.81, 246.94, 329.63],
        [130.81, 196.0, 293.66],
        [146.83, 220.0, 329.63],
        [164.81, 246.94, 392.0]
    ];
    const seed = Math.abs((info.killID * 7) + Math.floor(info.valueRaw) + (info.attackerCount * 13) + Math.floor(info.epoch / 60));
    const chord = progression[(ambientKillmail.progressionStep + seed) % progression.length];
    ambientKillmail.progressionStep = (ambientKillmail.progressionStep + 1) % progression.length;
    const transpose = getAmbientHourTransposeMultiplier(info.epoch);
    const baseHz = chord[0] * transpose;
    const octaveHz = baseHz * 2;

    const ctx = ambientKillmail.ctx;
    const t0 = ctx.currentTime + 0.01;
    const tEnd = t0 + duration;
    const attackTime = Math.min(8, 2.6 + valueFactor * 0.36);
    const bloomTime = Math.min(24, 10 + attackerFactor * 0.9);
    const releaseTime = Math.min(12, Math.max(6, duration * 0.2));
    const sustainUntil = Math.max(t0 + attackTime + 0.5, tEnd - releaseTime);
    const sustainLevel = Math.max(0.035, amplitude * 0.88);

    const voiceGain = ctx.createGain();
    voiceGain.gain.setValueAtTime(0.0001, t0);
    voiceGain.gain.exponentialRampToValueAtTime(amplitude, t0 + attackTime);
    voiceGain.gain.exponentialRampToValueAtTime(sustainLevel, t0 + bloomTime);
    voiceGain.gain.setValueAtTime(sustainLevel, sustainUntil);
    voiceGain.gain.exponentialRampToValueAtTime(0.0001, tEnd);

    const lowpass = ctx.createBiquadFilter();
    lowpass.type = 'lowpass';
    const brightness = (profile.brightness + 260) + (attackerFactor * 120);
    const darkenedBrightness = Math.max(260, brightness * (1 - darkFactor * 0.6));
    lowpass.frequency.setValueAtTime(darkenedBrightness, t0);
    lowpass.Q.setValueAtTime(Math.max(0.45, profile.q - (darkFactor * 0.15)), t0);

    if (ambientKillmail.reverbGain) ambientKillmail.reverbGain.gain.value = 0.34 + (darkFactor * 0.12);
    if (ambientKillmail.reverbLowpass) ambientKillmail.reverbLowpass.frequency.value = 3200 - (darkFactor * 900);

    voiceGain.connect(lowpass);
    lowpass.connect(ambientKillmail.masterGain);

    const buildOsc = function(freq, type, detune) {
        const osc = ctx.createOscillator();
        osc.type = type;
        osc.frequency.setValueAtTime(freq, t0);
        osc.detune.setValueAtTime(detune, t0);
        osc.connect(voiceGain);
        osc.start(t0);
        osc.stop(tEnd + 0.1);
        return osc;
    };

    const voices = [
        buildOsc(baseHz, 'sine', 0),
        buildOsc(octaveHz, 'sine', 0)
    ];

    ambientKillmail.voices.push({
        stopAt: tEnd,
        gain: voiceGain,
        filter: lowpass,
        oscs: voices
    });

    cleanupAmbientVoices();
}

function cleanupAmbientVoices() {
    if (!ambientKillmail.ctx) return;
    const now = ambientKillmail.ctx.currentTime;
    ambientKillmail.voices = ambientKillmail.voices.filter(function(voice) {
        if (voice.stopAt > now + 0.2) return true;
        voice.oscs.forEach(function(osc) {
            try { osc.disconnect(); } catch (e) {}
        });
        try { voice.gain.disconnect(); } catch (e) {}
        try { voice.filter.disconnect(); } catch (e) {}
        return false;
    });
}

window.playAmbientKillmailNote = playAmbientKillmailNote;

$(document).ready(function() {
    initAmbientKillmailMusic();
});
