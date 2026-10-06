let root = null;
const marketDataSource = '/market/data/';
const esi = 'https://esi.evetech.net/latest';
const state = {
    itemID: 44992,
    regions: [],
    regionNames: new Map(),
    systemRegions: new Map(),
    structures: {},
    items: [],
    itemElements: new Map(),
    controller: null,
    events: null,
    timers: new Map(),
    regionOrders: new Map(),
    orders: new Map()
};

const byID = id => document.getElementById('market-' + id);
const formatInt = value => Number(value).toLocaleString();
const formatPrice = value => Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function status(message) {
    byID('status').textContent = message;
}

async function getJSON(url, signal) {
    const response = await fetch(url, { signal, headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Request failed (' + response.status + ')');
    return response.json();
}

function createGroup(parent, groups) {
    Object.keys(groups || {}).sort().forEach(name => {
        const group = groups[name];
        const wrapper = document.createElement('div');
        wrapper.className = 'market-group';
        const button = document.createElement('button');
        button.className = 'btn btn-sm btn-link w-100 text-decoration-none';
        button.type = 'button';
        button.textContent = name;
        button.setAttribute('aria-expanded', 'false');
        const children = document.createElement('div');
        children.className = 'market-group-children d-none';
        button.addEventListener('click', () => {
            if (byID('search').value) {
                byID('search').value = '';
                searchItems();
            }
            const open = children.classList.toggle('d-none') === false;
            button.setAttribute('aria-expanded', String(open));
        });
        wrapper.append(button, children);
        createGroup(children, group.subgroups);
        Object.keys(group.items || {}).sort().forEach(itemName => {
            const item = group.items[itemName];
            const link = document.createElement('button');
            link.className = 'market-item d-block w-100 border-0';
            link.type = 'button';
            link.textContent = item.name;
            link.dataset.name = item.name.toLowerCase();
            link.dataset.itemId = item.item_id;
            link.dataset.categoryId = item.category_id;
            link.dataset.url = '/market/' + item.item_id + '/';
            link.addEventListener('click', event => {
                event.preventDefault();
                history.pushState({}, '', link.dataset.url);
                loadItem(item.item_id);
                root.classList.remove('market-show-search');
            });
            state.items.push(link);
            state.itemElements.set(Number(item.item_id), link);
            children.append(link);
        });
        parent.append(wrapper);
    });
}

function searchItems() {
    const query = byID('search').value.trim().toLowerCase();
    let matches = 0;
    document.querySelector('.market-item-keyboard')?.classList.remove('market-item-keyboard');
    state.items.forEach(item => {
        const match = !query || item.dataset.name.includes(query);
        item.classList.toggle('d-none', !match);
        if (match) {
            matches++;
            if (query) {
                let parent = item.parentElement;
                while (parent && parent !== byID('tree')) {
                    if (parent.classList.contains('market-group-children')) parent.classList.remove('d-none');
                    parent = parent.parentElement;
                }
            }
        }
    });
    byID('tree').querySelectorAll('.market-group').forEach(group => {
        group.classList.toggle('d-none', !!query && !group.querySelector('.market-item:not(.d-none)'));
    });
    byID('tree').classList.toggle('market-searching', !!query);
    const message = byID('search-message');
    message.textContent = query && !matches ? 'No results' : (query && query.length < 3 ? 'Type at least 3 characters for a narrower search.' : '');
    message.classList.toggle('d-none', !message.textContent);
    const exact = state.items.find(item => item.dataset.name === query);
    if (exact) {
        history.pushState({}, '', exact.dataset.url);
        loadItem(exact.dataset.itemId);
        const tree = byID('tree');
        const itemBox = exact.getBoundingClientRect();
        const treeBox = tree.getBoundingClientRect();
        tree.scrollTo({ top: tree.scrollTop + itemBox.top - treeBox.top - tree.clientHeight / 2, behavior: 'smooth' });
    }
}

function moveSearchSelection(direction) {
    const items = state.items.filter(item => !item.classList.contains('d-none') && item.offsetParent !== null);
    if (!items.length) return false;
    const current = items.indexOf(document.querySelector('.market-item-keyboard'));
    const index = current < 0 ? (direction > 0 ? 0 : items.length - 1) : Math.max(0, Math.min(items.length - 1, current + direction));
    document.querySelector('.market-item-keyboard')?.classList.remove('market-item-keyboard');
    items[index].classList.add('market-item-keyboard');
    items[index].scrollIntoView({ block: 'nearest' });
    return true;
}

function openSearchSelection() {
    const item = document.querySelector('.market-item-keyboard');
    if (!item) return false;
    item.click();
    return true;
}

function populateRegions() {
    const select = byID('region');
    state.regions.sort((a, b) => a.name.localeCompare(b.name)).forEach(region => {
        const option = document.createElement('option');
        option.value = region.id;
        option.textContent = region.name;
        select.append(option);
    });
}

async function locationName(order) {
    const cacheKey = 'market-location-' + order.location_id;
    const cached = localStorage.getItem(cacheKey);
    if (cached) return cached;
    let name = state.structures[order.location_id];
    if (!name && order.location_id <= 69999999) {
        try { name = (await getJSON(esi + '/universe/stations/' + order.location_id + '/')).name; } catch (error) { name = null; }
    }
    if (!name) {
        try { name = (await getJSON(esi + '/universe/systems/' + order.system_id + '/')).name + ' Structure'; } catch (error) { name = String(order.location_id); }
    }
    localStorage.setItem(cacheKey, name);
    return name;
}

async function regionName(order) {
    let regionID = order.market_region_id;
    if (!regionID || regionID === 19000001) {
        const cacheKey = 'market-system-' + order.system_id;
        regionID = state.systemRegions.get(order.system_id) || Number(localStorage.getItem(cacheKey));
        if (!regionID) {
            regionID = (async () => {
                const systemData = await getJSON(esi + '/universe/systems/' + order.system_id + '/');
                const constellation = await getJSON(esi + '/universe/constellations/' + systemData.constellation_id + '/');
                localStorage.setItem(cacheKey, String(constellation.region_id));
                state.systemRegions.set(order.system_id, constellation.region_id);
                return constellation.region_id;
            })();
            state.systemRegions.set(order.system_id, regionID);
        }
        try { regionID = await regionID; }
        catch (error) {
            state.systemRegions.delete(order.system_id);
            return '';
        }
    }
    return state.regionNames.get(Number(regionID)) || '';
}

function orderNode(order, inserted = false) {
    const row = document.createElement('div');
    row.className = 'market-order' + (inserted ? ' market-order-new' : '');
    row.dataset.orderId = order.order_id;
    row.dataset.price = Math.round(order.price * 100);
    row.dataset.regionId = order.market_region_id;
    const volume = document.createElement('span');
    volume.className = 'text-end';
    volume.textContent = formatInt(order.volume_remain);
    const price = document.createElement('span');
    price.className = 'text-end';
    price.textContent = formatPrice(order.price);
    const location = document.createElement('span');
    location.className = 'market-order-location';
    const locationText = document.createElement('span');
    locationText.className = 'market-order-location-name';
    locationText.textContent = 'Loading…';
    const locationRegion = document.createElement('small');
    locationRegion.className = 'market-order-region';
    location.append(locationText, locationRegion);
    locationName(order).then(name => {
        locationText.textContent = name;
    });
    regionName(order).then(name => { locationRegion.textContent = name; });
    switch (Number(order.location_id)) {
        case 60003760: location.classList.add('market-hub-jita'); break;
        case 60008494: location.classList.add('market-hub-amarr'); break;
        case 60011866: location.classList.add('market-hub-dodixie'); break;
        case 60004588: location.classList.add('market-hub-rens'); break;
        case 60015140: location.classList.add('market-hub-hek'); break;
    }
    const range = document.createElement('span');
    range.className = 'text-end text-capitalize';
    range.textContent = order.type_id === 44992 ? 'universe' : (order.range === 'solarsystem' ? 'system' : order.range);
    row.append(volume, price, location, range);
    return row;
}

function clearOrders() {
    byID('sell-orders').replaceChildren();
    byID('buy-orders').replaceChildren();
    state.orders = new Map();
}

function clearPolls() {
    state.timers.forEach(clearTimeout);
    state.timers.clear();
}

function setScrollLocked(locked) {
    if (locked) window.scrollTo(0, 0);
    document.documentElement.classList.toggle('market-page-locked', locked);
    const button = byID('scroll-lock');
    button.setAttribute('aria-pressed', String(locked));
    button.setAttribute('aria-label', locked ? 'Unlock page scrolling' : 'Lock page scrolling');
    button.title = locked ? 'Unlock page scrolling' : 'Lock page scrolling';
    button.querySelector('i').classList.toggle('fa-lock', locked);
    button.querySelector('i').classList.toggle('fa-lock-open', !locked);
}

function renderOrders(orders, refresh, refreshedRegionID) {
    const previous = state.orders;
    const sell = orders.filter(order => !order.is_buy_order && order.min_volume === 1).sort((a, b) => a.price - b.price || b.order_id - a.order_id);
    const buy = orders.filter(order => order.is_buy_order && order.min_volume === 1).sort((a, b) => b.price - a.price || b.order_id - a.order_id);
    const current = new Map([...sell, ...buy].map(order => [order.order_id, order]));
    if (!refresh) {
        [
            [byID('sell-orders'), sell],
            [byID('buy-orders'), buy]
        ].forEach(([container, rows]) => rows.forEach(order => {
            const row = container.querySelector('[data-order-id="' + order.order_id + '"]') || orderNode(order);
            container.append(row);
        }));
    } else {
        document.querySelectorAll('.market-order[data-region-id="' + refreshedRegionID + '"]').forEach(row => {
            row.classList.remove('market-order-new');
            row.querySelectorAll('.market-order-changed').forEach(element => element.classList.remove('market-order-changed'));
        });
        [
            [byID('sell-orders'), sell],
            [byID('buy-orders'), buy]
        ].forEach(([container, rows]) => rows.forEach(order => {
            let row = container.querySelector('[data-order-id="' + order.order_id + '"]');
            if (!row) row = orderNode(order, true);
            const old = previous.get(order.order_id);
            if (old && old.volume_remain !== order.volume_remain) {
                row.children[0].textContent = formatInt(order.volume_remain);
                row.children[0].classList.add('market-order-changed');
            }
            if (old && old.price !== order.price) {
                row.dataset.price = Math.round(order.price * 100);
                row.children[1].textContent = formatPrice(order.price);
                row.children[1].classList.add('market-order-changed');
            }
            container.append(row);
        }));
        previous.forEach((order, orderID) => {
            if (current.has(orderID)) return;
            const row = document.querySelector('.market-order[data-order-id="' + orderID + '"]');
            if (!row) return;
            row.classList.remove('market-order-new');
            row.classList.add('market-order-removed');
            row.children[0].textContent = '0';
            setTimeout(() => row.remove(), 5000);
        });
    }
    state.orders = current;
}

async function fetchRegionOrders(regionID, itemID, signal, onPage) {
    const orders = [];
    let expires = '';
    for (let page = 1; ; page++) {
        const response = await fetch(esi + '/markets/' + regionID + '/orders/?order_type=all&page=' + page + '&type_id=' + itemID, { signal, headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Request failed (' + response.status + ')');
        const rows = await response.json();
        rows.forEach(order => { order.market_region_id = regionID; });
        const pageExpires = response.headers.get('expires') || '';
        if (!expires || Date.parse(pageExpires) > Date.parse(expires)) expires = pageExpires;
        orders.push(...rows);
        onPage?.(rows);
        if (rows.length < 1000) break;
    }
    return { orders, expires };
}

function scheduleRegionPoll(regionID, itemID, expires, controller) {
    const expiry = Date.parse(expires);
    const delay = Number.isNaN(expiry) ? 301000 : Math.max(3000, expiry + 3000 - Date.now());
    clearTimeout(state.timers.get(regionID));
    state.timers.set(regionID, setTimeout(() => pollRegion(regionID, itemID, controller), delay));
}

async function pollRegion(regionID, itemID, controller) {
    if (controller.signal.aborted || state.controller !== controller || state.itemID !== itemID) return;
    state.timers.delete(regionID);
    try {
        const result = await fetchRegionOrders(regionID, itemID, controller.signal);
        if (controller.signal.aborted || state.controller !== controller || state.itemID !== itemID) return;
        state.regionOrders.set(regionID, result.orders);
        const orders = Array.from(state.regionOrders.values()).flat();
        renderOrders(orders, true, regionID);
        status(formatInt(orders.length) + ' orders');
        scheduleRegionPoll(regionID, itemID, result.expires, controller);
    } catch (error) {
        if (error.name !== 'AbortError' && state.controller === controller) scheduleRegionPoll(regionID, itemID, '', controller);
    }
}

async function loadOrders() {
    clearPolls();
    state.controller?.abort();
    clearOrders();
    const controller = new AbortController();
    state.controller = controller;
    state.regionOrders = new Map();
    const itemID = state.itemID;
    const selected = byID('region').value;
    const regions = state.itemID === 44992 ? [19000001] : (selected === 'all' ? state.regions.map(region => region.id) : [Number(selected)]);
    status('Loading orders from ' + regions.length + ' region' + (regions.length === 1 ? '' : 's') + '…');
    const orders = [];
    let remaining = regions.length;
    let cursor = 0;
    async function worker() {
        while (cursor < regions.length) {
            const region = regions[cursor++];
            try {
                const result = await fetchRegionOrders(region, itemID, controller.signal, rows => {
                    orders.push(...rows);
                    renderOrders(orders, false);
                });
                state.regionOrders.set(region, result.orders);
                scheduleRegionPoll(region, itemID, result.expires, controller);
            }
            catch (error) {
                if (error.name === 'AbortError') throw error;
                scheduleRegionPoll(region, itemID, '', controller);
            }
            finally {
                if (!controller.signal.aborted && state.controller === controller) {
                    remaining--;
                    status('Loading orders from ' + remaining + ' region' + (remaining === 1 ? '' : 's') + '…');
                }
            }
        }
    }
    try {
        await Promise.all(Array.from({ length: Math.min(8, regions.length) }, worker));
        status(formatInt(orders.length) + ' orders');
    } catch (error) {
        if (error.name !== 'AbortError') status('Unable to load market orders.');
    }
}

async function loadItem(itemID) {
    const selectedItemID = Number(itemID) || 44992;
    state.itemID = selectedItemID;
    clearPolls();
    state.controller?.abort();
    state.controller = null;
    clearOrders();
    byID('item-image').onerror = null;
    byID('item-image').src = '/img/transparent_32.png';
    byID('item-image').alt = '';
    state.itemElements.forEach(item => item.classList.remove('market-item-selected'));
    state.itemElements.get(state.itemID)?.classList.add('market-item-selected');
    status('Loading item…');
    try {
        const item = await getJSON(esi + '/universe/types/' + selectedItemID + '/');
        if (state.itemID !== selectedItemID) return;
        byID('item-name').textContent = item.name;
        const itemPath = Number(state.itemElements.get(selectedItemID)?.dataset.categoryId) === 6 ? '/ship/' : '/item/';
        byID('item-name').href = itemPath + selectedItemID + '/';
        byID('item-image-link').href = itemPath + selectedItemID + '/';
        const image = byID('item-image');
        image.alt = item.name;
        image.onerror = function() {
            this.onerror = function() {
                this.onerror = null;
                this.src = '/img/icons/' + selectedItemID + '_64.png';
            };
            this.src = 'https://images.evetech.net/types/' + selectedItemID + '/bp?size=64';
        };
        image.src = 'https://images.evetech.net/types/' + selectedItemID + '/icon?size=64';
        document.title = item.name + ' | EVEconomy | zKillboard';
        await loadOrders();
    } catch (error) { status('Unable to load item.'); }
}

async function init() {
    root = document.getElementById('market');
    if (!root || root.dataset.initialized === 'true') return;
    root.dataset.initialized = 'true';
    state.controller?.abort();
    state.events?.abort();
    clearPolls();
    state.itemID = Number(root.dataset.itemId) || 44992;
    state.items = [];
    state.itemElements = new Map();
    state.regionOrders = new Map();
    state.orders = new Map();
    state.events = new AbortController();
    window.zkbMarketMoveSelection = moveSearchSelection;
    window.zkbMarketOpenSelection = openSearchSelection;
    setScrollLocked(true);
    window.zkbPageCleanup = () => {
        state.controller?.abort();
        state.controller = null;
        state.events?.abort();
        clearPolls();
        document.documentElement.classList.remove('market-page-locked');
        delete window.zkbMarketMoveSelection;
        delete window.zkbMarketOpenSelection;
        delete root.dataset.initialized;
    };
    byID('search').addEventListener('input', searchItems, { signal: state.events.signal });
    byID('region').addEventListener('change', () => loadOrders(), { signal: state.events.signal });
    byID('show-search').addEventListener('click', () => root.classList.add('market-show-search'), { signal: state.events.signal });
    byID('show-orders').addEventListener('click', () => root.classList.remove('market-show-search'), { signal: state.events.signal });
    byID('scroll-lock').addEventListener('click', () => setScrollLocked(byID('scroll-lock').getAttribute('aria-pressed') !== 'true'), { signal: state.events.signal });
    window.addEventListener('popstate', () => loadItem(location.pathname.split('/')[2] || 44992), { signal: state.events.signal });
    try {
        const data = await getJSON(marketDataSource);
        state.structures = data.locations || {};
        state.regions = data.regions || [];
        state.regionNames = new Map(state.regions.map(region => [Number(region.id), region.name]));
        populateRegions();
        createGroup(byID('tree'), data.groups || {});
        await loadItem(state.itemID);
    } catch (error) {
        console.error(error);
        status('Unable to initialize EVE Market.');
    }
}

window.zkbInitMarket = init;
init();
