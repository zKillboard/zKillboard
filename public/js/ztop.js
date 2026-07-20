window.zkbZtopLoadTimeout = setTimeout(load_ztop, 1000);

function load_ztop() {
    if (window.location.pathname != '/ztop/') return;
    if (!pubsub) {
        window.zkbZtopLoadTimeout = setTimeout(load_ztop, 250);
        return;
    }
    pubsub('ztop');
}

const ztopState = {
    metricSeries: {},
    maxPoints: 900,
    lastMetrics: [],
    lastUpdateAt: null,
    esiBucketSeries: {},
    serverSeries: {}
};
window.ztopState = ztopState;

function toNumber(value) {
    if (value === null || value === undefined) return null;
    const cleaned = String(value).replace(/,/g, '').trim();
    const match = cleaned.match(/(-?\d+(?:\.\d+)?)/);
    return match ? parseFloat(match[1]) : null;
}

function updateDelta(el, delta) {
    if (!el) return;
    el.textContent = '';
    el.classList.remove('positive', 'negative');
    if (!delta || delta === '0' || delta === '+0' || delta === '-0') return;
    const value = Number(delta);
    const text = delta.toString().startsWith('+') || delta.toString().startsWith('-') ? delta : (value > 0 ? `+${delta}` : `${delta}`);
    el.textContent = text;
    el.classList.add(value >= 0 ? 'positive' : 'negative');
}

function pushSeries(key, value) {
    if (!ztopState.metricSeries[key]) ztopState.metricSeries[key] = [];
    ztopState.metricSeries[key].push(value);
    if (ztopState.metricSeries[key].length > ztopState.maxPoints) ztopState.metricSeries[key].shift();
}

