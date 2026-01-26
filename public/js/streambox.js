let current_timeout = 0;
document.addEventListener('DOMContentLoaded', init);
const path = window.location.pathname.replace(/streambox\//, '');

async function init() {
    document.getElementById('pathname').textContent = path;
    fetchKills();
}

async function fetchKills() {
    try {
        const response = await fetch('/cache/tagged/killlist/?u=' + path);

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        prepKills(await response.json());
    } catch (error) {
        document.getElementById('content').innerHTML = 'Failed to load kill list :(';
        console.error('Error fetching kill list:', error);
    }
}

async function prepKills(data) {
    const our_id = window.location.pathname.split('/')[2];
    const unixtime = Math.floor(Date.now() / 1000); // This is always UTC
    const afterEpoch = unixtime - (3600 * 8); // show kills 8 hours old or less

    try {
        const content = document.getElementById('contenttemp');
        content.innerHTML = '';
        const temp = document.getElementById('temp');

        for (let i = 0; i < data.length && i < 25; i++) {
            const killID = data[i];

            let res = await fetch(`/cache/24hour/killlistrow/${killID}/`);
            let html = await res.text();

            temp.innerHTML = html;
            let info = document.querySelector('#temp tr.kltbd');
            let vics = info.getAttribute('vics');
            let epoch = Number(info.getAttribute('date'));
            if (epoch < afterEpoch) break; // the rest of the kills are older, we're all done here
            let isVictim = vics.split(',').indexOf(our_id) >= 0;

            const el = temp.querySelector('span[format="format-isk-once"]');
            const raw = el.getAttribute('raw');
            const value = formatISK(Number(raw));

            const image = temp.querySelector('span.shipImageSpan');

            const clone = image.cloneNode(true);

            const wrapper = document.createElement('div');
            wrapper.style.display = "inline-block";
            wrapper.style.textAlign = "center";

            wrapper.appendChild(clone);

            const valueNode = document.createElement('div');
            valueNode.textContent = value;
            valueNode.classList.add(isVictim ? 'lost' : 'killed');
            wrapper.appendChild(valueNode);

            content.appendChild(wrapper);
        }
        if (document.getElementById('contenttemp').innerHTML != document.getElementById('content').innerHTML) {
            console.log('updating streambox');
            document.getElementById('content').innerHTML = document.getElementById('contenttemp').innerHTML;
        }
    } finally {
        temp.innerHTML = '';
        clearTimeout(current_timeout);
        current_timeout = setTimeout(fetchKills, 60000);
		document.getElementById('promoLong').classList.remove('hideme');
		document.getElementById('promoShort').classList.remove('hideme');
    }
}


const formatIskIndex = ['', 'k', 'm', 'b', 't', 'k t', 'm t', 'b t'];
function formatISK(value, decimals = 1) {
    if (value < 10000) return value.toLocaleString();
    let i = 0;
    while (value > 999.99) {
        value = value / 1000;
        i++;
    }
    return value.toLocaleString(undefined, {minimumFractionDigits: decimals, maximumFractionDigits: decimals}) + formatIskIndex [i];
}