function renderSparkline(canvas, data, color) {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const width = canvas.clientWidth || 140;
    const height = canvas.clientHeight || 46;
    canvas.width = width;
    canvas.height = height;
    ctx.clearRect(0, 0, width, height);

    if (!data || data.length === 0) return;
    let min = Math.min(...data);
    let max = Math.max(...data);
    if (min === max) max = min + 1;
    const padding = 4;
    const chartWidth = Math.max(1, width - padding * 2);
    const chartHeight = Math.max(1, height - padding * 2);
    ctx.strokeStyle = color;
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.beginPath();
    data.forEach((value, index) => {
        const x = padding + (chartWidth * index) / Math.max(data.length - 1, 1);
        const y = padding + chartHeight - ((value - min) / (max - min)) * chartHeight;
        if (index === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.stroke();
}

function renderMultiSparkline(canvas, seriesByCode, codes) {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const width = canvas.clientWidth || 220;
    const height = canvas.clientHeight || 52;
    canvas.width = width;
    canvas.height = height;
    ctx.clearRect(0, 0, width, height);

    const values = [];
    codes.forEach((code) => {
        (seriesByCode[code] || []).forEach((value) => values.push(value));
    });
    if (values.length === 0) return;

    const min = 0;
    let max = Math.max(...values);
    if (max === min) max = min + 1;
    const padding = 4;
    const chartWidth = Math.max(1, width - padding * 2);
    const chartHeight = Math.max(1, height - padding * 2);

    codes.forEach((code, index) => {
        const data = seriesByCode[code] || [];
        if (data.length === 0) return;
        ctx.strokeStyle = codeColor(code, index);
        ctx.lineWidth = code === '200' || code === '304' ? 2 : 1.5;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.beginPath();
        data.forEach((value, pointIndex) => {
            const x = padding + (chartWidth * pointIndex) / Math.max(data.length - 1, 1);
            const y = padding + chartHeight - ((value - min) / (max - min)) * chartHeight;
            if (pointIndex === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
    });
}

function parseServerLine(line) {
    if (!line) return null;
    const cpuMatch = line.match(/CPU:\s*([0-9.]+)%/i);
    const loadMatch = line.match(/Load:\s*([0-9.]+)/i);
    const memMatch = line.match(/Memory:\s*([0-9.,]+)G\/([0-9.,]+)G/i);
    const memAlt = line.match(/Memory:\s*([^\s]+)/i);
    const redisMatch = line.match(/Redis:\s*([^\s]+)/i);
    const mongoMatch = line.match(/MongoDB:\s*([0-9.,]+)G\/\s*([0-9.,]+)G/i);
    const mongoAlt = line.match(/MongoDB:\s*([^\s]+)/i);
    const roleMatch = line.match(/^([A-Za-z])\s/);

    const memUsed = memMatch ? parseFloat(memMatch[1].replace(/,/g, '')) : null;
    const memTotal = memMatch ? parseFloat(memMatch[2].replace(/,/g, '')) : null;
    const cpuValue = cpuMatch ? parseFloat(cpuMatch[1]) : null;
    const loadValue = loadMatch ? parseFloat(loadMatch[1]) : null;
    const redisValue = redisMatch ? sizeToMegabytes(redisMatch[1]) : null;
    const mongoValue = mongoMatch ? sizeToMegabytes(`${mongoMatch[1]}G`) : null;

    return {
        role: roleMatch ? roleMatch[1] : '',
        cpu: cpuMatch ? `${cpuMatch[1]}%` : '--',
        load: loadMatch ? loadMatch[1] : '--',
        memory: memMatch ? `${memMatch[1]}G/${memMatch[2]}G` : (memAlt ? memAlt[1] : '--'),
        redis: redisMatch ? redisMatch[1] : '--',
        mongo: mongoMatch ? `${mongoMatch[1]}G/${mongoMatch[2]}G` : (mongoAlt ? mongoAlt[1] : '--'),
        cpuValue,
        loadValue,
        memUsed,
        memTotal,
        redisValue,
        mongoValue
    };
}

function sizeToMegabytes(value) {
    if (!value) return null;
    const match = String(value).trim().match(/^([0-9.,]+)\s*([KMGTP])?B?$/i);
    if (!match) return null;
    const num = parseFloat(match[1].replace(/,/g, ''));
    const unit = (match[2] || 'M').toUpperCase();
    const multipliers = { K: 1 / 1024, M: 1, G: 1024, T: 1024 * 1024, P: 1024 * 1024 * 1024 };
    return num * (multipliers[unit] || 1);
}

function renderServers(servers) {
    const container = document.getElementById('ztop-servers');
    if (!container) return;
    if (!servers || servers.length === 0) {
        container.innerHTML = '';
        return;
    }
    container.innerHTML = '';

    const header = document.createElement('div');
    header.className = 'ztop-server-header';
    header.innerHTML = `
        <div>R</div>
        <div>Host</div>
        <div>CPU</div>
        <div>Load</div>
        <div>Memory</div>
        <div>Redis</div>
        <div>MongoDB</div>
    `;
    container.appendChild(header);

    servers.forEach((server) => {
        const parsed = parseServerLine(server.line || '');
        const hostKey = server.host || 'unknown';
        if (!ztopState.serverSeries[hostKey]) {
            ztopState.serverSeries[hostKey] = { cpu: [], load: [], mem: [], redis: [], mongo: [] };
        }
        if (parsed && parsed.cpuValue !== null) {
            ztopState.serverSeries[hostKey].cpu.push(parsed.cpuValue);
        }
        if (parsed && parsed.loadValue !== null) {
            ztopState.serverSeries[hostKey].load.push(parsed.loadValue);
        }
        if (parsed && parsed.memUsed !== null && parsed.memTotal) {
            const percent = (parsed.memUsed / parsed.memTotal) * 100;
            ztopState.serverSeries[hostKey].mem.push(percent);
        }
        if (parsed && parsed.redisValue !== null) {
            ztopState.serverSeries[hostKey].redis.push(parsed.redisValue);
        }
        if (parsed && parsed.mongoValue !== null) {
            ztopState.serverSeries[hostKey].mongo.push(parsed.mongoValue);
        }

        ['cpu', 'load', 'mem', 'redis', 'mongo'].forEach((key) => {
            const series = ztopState.serverSeries[hostKey][key];
            if (series.length > ztopState.maxPoints) series.shift();
        });

        const row = document.createElement('div');
        row.className = 'ztop-server-row';
        row.innerHTML = `
            <div class="ztop-server-role">${parsed ? parsed.role : ''}</div>
            <div class="ztop-server-host">${server.host || '--'}</div>
            <div class="ztop-server-metric-graph">
                <canvas class="ztop-server-sparkline" data-series="cpu"></canvas>
                <span class="ztop-server-metric">${parsed ? parsed.cpu : '--'}</span>
            </div>
            <div class="ztop-server-metric-graph">
                <canvas class="ztop-server-sparkline" data-series="load"></canvas>
                <span class="ztop-server-metric">${parsed ? parsed.load : '--'}</span>
            </div>
            <div class="ztop-server-metric-graph">
                <canvas class="ztop-server-sparkline" data-series="mem"></canvas>
                <span class="ztop-server-metric">${parsed ? parsed.memory : '--'}</span>
            </div>
            <div class="ztop-server-metric-graph">
                <canvas class="ztop-server-sparkline" data-series="redis"></canvas>
                <span class="ztop-server-metric">${parsed ? parsed.redis : '--'}</span>
            </div>
            <div class="ztop-server-metric-graph">
                <canvas class="ztop-server-sparkline" data-series="mongo"></canvas>
                <span class="ztop-server-metric">${parsed ? parsed.mongo : '--'}</span>
            </div>
        `;
        container.appendChild(row);

        row.querySelectorAll('.ztop-server-sparkline').forEach((canvas) => {
            const key = canvas.getAttribute('data-series');
            const series = ztopState.serverSeries[hostKey][key] || [];
            const color = key === 'cpu'
                ? '#67c3ff'
                : key === 'load'
                    ? '#f6b26b'
                    : key === 'mem'
                        ? '#7ce07c'
                        : key === 'redis'
                            ? '#c89bff'
                            : '#ffb6c1';
            renderSparkline(canvas, series, color);
        });
    });
}

function addText(parent, className, text) {
    const el = document.createElement('div');
    if (className) el.className = className;
    el.textContent = text;
    parent.appendChild(el);
    return el;
}

function codeColor(code, index) {
    const status = Number(code);
    if (status === 200) return '#7ce07c';
    if (status === 304) return '#67c3ff';
    if (status >= 400 && status < 500) return '#f6b26b';
    if (status >= 500 || status === 0) return '#ff7b7b';
    const colors = ['#c89bff', '#ffb6c1', '#f9d66b', '#64d8cb'];
    return colors[index % colors.length];
}

function esiCodeSort(a, b) {
    const aNum = Number(a);
    const bNum = Number(b);
    if (a === '200') return -1;
    if (b === '200') return 1;
    if (a === '304') return -1;
    if (b === '304') return 1;
    if (!Number.isNaN(aNum) && !Number.isNaN(bNum)) return aNum - bNum;
    return String(a).localeCompare(String(b));
}

function updateEsiBucketSeries(bucketName, codes) {
    if (!ztopState.esiBucketSeries[bucketName]) ztopState.esiBucketSeries[bucketName] = {};
    const series = ztopState.esiBucketSeries[bucketName];
    const counts = {};

    codes.forEach((code) => {
        counts[String(code.code)] = Number(code.count) || 0;
    });

    const codeSet = { 200: true, 304: true };
    Object.keys(series).forEach((code) => { codeSet[code] = true; });
    Object.keys(counts).forEach((code) => { codeSet[code] = true; });
    const trackedCodes = Object.keys(codeSet).sort(esiCodeSort);

    trackedCodes.forEach((code) => {
        if (!series[code]) series[code] = [];
        series[code].push(counts[code] || 0);
        if (series[code].length > ztopState.maxPoints) series[code].shift();
    });

    return trackedCodes.filter((code) => code === '200' || code === '304' || (series[code] || []).some((value) => value > 0));
}

function renderEsiBuckets(buckets) {
    const container = document.getElementById('ztop-esi-buckets');
    if (!container) return;
    container.innerHTML = '';
    addText(container, 'ztop-group-title', 'ESI outbound calls');

    if (!Array.isArray(buckets) || buckets.length === 0) {
        addText(container, 'ztop-empty', 'Waiting for ESI telemetry...');
        return;
    }

    const grid = document.createElement('div');
    grid.className = 'ztop-esi-grid';
    container.appendChild(grid);

    buckets.slice().sort((a, b) => {
        return String(a.bucket || '').localeCompare(String(b.bucket || ''), undefined, { sensitivity: 'base' });
    }).forEach((bucket) => {
        const last = bucket.last || {};
        const card = document.createElement('div');
        card.className = 'ztop-esi-card';
        card.title = `${bucket.total || 0} calls, ${bucket.success || 0} ok, ${bucket.failure || 0} fail, ${bucket.token_cost || 0} tokens`;

        const top = document.createElement('div');
        top.className = 'ztop-esi-card-top';
        const bucketName = bucket.bucket || '--';
        addText(top, 'ztop-esi-name', bucketName);
        if (last.remaining !== undefined || last.limit !== undefined) {
            let limit = last.limit || '?';
            let time = '';
            const limitParts = String(limit).split('/');
            if (limitParts.length === 2) {
                limit = limitParts[0];
                time = ` (${limitParts[1]})`;
            }
            addText(top, 'ztop-esi-bucket-values', `${last.remaining || '?'} / ${limit}${time}`);
        } else if (last.error_remain !== undefined) {
            const reset = last.error_reset ? ` (${last.error_reset}s)` : '';
            addText(top, 'ztop-esi-bucket-values', `${last.error_remain} errors remaining${reset}`);
        }
        card.appendChild(top);

        const codes = Array.isArray(bucket.codes) ? bucket.codes : [];
        const chartCell = document.createElement('div');
        chartCell.className = 'ztop-esi-chart-cell';
        const chart = document.createElement('canvas');
        chart.className = 'ztop-esi-chart';
        chartCell.appendChild(chart);
        const chartCodes = updateEsiBucketSeries(bucketName, codes);
        const legend = document.createElement('div');
        legend.className = 'ztop-esi-legend';
        const codeCounts = {};
        codes.forEach((code) => {
            codeCounts[String(code.code)] = Number(code.count) || 0;
        });
        chartCodes.forEach((code, index) => {
            const label = addText(legend, 'ztop-esi-legend-item', `${code}: ${codeCounts[code] || 0}`);
            label.style.color = codeColor(code, index);
        });
        chartCell.appendChild(legend);
        card.appendChild(chartCell);

        grid.appendChild(card);
        renderMultiSparkline(chart, ztopState.esiBucketSeries[bucketName], chartCodes);
    });
}

function renderGroups(metrics) {
    if (metrics && metrics.length > 0) {
        ztopState.lastMetrics = metrics;
    }
    const effectiveMetrics = ztopState.lastMetrics;
    if (!effectiveMetrics || effectiveMetrics.length === 0) return;

    const container = document.getElementById('ztop-groups');
    if (!container) return;
    container.innerHTML = '';

    const groupTitles = {
        1: 'Queues',
        2: 'Killmails',
        3: 'Analytics',
        4: 'Scopes'
    };

    const groups = {};
    const ensureGroup = (index) => {
        if (!groups[index]) groups[index] = { left: [], right: [] };
    };

    let groupIndex = 1;
    ensureGroup(groupIndex);

    effectiveMetrics.forEach((metric) => {
        if (!metric || metric.type === 'separator' || metric.text === '') {
            groupIndex += 1;
            ensureGroup(groupIndex);
            return;
        }
        const card = document.createElement('div');
        card.className = 'ztop-card';
        const title = document.createElement('div');
        title.className = 'ztop-card-title';
        title.textContent = metric.text;
        const body = document.createElement('div');
        body.className = 'ztop-card-body';
        const valueWrap = document.createElement('div');
        const value = document.createElement('span');
        value.className = 'ztop-card-value';
        value.textContent = metric.num || metric.raw || '--';
        const delta = document.createElement('span');
        delta.className = 'ztop-card-delta';
        updateDelta(delta, metric.delta);
        valueWrap.appendChild(value);
        valueWrap.appendChild(delta);
        const canvas = document.createElement('canvas');
        canvas.className = 'ztop-sparkline';
        body.appendChild(valueWrap);
        body.appendChild(canvas);
        card.appendChild(title);
        card.appendChild(body);

        const seriesKey = metric.text;
        const series = ztopState.metricSeries[seriesKey] || [];
        renderSparkline(canvas, series, metric.left ? '#67c3ff' : '#f6b26b');

        let targetIndex = groupIndex;
        if (targetIndex === 5) targetIndex = 4;
        if (targetIndex > 4) return;
        ensureGroup(targetIndex);

        if (metric.left) groups[targetIndex].left.push(card);
        else groups[targetIndex].right.push(card);
    });

    [2, 1, 4, 3].forEach((index) => {
        const groupData = groups[index];
        if (!groupData || (!groupData.left.length && !groupData.right.length)) return;
        const group = document.createElement('div');
        group.className = 'ztop-group';
        const title = document.createElement('div');
        title.className = 'ztop-group-title';
        title.textContent = groupTitles[index] || `Group ${index}`;
        group.appendChild(title);
        const cols = document.createElement('div');
        cols.className = 'ztop-columns';

        const maxLen = Math.max(groupData.left.length, groupData.right.length);
        for (let i = 0; i < maxLen; i++) {
            if (groupData.left[i]) cols.appendChild(groupData.left[i]);
            if (groupData.right[i]) cols.appendChild(groupData.right[i]);
        }

        group.appendChild(cols);
        container.appendChild(group);
    });
}

window.ztopUpdate = function(payload) {
    if (!payload) return;
    renderServers(payload.servers || []);
    renderEsiBuckets(payload.esiBuckets || []);
    const metrics = Array.isArray(payload.metrics) ? payload.metrics : [];
    metrics.forEach((metric) => {
        if (!metric || metric.type !== 'metric') return;
        const value = toNumber(metric.raw ?? metric.num);
        if (value === null) return;
        pushSeries(metric.text, value);
    });
    renderGroups(metrics);

    const lastUpdate = document.getElementById('ztop-last-update');
    if (lastUpdate) lastUpdate.textContent = `Last update: ${new Date().toLocaleString()}`;
    const connection = document.getElementById('ztop-connection');
    if (connection) {
        connection.textContent = 'Live';
        connection.classList.add('is-live');
        connection.classList.remove('is-offline');
    }
    ztopState.lastUpdateAt = Date.now();
};

if (!window.zkbZtopConnectionInterval) {
    window.zkbZtopConnectionInterval = setInterval(() => {
        const connection = document.getElementById('ztop-connection');
        if (!connection || !window.ztopState || !window.ztopState.lastUpdateAt) return;
        const seconds = (Date.now() - window.ztopState.lastUpdateAt) / 1000;
        if (seconds > 15) {
            connection.textContent = 'Disconnected';
            connection.classList.remove('is-live');
            connection.classList.add('is-offline');
        }
    }, 3000);
}

window.zkbPageCleanup = function () {
    clearTimeout(window.zkbZtopLoadTimeout);
    clearInterval(window.zkbZtopConnectionInterval);
    window.zkbZtopLoadTimeout = undefined;
    window.zkbZtopConnectionInterval = undefined;
    window.ztopState = undefined;
};
