var ws;
var adblocked = undefined;
var zkbVersionCheckTimeout = null;
window.zkbFavorites = window.zkbFavorites || [];

window.onerror = function (message, source, lineno, colno, error) {
	console.error("Global error:", message, error);
};

function showModal(selector) {
    const modalEl = document.querySelector(selector);
    if (!modalEl) return;

    if (!window.bootstrap || !window.bootstrap.Modal) {
        console.error('Bootstrap Modal API unavailable for selector:', selector);
        return;
    }

    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInstance.show();
}

$(document).ready(function () {	
	setTime();

    refreshNavbarTracker();
    initSpaNavigation();
    scheduleVersionCheck();

    // autocomplete
    $('#searchbox').zz_search( function(data, event) { navigateTo('/' + data.type + '/' + data.id + '/'); event.preventDefault(); } );

    // prevent firing of window.location in table rows if a link is clicked directly
    $('.killListRow a').click(function(e) {
        e.stopPropagation();
    });


    // See if we are embedded in an iframe on another website perhaps?
    if (top !== self) {
        showModal('#iframed');
    }

    // Send that ESI URL to the parser, allows the website to parse the killmail so that
    // it is ready for the user when they click submit
    $("#killmailurl").bind('paste', function(event) {
        setTimeout(sendCrestUrl, 1);
    });
    $('#postExternalMail').on('click', pasteCrestUrl)

    addKillListClicks();

    $(document).on('keypress', checkForSearchKey);
    $('#dls-slider').on('change input', updateDLS);
    $('#dls-slider').on('click touchstart mousedown', stopPropagation);
    $('#login-delay-slider').on('change input', updateDLS);
    $('#login-delay-slider').on('click touchstart mousedown', stopPropagation);
    $(document).on('click', 'a[href^="/ccpoauth2/"]:not([href^="/ccpoauth2-"])', interceptLoginClick);
    $(document).on('submit', 'form[action^="/ccpoauth2/"]', interceptLoginSubmit);
    $(document).on('change', '#login-scope-all', loginScopeAllChange);
    $(document).on('change', '#loginOptionsModal .login-scope', syncLoginScopeAllCheckbox);
    $('#continueLoginWithOptions').on('click', continueLoginWithOptions);
    updateDLS.call($('#dls-slider'));

    // setup websocket with callbacks
    //if (start_websocket) startWebSocket();
    if (entityType != 'none') {
        statsboxUpdate({type: (entityType == 'label' ? entityType : entityType + "ID"), id: entityID});
    }

	try {
		$(".datatable").DataTable();
	} catch (e) {
		console.error("Failed to initialize datatables:", e);
	}

    // Prep comments, if the page has the function for them
    if (typeof prepComments === "function") prepComments();

    // For named anchors with the hrefit classname, make it a link as well
    $(".hrefit").each(function() {
        t = $(this);
        var anchorName = (t.attr('name') || '').replace(/[^A-Za-z0-9_\-:\.]/g, '');
        t.attr('href', '#' + anchorName);
    });
    loadFetchmeKillRows();
    setTimeout(fixCCPsBrokenImages, 1000);

    assignRowColor();
    doFormats();
    $(document).ajaxComplete(doFormats);

    // Anything that has a raw value to it will be able to be copied to the clipboard
	$("[raw]").click(copyToClipboard);
	
	$("label[for]").on("click", () => { $(window).focus(); })
	setTimeout(prepTippy, 1);
});

function initPageContent() {
    try {
        $(".datatable").not(".dataTable").DataTable();
    } catch (e) {
        console.error("Failed to initialize datatables:", e);
    }

    if (typeof prepComments === "function") prepComments();
    addKillListClicks();
    assignRowColor();
    doFormats();
    loadFetchmeKillRows();
    loadHomeKillListIfNeeded();
    applyFavoriteStars();
    $("[raw]").off("click.zkb-copy").on("click.zkb-copy", copyToClipboard);
    setTimeout(fixCCPsBrokenImages, 1000);
    setTimeout(prepTippy, 1);
}

function scheduleVersionCheck() {
    if (zkbVersionCheckTimeout) clearTimeout(zkbVersionCheckTimeout);

    const delay = (3000 + Math.floor(Math.random() * 1201)) * 1000;
    zkbVersionCheckTimeout = setTimeout(checkSiteVersion, delay);
}

async function checkSiteVersion() {
    try {
        const response = await fetch('/api/version/', {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!response.ok) throw new Error("Unexpected status " + response.status);

        const data = await response.json();
        if (data && data.version && typeof zkbVersion !== "undefined" && data.version !== zkbVersion) {
            window.location.reload();
            return;
        }
    } catch (error) {
        console.error("Version check failed:", error);
    }

    scheduleVersionCheck();
}

function refreshNavbarTracker() {
    if (!navbar) return;
    const trackerDropdown = $('#tracker-dropdown');
    if (trackerDropdown.length == 0) return;

    trackerDropdown.load('/navbar/');
}

function applyTrackerControls() {
    const trackerControls = $("#tracker-add, #tracker-none, [id^='tracker-remove-']");
    if (trackerControls.length == 0) return;

    trackerControls.addClass("d-none");

    const trackerState = window.zkbTrackerState;
    if (!trackerState || !trackerState.loggedIn) {
        $("#tracker-none").removeClass("d-none");
        return;
    }

    let showAdd = true;
    const tracked = trackerState.tracked || {};
    Object.keys(tracked).forEach(function(type) {
        (tracked[type] || []).forEach(function(id) {
            showAdd = showAdder(showAdd, type, id, trackerState.trackNotification);
        });
    });

    $("#tracker-remove-character-" + trackerState.characterID).remove();
    $("#tracker-remove-corporation-" + trackerState.corporationID).remove();
    if (trackerState.allianceID > 0) $("#tracker-remove-alliance-" + trackerState.allianceID).remove();

    if (showAdd) $("#tracker-add").removeClass("d-none");
}

function loadFetchmeKillRows() {
    $(".fetchme").each(function() {
        const row = $(this);
        if (row.attr("data-fetching") === "true") return;
        row.attr("data-fetching", "true");
        loadKillRow(row.attr("killID"));
    });
}

function loadHomeKillListIfNeeded() {
    if (window.location.pathname !== "/") return;
    if (!document.getElementById("kms_loading")) return;
    if (!document.getElementById("killmailstobdy")) return;

    fetch("/cache/tagged/killlist/?u=/")
        .then(response => {
            if (!response.ok) throw new Error("Unexpected status " + response.status);
            const contentType = response.headers.get("content-type") || "";
            if (!contentType.includes("application/json")) throw new Error("Unexpected content type " + contentType);
            return response.json();
        })
        .then(data => prepKills(data))
        .catch(error => console.error("Failed to load home kill list!", error));
}

let spaAbortController = null;
let spaScrollSaveTimeout = null;
let spaRenderedURL = window.location.href;
const spaLoadedScripts = new Set(Array.from(document.scripts)
    .map(script => script.src)
    .filter(Boolean)
    .map(normalizeAssetURL));
const spaLoadedStyles = new Set(Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
    .map(link => link.href)
    .filter(Boolean)
    .map(normalizeAssetURL));

function initSpaNavigation() {
    if (!window.history || !window.fetch || !window.DOMParser) return;

    if ("scrollRestoration" in window.history) window.history.scrollRestoration = "manual";
    window.history.replaceState(getSpaHistoryState(), document.title, window.location.href);

    $(document).on("click", "a[href]", function(event) {
        if (isAsearchDrillDownClick(this)) return;
        if (!shouldSpaNavigate(event, this)) return;

        event.preventDefault();
        spaNavigate(this.href, true);
    });

    window.addEventListener("popstate", function(event) {
        if (!event.state || !event.state.zkbSpa) return;
        if (isSpaRenderedURL(window.location.href)) return;
        spaNavigate(window.location.href, false, event.state);
    });

    window.addEventListener("scroll", scheduleSpaScrollSave, { passive: true });
}

function shouldSpaNavigate(event, anchor) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
    if (anchor.target && anchor.target !== "_self") return false;
    if (anchor.hasAttribute("download") || anchor.hasAttribute("onclick")) return false;
    if (anchor.getAttribute("data-bs-toggle") || anchor.getAttribute("data-spa") === "off") return false;

    const href = anchor.getAttribute("href") || "";
    if (href === "" || href === "#" || href.startsWith("#")) return false;
    if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;

    let url;
    try {
        url = new URL(anchor.href, window.location.href);
    } catch (e) {
        return false;
    }

    if (url.origin !== window.location.origin) return false;
    if (url.pathname === window.location.pathname && url.search === window.location.search) return false;
    if (url.hash && anchor.pathname === window.location.pathname && anchor.search === window.location.search) return false;
    if (!url.pathname.endsWith("/")) return false;
    if (/\.(?:css|js|json|xml|txt|ico|png|jpg|jpeg|gif|webp|svg|html)$/i.test(url.pathname)) return false;

    return !isSpaExcludedPath(url.pathname);
}

function isAsearchDrillDownClick(anchor) {
    return !!(
        document.getElementById("asearchcontent") &&
        anchor.closest("#clickablecontent") &&
        document.getElementById("clickToDigCheckbox") &&
        document.getElementById("clickToDigCheckbox").checked
    );
}

function isSpaExcludedPath(pathname) {
    const excludedPrefixes = [
        "/api/",
        "/cache/",
        "/account/logout/",
        "/account/tracker/",
        "/asearchsaved/",
        "/brsave/",
        "/ccp",
        "/ccpoauth2",
        "/crestmail/",
        "/logout/",
        "/navbar/",
        "/sponsor/"
    ];

    if (excludedPrefixes.some(prefix => pathname.startsWith(prefix))) return true;
    return /^\/kill\/\d+\/redirect\//.test(pathname);
}

async function spaNavigate(href, pushState, historyState) {
    const targetURL = new URL(href, window.location.href);
    if (pushState) saveSpaScrollPosition();

    if (spaAbortController) spaAbortController.abort();
    spaAbortController = new AbortController();

    try {
        const response = await fetch(targetURL.href, {
            method: "GET",
            credentials: "same-origin",
            headers: {
                "X-Requested-With": "XMLHttpRequest"
            },
            signal: spaAbortController.signal
        });

        if (!response.ok || response.redirected) return fullNavigate(targetURL.href);

        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, "text/html");
        const nextContent = doc.querySelector("#zkb-page-content");
        const currentContent = document.querySelector("#zkb-page-content");

        if (!nextContent || !currentContent) return fullNavigate(targetURL.href);

        const nextModals = doc.querySelector("#zkb-page-modals");
        const currentModals = document.querySelector("#zkb-page-modals");

        document.title = doc.title || document.title;
        updateHeadLink("canonical", doc);
        updateHeadMeta("description", doc);
        syncSpaHeadExtras(doc);
        await loadSpaHeadStylesheets(doc);

        if (pushState) {
            window.history.pushState(getSpaHistoryState({ scrollX: 0, scrollY: 0 }), document.title, targetURL.href);
        }

        clearSpaPageHelpers();
        currentContent.innerHTML = nextContent.innerHTML;
        if (nextModals && currentModals) currentModals.innerHTML = nextModals.innerHTML;
        syncPageGlobals(currentContent);
        syncPagePubsubs();
        const contentAssets = await loadSpaElementScripts(currentContent);
        const modalAssets = currentModals ? await loadSpaElementScripts(currentModals) : emptySpaAssetResult();
        const pageAssets = await loadSpaPageAssets(doc);

        initPageContent();
        applyTrackerControls();
        runSpaPageInitializers(mergeSpaAssetResults(contentAssets, modalAssets, pageAssets));
        restoreSpaScrollPosition(pushState ? null : historyState);
        spaRenderedURL = window.location.href;
        collapseMobileNav();
    } catch (e) {
        if (e.name === "AbortError") return;
        console.error("SPA navigation failed:", e);
        fullNavigate(targetURL.href);
    }
}

function getSpaHistoryState(overrides) {
    const currentState = window.history.state || {};
    return Object.assign({}, currentState, {
        zkbSpa: true,
        scrollX: window.scrollX || window.pageXOffset || 0,
        scrollY: window.scrollY || window.pageYOffset || 0
    }, overrides || {});
}

function saveSpaScrollPosition() {
    if (!window.history || !window.history.replaceState) return;
    const currentState = window.history.state;
    if (!currentState || !currentState.zkbSpa) return;
    window.history.replaceState(getSpaHistoryState(), document.title, window.location.href);
}

function scheduleSpaScrollSave() {
    if (spaScrollSaveTimeout) return;
    spaScrollSaveTimeout = setTimeout(function() {
        spaScrollSaveTimeout = null;
        saveSpaScrollPosition();
    }, 150);
}

function restoreSpaScrollPosition(historyState) {
    const left = historyState ? historyState.scrollX || 0 : 0;
    const top = historyState ? historyState.scrollY || 0 : 0;
    window.scrollTo({ top: top, left: left, behavior: "auto" });
}

function isSpaRenderedURL(href) {
    try {
        const rendered = new URL(spaRenderedURL, window.location.href);
        const current = new URL(href, window.location.href);
        return rendered.pathname === current.pathname && rendered.search === current.search;
    } catch (e) {
        return false;
    }
}

function updateHeadLink(rel, doc) {
    const next = doc.querySelector(`link[rel="${rel}"]`);
    let current = document.querySelector(`link[rel="${rel}"]`);
    if (!next) return;
    if (!current) {
        current = document.createElement("link");
        current.setAttribute("rel", rel);
        document.head.appendChild(current);
    }
    current.setAttribute("href", next.getAttribute("href"));
}

function updateHeadMeta(name, doc) {
    const next = doc.querySelector(`meta[name="${name}"]`);
    let current = document.querySelector(`meta[name="${name}"]`);
    if (!next) return;
    if (!current) {
        current = document.createElement("meta");
        current.setAttribute("name", name);
        document.head.appendChild(current);
    }
    current.setAttribute("content", next.getAttribute("content"));
}

function syncSpaHeadExtras(doc) {
    document.querySelectorAll("[data-spa-head-extra]").forEach(el => el.remove());

    doc.head.querySelectorAll("style").forEach(style => {
        const next = document.createElement("style");
        next.setAttribute("data-spa-head-extra", "style");
        next.textContent = style.textContent;
        document.head.appendChild(next);
    });

    const currentRefresh = document.querySelector('meta[http-equiv="refresh"]');
    if (currentRefresh) currentRefresh.remove();

    const nextRefresh = doc.querySelector('meta[http-equiv="refresh"]');
    if (nextRefresh) {
        const meta = document.createElement("meta");
        copyAttributes(nextRefresh, meta);
        meta.setAttribute("data-spa-head-extra", "refresh");
        document.head.appendChild(meta);
    }
}

function syncPageGlobals(content) {
    if (!content) return;
    window.entityType = content.getAttribute("data-entity-type") || "none";
    window.entityID = content.getAttribute("data-entity-id") || "0";
    window.entityPage = content.getAttribute("data-entity-page") || "";
    window.actualURI = content.getAttribute("data-actual-uri") || window.location.pathname;
}

function clearSpaPageHelpers() {
    if (typeof window.zkbPageCleanup === "function") {
        try {
            window.zkbPageCleanup();
        } catch (e) {
            console.error("SPA page cleanup failed:", e);
        }
    }
    window.zkbPageCleanup = undefined;
    window.prepComments = undefined;
    window.resizeMobileFittingWheel = undefined;
    window.loadRemainingPilots = undefined;
    window.load_ztop = undefined;
    window.ztopUpdate = undefined;
    window.ztopState = undefined;
}

async function loadSpaPageAssets(doc) {
    const assets = doc.querySelector("#zkb-page-scripts");
    const result = emptySpaAssetResult();
    if (!assets) return result;

    for (const link of assets.querySelectorAll('link[rel="stylesheet"]')) {
        await loadSpaStylesheet(link);
    }

    for (const script of assets.querySelectorAll("script")) {
        const loadedScript = await loadSpaScript(script);
        if (loadedScript === "inline") result.inline = true;
        else if (loadedScript && loadedScript.reused) result.reusedScripts.add(loadedScript.key);
        else if (loadedScript && loadedScript.key) result.loadedScripts.add(loadedScript.key);
    }

    return result;
}

async function loadSpaHeadStylesheets(doc) {
    for (const link of doc.head.querySelectorAll('link[rel="stylesheet"]')) {
        await loadSpaStylesheet(link);
    }
}

async function loadSpaElementScripts(root) {
    const result = emptySpaAssetResult();
    if (!root) return result;

    for (const script of Array.from(root.querySelectorAll("script"))) {
        const loadedScript = await loadSpaScript(script);
        if (loadedScript === "inline") result.inline = true;
        else if (loadedScript && loadedScript.reused) result.reusedScripts.add(loadedScript.key);
        else if (loadedScript && loadedScript.key) result.loadedScripts.add(loadedScript.key);
        script.remove();
    }

    return result;
}

function emptySpaAssetResult() {
    return { inline: false, loadedScripts: new Set(), reusedScripts: new Set() };
}

function mergeSpaAssetResults(...results) {
    const merged = emptySpaAssetResult();
    for (const result of results) {
        if (!result) continue;
        merged.inline = merged.inline || result.inline;
        for (const key of result.loadedScripts || []) merged.loadedScripts.add(key);
        for (const key of result.reusedScripts || []) merged.reusedScripts.add(key);
    }
    return merged;
}

function loadSpaStylesheet(link) {
    const href = link.href;
    const key = normalizeAssetURL(href);
    if (!href || spaLoadedStyles.has(key)) return Promise.resolve();

    return new Promise((resolve, reject) => {
        const next = document.createElement("link");
        copyAttributes(link, next);
        next.onload = () => {
            spaLoadedStyles.add(key);
            resolve();
        };
        next.onerror = reject;
        document.head.appendChild(next);
    });
}

function loadSpaScript(script) {
    if (script.src) {
        const key = normalizeAssetURL(script.src);
        if (spaLoadedScripts.has(key)) return Promise.resolve({ key, reused: true });

        return new Promise((resolve, reject) => {
            const next = document.createElement("script");
            copyAttributes(script, next);
            next.defer = false;
            next.async = false;
            next.onload = () => {
                spaLoadedScripts.add(key);
                resolve({ key, reused: false });
            };
            next.onerror = reject;
            document.body.appendChild(next);
        });
    }

    runInlinePageScript(script.textContent || "");
    return Promise.resolve("inline");
}

function copyAttributes(from, to) {
    for (const attr of from.attributes) {
        if (attr.name === "defer") continue;
        to.setAttribute(attr.name, attr.value);
    }
}

function normalizeInlinePageScript(scriptText) {
    const pageGlobals = [
        "killID",
        "pageID",
        "pageType",
        "ranksEpoch",
        "ranksGroup",
        "ranksHasMore",
        "ranksKL",
        "ranksPage",
        "ranksSortDir",
        "ranksSortKey",
        "ranksType",
        "rawBlock",
        "rawToggle",
        "ztopState"
    ].join("|");
    const pageGlobalDeclaration = new RegExp("^\\s*(?:const|let)\\s+(" + pageGlobals + ")\\s*=", "gm");

    return scriptText
        .replace(pageGlobalDeclaration, "window.$1 =")
        .replace(/^(\s*)function\s+(loadRemainingPilots|prepComments|resizeMobileFittingWheel|load_ztop|toNumber|updateDelta|pushSeries|renderSparkline|parseServerLine|sizeToMegabytes|renderServers|renderGroups)\s*\(/gm, "$1window.$2 = function(");
}

function runInlinePageScript(scriptText) {
    const normalizedScript = normalizeInlinePageScript(scriptText || "");
    const spaDocument = new Proxy(document, {
        get(target, prop) {
            if (prop === "addEventListener") {
                return function(type, listener, options) {
                    if (type === "DOMContentLoaded" && typeof listener === "function") {
                        listener.call(target, new Event("DOMContentLoaded"));
                        return;
                    }
                    return target.addEventListener(type, listener, options);
                };
            }

            const value = target[prop];
            return typeof value === "function" ? value.bind(target) : value;
        }
    });

    (new Function("document", normalizedScript))(spaDocument);
}

function normalizeAssetURL(url) {
    try {
        const parsed = new URL(url, window.location.href);
        parsed.search = "";
        parsed.hash = "";
        return parsed.href;
    } catch (e) {
        return url;
    }
}

function runSpaPageInitializers(pageAssets) {
    const reusedScripts = pageAssets && pageAssets.reusedScripts ? pageAssets.reusedScripts : new Set();
    if (typeof window.zkbInitOverview === "function" && document.querySelector("#killlist") && hasReusedSpaScript(reusedScripts, "/js/overview.js")) window.zkbInitOverview();
    if (typeof window.zkbInitTypeRanks === "function" && document.querySelector(".rank-table") && hasReusedSpaScript(reusedScripts, "/js/typeranks.js")) window.zkbInitTypeRanks();
    if (typeof window.zkbInitScanalyzer === "function" && document.querySelector("#scaninput") && hasReusedSpaScript(reusedScripts, "/js/scanalyzer.js")) window.zkbInitScanalyzer();
    if (typeof window.zkbInitAsearch === "function" && document.querySelector("#asearchcontent") && hasReusedSpaScript(reusedScripts, "/js/asearch.js")) window.zkbInitAsearch();
    if (typeof window.resizeMobileFittingWheel === "function") window.resizeMobileFittingWheel();
}

function hasReusedSpaScript(reusedScripts, pathname) {
    for (const scriptURL of reusedScripts) {
        try {
            if (new URL(scriptURL).pathname === pathname) return true;
        } catch (e) {
            if (scriptURL.includes(pathname)) return true;
        }
    }
    return false;
}

function collapseMobileNav() {
    const nav = document.getElementById("navbar-main");
    if (!nav || !window.bootstrap) return;
    const collapse = bootstrap.Collapse.getInstance(nav);
    if (collapse) collapse.hide();
}

function fullNavigate(href) {
    window.location.href = href;
}

function navigateTo(href) {
    let url;
    try {
        url = new URL(href, window.location.href);
    } catch (e) {
        return fullNavigate(href);
    }

    if (url.origin !== window.location.origin || isSpaExcludedPath(url.pathname) || !url.pathname.endsWith("/")) {
        return fullNavigate(url.href);
    }

    spaNavigate(url.href, true);
}

function prepTippy() {
	document.querySelectorAll('[rel="tooltip"], [title]:not([title=""])')
		.forEach(el => {
			// Skip empty titles or tooltips
			const content =
				el.getAttribute('tooltip') ||
				el.getAttribute('title');

			if (!content || content.trim() === '') return;

			// Prevent double-initialization
			if (el._tippy) return;

			// Remove native tooltip
			el.setAttribute('prev-title', content);
			el.removeAttribute('title');

			tippy(el, {
				content,
				allowHTML: true,
				delay: 250,
			});
		});
	setTimeout(prepTippy, 500);
}

function copyToClipboard(e) {
    console.log(this);
    const raw = $(this).attr("raw");
    console.log(raw);
    if (navigator.clipboard.writeText) {
        navigator.clipboard.writeText(raw);
        showToast(raw + ' has been copied to your clipboard');
    }
}

const asciiForwardSlash = '/'.charCodeAt(0);
const asciiBackSlash = '\\'.charCodeAt(0);

function checkForSearchKey(event) {
    if ($("input:focus, textarea:focus").length == 0) {
        if (event.which == asciiForwardSlash) {$("#searchbox").focus(); return false; }
        if (event.which == asciiBackSlash) {
            navigateTo('/asearch/');
            return false;
        }
    }
}

function startWebSocket() {
	try {
		if (ws) return;
		if (location.pathname != '/ztop/' && characterID == 0) return setTimeout(startWebSocket, 1000);

        addCurrentPagePubsubs();
        ws = new ReconnectingWebSocket((window.location.hostname == 'localhost' ? 'ws' : 'wss' ) + '://' + window.location.hostname + '/websocket/', '', {maxReconnectAttempts: 15});
        ws.onmessage = function(event) {
                wslog(event.data);
        };
        ws.onopen = function(event) {
            doSubs();
        };

        console.log('WebSocket connected');
    } catch (e) {
        setTimeout(startWebSocket, 100);
    }
}

const basePubsubs = ['public'];
let pubsubs = basePubsubs.slice();
let pubsubGeneration = 0;

function getCurrentPagePubsubs() {
    const channels = [];

    if (entityType != 'none') {
        var channel = entityType + ":" + entityID;
        if (entityPage != 'index' && entityPage != 'overview') channel = channel + ":" + entityPage;
        channels.push(channel);
        channels.push('stats:' + channel);
    }

    if (window.location.pathname == '/') channels.push('all:*');
    if (window.location.pathname == '/ztop/') channels.push('ztop');

    return channels;
}

function addPubsubChannel(channel) {
    if (channel && !pubsubs.includes(channel)) pubsubs.push(channel);
}

function addCurrentPagePubsubs() {
    getCurrentPagePubsubs().forEach(addPubsubChannel);
}

function syncPagePubsubs() {
    const previousPubsubs = pubsubs.slice();
    pubsubGeneration++;
    pubsubs = previousPubsubs.filter(isPersistentPubsub);
    basePubsubs.forEach(addPubsubChannel);
    addCurrentPagePubsubs();

    if (!ws) return;

    previousPubsubs
        .filter((channel) => !isPersistentPubsub(channel) && !pubsubs.includes(channel))
        .forEach((channel) => unpubsub(channel));

    pubsubs
        .filter((channel) => !previousPubsubs.includes(channel))
        .forEach((channel) => pubsub(channel, true));
}

function doSubs() {
    pubsubs.forEach((e) => { pubsub(e, true); });
}

function isPersistentPubsub(channel) {
    return basePubsubs.includes(channel) || channel.startsWith('tracker:');
}

function htmlNotify (data) 
{
    if (tn === false) return;
    if("Notification" in window) {
        if (Notification.permission !== 'denied' && Notification.permission !== "granted") {
            Notification.requestPermission(function (permission) {
                if (permission === 'granted') htmlNotify(data);
            });
            return;
        }
        if (Notification.permission === 'granted') {
            var notif = new Notification(data.title, {
                body: data.iskStr,
                icon: data.image,
                tag: data.url
            });
            setTimeout(function() { notif.close() }, 20000);
            notif.onclick = function () {
                notif.close();
                window.open(data.url).focus();
            };
        }
    }
}

function wslog(msg)
{
    if (msg === 'ping' || msg === 'pong') return;
    json = JSON.parse(msg);
    if (json.action === 'tqStatus') {
        setLiveCounter($('#lasthour'), json.kills);
        updateTqStatus(json.tqStatus, json.tqCount);
    } else if (json.action === 'reload') {
        console.log('Reload imminent in the next 5 minutes');
        setTimeout("location.reload(true);", Math.floor(1 + (Math.random() * 500000)));
    } else if (json.action === 'bigkill') {
        htmlNotify(json);
    } else if (json.action === 'lastHour') {
        setLiveCounter($('#lasthour'), json.kills);
    } else if (json.action === 'audio') {
        audio(json.uri);
    } else if (json.action === 'comment') {
        $("#commentblock").html(json.html);
    } else if (json.action === 'littlekill') {
        var killID = json.killID;
        setTimeout(function() { loadLittleMail(killID); }, Math.floor(Math.random() * 1000));    
    } else if (json.action == 'statsbox') {
        console.log(json);
        statsboxUpdate(json);
    } else if (json.action == 'message') {
        console.log(json);
        if (json.message.length > 0) $("#zkb-message").html("<center>" + json.message + "</center>").removeClass('d-none');
        else $("#zkb-message").html('').addClass('d-none');
    } else if (json.action == 'ztop') {
        if (json.payload && typeof window.ztopUpdate === 'function') {
            window.ztopUpdate(json.payload);
        } else {
            $("#ztoptextblock").text(json.message);
        }
    } else {
        console.log("Unknown action: " + json.action);
    }
}

function setLiveCounter(elem, value) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) elem.attr('raw', parsed);
    else elem.attr('raw', value == null ? '' : String(value));
    const formatted = Number.isFinite(parsed) ? parsed.toLocaleString() : String(value || '');
    doFieldUpdate(elem, formatted);
}

function loadLittleMail(killID) {
        // Add the killmail to the live feed kill list
        $.get("/cache/24hour/killlistrow/" + killID + "/", addLittleKill);
}

function loadKillRow(killID, retries = 0) {
        fetch("/cache/24hour/killlistrow/" + killID + "/", { credentials: 'same-origin' })
            .then(function(res) {
                if (!res.ok) throw new Error('Failed to load kill row');
                return res.text();
            })
            .then(function(data) {
                addKillRow(data, killID);
            })
            .catch(function() {
                retries++;
                if (retries < 3) setTimeout(loadKillRow.bind(null, killID, retries), 1000);
            });
}

function addKillRow(data, id) {
    const row = document.getElementById('kill-' + id);
    if (row) row.outerHTML = data;
    assignRowColor();
    adjustKillmailPresentation();
}

var dateFormatter = new Intl.DateTimeFormat(undefined, {dateStyle: 'long', timeZone: 'UTC' });
var longFormatter = new Intl.DateTimeFormat(undefined, {dateStyle: 'long', timeStyle: 'long', timeZone: 'UTC' });
function adjustKillmailPresentation() {
    // Remove excess killmails
    while (document.querySelectorAll('.tr-killmail').length > 50) {
        const rows = document.querySelectorAll('.tr-killmail');
        const lastRow = rows[rows.length - 1];
        if (!lastRow) break;
        lastRow.remove();
    }

    // Ensure the last row isn't a dangling date row
    const killmailsBody = document.getElementById('killmailstobdy');
    if (!killmailsBody) return;
    while (killmailsBody.lastElementChild && killmailsBody.lastElementChild.classList.contains('tr-date')) {
        killmailsBody.lastElementChild.remove();
    }

    // Keep only the first date marker per day. Hiding duplicates breaks table striping
    // because nth-of-type still counts hidden rows.
    let priorDate = undefined;
    document.querySelectorAll('.tr-date').forEach(function(row) {
        const date = row.getAttribute('date');
        if (date == priorDate) {
            row.remove();
            return;
        }
        priorDate = date;
    });
}

function prepKills(data) {
    const tbody = document.getElementById('killmailstobdy');
    if (!tbody || !Array.isArray(data)) return;

    for (let i = 0; i < data.length; i++) {
        const killID = data[i];
        const tr = document.createElement('tr');
        tr.id = 'kill-' + killID;
        tr.className = 'fetchme';
        tr.setAttribute('killID', killID);
        tbody.appendChild(tr);
        loadKillRow(killID);
    }

    const loading = document.getElementById('kms_loading');
    if (loading) loading.remove();
}

var killdata = undefined;
var killdata = undefined;
function addLittleKill(data) {
	const y = window.scrollY;

    var data = $(data);
    killdata = $(data);
    const row = data.filter('.tr-killmail').first();
    $("#killlist tbody tr").first().before(data);
    if (row.length > 0 && typeof window.playAmbientKillmailNote === 'function') window.playAmbientKillmailNote(row);

	// Keep the page from growing too much...
	while ($("#killlist tbody tr").length > 100) $("#killlist tbody tr:last").remove();
	// Tell the user what's going on and not to expect sequential killmails
	if ($("#livefeednotif").length == 0) {
		$("#killlist thead tr").after("<tr><td id='livefeednotif' colspan='7'><strong><em>Live feed - killmails may be out of order.</em></strong></td></tr>");
	}
	assignRowColor();
	adjustKillmailPresentation();

	// lets prevent the page from jumping around
	window.scrollTo(0, y);
}

/* This is currently not used, it is here as a proof of concept */
function addLittleKillInOrder(data) {
    let added = false;
    let lastrow = undefined;
    let rows = [].reverse.call($("#killmailstobdy tr"));
    data = $(data);
    data.hide();
    let killid = Number(data.attr('killid'));
    console.log('loading', killid);
    for (row of rows) {
        row = $(row);
        let curKillID = Number(row.attr('killid'));
        if (isNaN(curKillID)) continue;
        if (curKillID == killid) return; // we're already displaying this killmail
        if (curKillID > killid) {  row.after(data); break; }
        lastrow = row;
    }
    lastrow.before(data);
    setTimeout(() => { data.show('slow');}, 1);

    while ($("#killmailstobdy tr").length > 50) $("#killmailstobdy tr").last().remove()
}
function audio(uri)
{
    var audio = new Audio(uri);
    audio.volume = 0.1;
    audio.play();
}

function saveFitting(id) {
    $('#modalMessageBody').html('<div style="color: white;">Saving fit....</div>');
    showModal('#modalMessage');

    var request = $.ajax({
url: "/ccpsavefit/" + id + "/",
type: "GET",
dataType: "text"
});

request.done(function(msg) {
        $('#modalMessageBody').html('<div style="color: white;">' + msg + '</div>');
        showModal('#modalMessage');
        });
}


let sortOrder = 1;
let sortColumn = 0;
function doSort(column, doHide)
{
    let count = $(".item_row").length;
    if (count >= 250 && confirm(`Are you sure? There are ${count} rows to sort! This could result in high cpu usage which could cause your web application to temporarily lock up during the sort and possible increased battery drainage (e.g. phones, tablets, laptops).`) === false) return;
    if (doHide) $(".hide-when-sorted").hide();
    else $(".hide-when-sorted").show();

    if (column != sortColumn) {
        if (column >= 2) order = -1;
        else order = 1;
    }
    else order = -1 * sortOrder;
    if (column == 0) order = 1;

    //if (column == sortColumn && order == sortOrder) return;

    sortItemTable(column, order);
    sortColumn = column;
    sortOrder = order;
}

function sortItemTable(column, order) {
    var table, rows, switching, i, x, y, shouldSwitch;  
    table = document.getElementById("itemTable");
    switching = true;
    haveSwitched = false;

    do {
        haveSwitched = false;
        rows = table.rows;
        for (i = 1; i < (rows.length - 1); i++) {
            x = rows[i].getElementsByTagName("td")[column];
            if (x == undefined) x = rows[i].getElementsByTagName("th")[column];
            y = rows[i + 1].getElementsByTagName("td")[column];
            if (y == undefined) y = rows[i + 1].getElementsByTagName("th")[column];
            if (!x || !y) continue;
            let v1 = x.getAttribute('data-order');
            v1 = (v1 == null) ? x.innerHTML : parseFloat(v1);
            if (isNaN(v1)) v1 = x.getAttribute('data-order');
            let v2 = y.getAttribute('data-order');
            v2 = (v2 == null) ? y.innerHTML : parseFloat(v2);
            if (isNaN(v2)) v2 = y.getAttribute('data-order');
            if ((order == 1 && v1 > v2) || (order == -1 && v1 < v2)) {
                rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                t = rows[i];
                rows[i] = rows[i] + 1;
                rows[i + 1] = t;
                haveSwitched = true;
            }
        }
    } while (haveSwitched); 
} 

function sendCrestUrl() {
    str = $("#killmailurl").val();
    strSplit = str.split("/");
    if (strSplit.length === 8) strSplit.shift();
    killID = strSplit[4];
    hash = strSplit[5];
    a = ['/crestmail/', killID, '/', hash, '/'];
    url = a.join('');
    $.get(url);
}

function pasteCrestUrl() {
    setTimeout(pasteCrestUrlAsync, 1);
    return false;
}
async function pasteCrestUrlAsync() {
    try {
        const isFirefox = navigator.userAgent.toLowerCase().includes('firefox');
        if (isFirefox) return window.location = '/post/';

        let str = await navigator.clipboard.readText();
		strSplit = str.split('/');
		// Allow with or without /latest as part of the ESI url
        if (strSplit.length < 5 || strSplit.length > 8) return window.location = '/post/';

        $('#externalurl').val(str);
        $('#externalkmform').submit();
        console.log('submitted');

        return false;
    } catch (e) {
        console.log(e);
        return window.location = '/post/';
    }
}

function addKillListClicks()
{
    $(".killListRow").off('click.zkb-killrow').on('click.zkb-killrow', function(event) {
            if (event.which === 2) return false;
            console.log($(this).attr('killID'));
            navigateTo('/kill/' + $(this).attr('killID') + '/');
            return false;
            });
}

function doSponsor(url)
{
    $('#modalMessageBody').load(url);
    $('#modalTitle').text('Sponsor this killmail');
    showModal('#modalMessage');
}

function doFavorite(killID, star) {
    var favoriteStars = $(".fav-star-" + killID);
    var clickedElement = star ? $(star) : favoriteStars.first();
    var clickedStar = clickedElement.hasClass("fa-star") ? clickedElement : clickedElement.find(".fa-star").first();
    var color = clickedStar.css("color");
    var action = (color === "rgb(253, 188, 44)") ? "remove" : "save";
    var url = '/account/favorite/' + killID + '/' + action + '/';
    $.post(url, function( result ) {
		console.log(result);
        favoriteStars.css("color", result.color);
        favoriteStars.toggleClass("fas", result.color === "#FDBC2C");
        favoriteStars.toggleClass("far", result.color !== "#FDBC2C");
        updateFavoriteState(killID, result.color === "#FDBC2C");
		showToast(result.message, 5000);
    }, "json").fail(function() {
        showToast("Unable to update favorite. Please try again.", 5000);
    });
}

function applyFavoriteStars() {
    const favorites = new Set((window.zkbFavorites || []).map(Number));

    $('[class*="fav-star-"]').each(function() {
        const star = $(this);
        const favoriteClass = (this.className || '').split(/\s+/).find(name => name.indexOf('fav-star-') === 0);
        if (!favoriteClass) return;

        const killID = Number(favoriteClass.replace('fav-star-', ''));
        const isFavorite = favorites.has(killID);
        star.css("color", isFavorite ? "#FDBC2C" : "#d0d0d0");
        star.toggleClass("fas", isFavorite);
        star.toggleClass("far", !isFavorite);
    });
}

function updateFavoriteState(killID, isFavorite) {
    const favoriteSet = new Set((window.zkbFavorites || []).map(Number));
    killID = Number(killID);

    if (isFavorite) favoriteSet.add(killID);
    else favoriteSet.delete(killID);

    window.zkbFavorites = Array.from(favoriteSet);
}

function pubsub(channel, forceSend, generation)
{
    if (!channel) return;
    if (generation !== undefined && generation !== pubsubGeneration) return;

    const alreadySubscribed = pubsubs.includes(channel);
    if (!alreadySubscribed) pubsubs.push(channel);
    if (alreadySubscribed && !forceSend) return;

    try {
        ws.send(JSON.stringify({'action':'sub', 'channel': channel}));
        console.log("subscribing to " + channel);
    } catch (e) {
        const retryGeneration = pubsubGeneration;
        setTimeout(function() { pubsub(channel, true, retryGeneration); }, 150);
    }
}

function unpubsub(channel, generation)
{
    if (!channel) return;
    if (isPersistentPubsub(channel)) return;
    if (generation !== undefined && generation !== pubsubGeneration) return;

    try {
        ws.send(JSON.stringify({'action':'unsub', 'channel': channel}));
        console.log("unsubscribing from " + channel);
    } catch (e) {
        const retryGeneration = pubsubGeneration;
        setTimeout(function() { unpubsub(channel, retryGeneration); }, 150);
    }
}

function curday()
{
    today = new Date();
    var dd = today.getDate();
    var mm = today.getMonth()+1; //As January is 0.
    var yyyy = today.getFullYear();

    if(dd<10) dd='0'+dd;
    if(mm<10) mm='0'+mm;
    return (yyyy+mm+dd);
};

function commentUpVote(pageID, commentID) 
{
    if (showAds == 0 || typeof fusetag != "undefined") {
  $.ajax({
    url: "/cache/bypass/comment/" + pageID + "/" + commentID + "/up/",
    method: "POST",
    data: {}, // add payload here if needed
    success: function(response) {
      $("#commentblock").html(response);
    },
    error: function(xhr, status, err) {
      console.error("POST failed:", status, err);
    }
  });
    }
}

var adnumber = 0;
var adfailcount = 0;
function loadads() {
    $("#messagedad").remove();
    var adblocks = $(".publift:visible");
    adnumber = adblocks.length;
    adblocks.each(function() {
        const elem = $(this);
        const fuse = elem.attr("fuse") || '';
		if (fuse.trim().length > 0) elem.load('/cache/1hour/publift/' + fuse + '/', adblockloaded);
    });
}

var bottomad = null;
async function adblockloaded() {
    adnumber--;
	if (adnumber <= 0) {
		if (typeof fusetag != "undefined") {
            try {
                fusetag.loadSlots();
                if (fusetag.loadSlots.toString().includes('native')) throw '1';
                let res =  await fetch('/check.aspx?adid=');
                if (res.status != 403) throw '2';
                startWebSocket();
            } catch (e) {
                console.error(e);
                gtag('event', 'adblocked', 'detectAdblock blocked');
                $(".liveupdates").addClass('hidden');
                $("#noliveupdates").removeClass("hidden");
            }
		} else {
			showAdblockedMessage();
		}
    }
}

function showAdblockedMessage() {
    if ($("#publifttop").html() == "") {
        gtag('event', 'adblocked', 'detectAdblock blocked');
        let html = '';
        //if (promoURI != '') html = `<div style='max-height: 130px; max-width: 100%;'><a href="${promoURI}" target="_blank"><img style='max-height: 130px; max-width: 100%;' src="${promoImage1}" alt="Promotional Image" />User code "zkill" for 3% Off!</a></div>`;
        //else html = '<h4>AdBlocker Detected! :(</h4><p>Please support zKillboard by disabling your adblocker.<br/><a href="/information/payments/">Or block them with ISK and get a golden wreck too.</a></p>';
        $("#publifttop").html(html);
        if (ws) ws.close();
        $(".liveupdates").addClass('hidden');
        $("#noliveupdates").removeClass("hidden");
    }
}

var now = time();
var today = now - (now % 86400);
var week = now - (now % 604800);

function time() {
return Math.floor(Date.now() / 1000);
}

// gtcplex320.jpg  gtcplex728.jpg  merch320.jpg  merch728.jpg
var banner_links = ['https://store.markeedragon.com/affiliate.php?id=928&redirect=index.php?cat=4', 'https://www.zazzle.com/store/zkillboard/products'];
var banners_sm = ['/img/banners/gtcplex320.jpg', '/img/banners/merch320.jpg'];
var banners_lg = ['/img/banners/gtcplex728.jpg?1', '/img/banners/merch728.jpg'];
var ob_firstcall = true;
function otherBanners() {
return;
if (ob_firstcall) {
ob_firstcall = false;
return setTimeout(otherBanners, 6000);
}
if ($("#messagedad").length == 0) return;

var minute = new Date().getMinutes();
var mod = minute % 2; // number of other banners
$('#otherBannerAnchor').attr('href', banner_links[mod]);
$('#otherBannerImg').attr('src', banners_lg[mod]);
$("#otherBannerDiv").css('display', 'block');
setTimeout(otherBanners, Math.min(30000, 1000 * (61 - new Date().getSeconds())));
}

/*
   <h4>zKillboard does NOT automatically get all killmails</h4><p>zKillboard does not get all killmails automatically. CCP does not make killmails public. They must be provided by various means.</p><ul><li>Someone manually posts the killmail.</li><li>A character has authorized zKillboard to retrieve their killmails.</li><li>A corporation director or CEO has authorized zKillboard to retrieve their corporation\'s killmails.</li><li>War killmail (victim and final blow have a Concord sanctioned war with each other)</li></ul><p>The killmail API works just like killmails do in game. The victim gets the killmail, and the person with the finalblow gets the killmail. Therefore, for zKillboard to be able to retrieve the killmail via API it must have the character or corporation API submitted for the victim or the person with the final blow. If an NPC gets the final blow, the last character to aggress to the victim will receive the killmail and credit for the final blow.</p><p>Remember, every PVP killmail has two sides, the victim and the aggressors. Victims often don\'t want their killmails to be made public, however, the aggressors do.</p>
 */

function showAdder(showAdd, type, id, doTN) {
    if (doTN) pubsub('tracker:' + type + ':' + id);
    return (showAdd && ($("#tracker-remove-" + type + "-" + id).removeClass("hidden d-none").length == 0));
}

function statsboxUpdate(stats) {
    const statsbox = document.getElementById('statsbox');
    if (!statsbox || statsbox.dataset.entity != stats.type.replace(/ID$/, '') + ':' + stats.id) return;
    if (stats.type == 'systemID') stats.type = 'solarSystemID';
    else if (stats.type == 'shipID') stats.type = 'shipTypeID';
    $.get('/cache/tagged/stats/?type=' + stats.type + '&id=' + stats.id, function(values) {
        if (statsbox.isConnected) setStatsboxValues(values);
    });
}

function setStatsboxValues(stats) {
    Object.keys(stats).forEach((e) => $('#' + e).attr('raw', stats[e]) )
        doFormats();
    waitForStatsFunctionToLoadBecauseChromeIsBeingDumb(stats);
}

function waitForStatsFunctionToLoadBecauseChromeIsBeingDumb(stats) {
    if (typeof updateStats == 'undefined') return setTimeout(function() { waitForStatsFunctionToLoadBecauseChromeIsBeingDumb(stats), 10});
    updateStats(stats);
}

function doFormats() {
    $("[format='format-int']").each(function() { t = $(this); doFieldUpdate(t, Number(t.attr('raw') || 0).toLocaleString()); });
    $("[format='format-int-once']").each(function() { t = $(this); doFieldUpdate(t, Number(t.attr('raw') || 0).toLocaleString()); t.removeAttr('format'); });
    $("[format='format-pct-once']").each(function() { t = $(this); doFieldUpdate(t, (Number(t.attr('raw') || 0) + '%').toLocaleString()); t.removeAttr('format'); });

    $("[format='format-dec1']").each(function() { t = $(this); doFieldUpdate(t, parseFloat(t.attr('raw')).toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits:1} )); });
    $("[format='format-isk']").each(function() { t = $(this); doFieldUpdate(t, formatISK(Number(t.attr('raw')))) });

    $("[format='format-dec2-once']").each(function() { t = $(this); doFieldUpdate(t, parseFloat(t.attr('raw')).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2} )); t.removeAttr('format'); });
    $("[format='format-dec2-once-i']").each(function() { t = $(this); doFieldUpdate(t, parseFloat(t.attr('raw')).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2} ) + ' ISK'); t.removeAttr('format'); });
    $("[format='format-isk-once']").each(function() { t = $(this); doFieldUpdate(t, formatISK(Number(t.attr('raw')))); t.removeAttr('format'); });
    $("[format='format-isk-once-i']").each(function() { t = $(this); doFieldUpdate(t, formatISK(Number(t.attr('raw'))) + ' ISK'); t.removeAttr('format'); });
    $("#statsbox td[raw='-']").text('-');
}

function doFieldUpdate(f, v) {
    if (f.attr('raw') == '' || f.attr('raw') == '-' || f.attr('raw') == undefined) return;
    if (v == 'NaN') v = '';

    if (f.text() == String(v)) return;

    // Hidden tabs can queue animations and replay them on focus; update immediately instead.
    if (document.hidden || (typeof document.hasFocus === 'function' && !document.hasFocus())) {
        f.stop(true, true).text(v).css('opacity', 1);
        return;
    }

    let o = $(f).attr('flash') == undefined ? 1 : 0;
    f.stop(true, true).animate({opacity: o}, 100, function() {
            $(this).text(v).animate({opacity: 1}, 100);
            })
}

const formatIskIndex = ['', 'k', 'm', 'b', 't', 'k t', 'm t', 'b t'];
function formatISK(value, decimals = 2) {
    if (value < 10000) return value.toLocaleString();
    let i = 0;
    while (value > 999.99) {
        value = value / 1000;
        i++;
    }
    return value.toLocaleString(undefined, {minimumFractionDigits: decimals, maximumFractionDigits: decimals}) + formatIskIndex [i];
}

function assignRowColor() {
    document.querySelectorAll('.kltbd').forEach(function(el) {
        assignGreenRed.call(el);
    });
}

function assignGreenRed() {
    // URI split could be done at the global level, but page URI might change so we're keeping it here
    let urisplit = window.location.pathname.split('/');
    if (urisplit.length < 4 || urisplit[2] == '') return;
    let vicid = urisplit[2];

    const row = this;
    row.classList.remove('kltbd', 'winwin', 'error');
    let vics = row.getAttribute('vics');
    if (vics == '') return;
    vics = vics.split(',');

    for (let i = 0; i < vics.length; i++) if (vicid == vics[i]) {
        const removeIcon = document.querySelector('#kill-' + row.getAttribute('killID') + ' .fa-times');
        if (removeIcon) removeIcon.classList.remove('d-none');
        row.classList.add('error');
        return;
    }

    const okIcon = document.querySelector('#kill-' + row.getAttribute('killID') + ' .fa-check');
    if (okIcon) okIcon.classList.remove('d-none');
    row.classList.add('winwin');
}

function fixCCPsBrokenImages() {
    $("img[shipimageerror='true']").each(function() { let t = $(this);  let src = t.attr('src'); console.log('fixing', src); t.attr('src', src.replace('render', 'icon')).removeAttr('shipimageerror'); });
}

function updateTqStatus(tqStatus, count) { 
    setLiveCounter($('#tqCount'), count);
    let detail = 'TQ ', clss = 'green';

    if (tqStatus != 'ONLINE') clss = 'red';

    $("#tqStatusDetail").text(detail);
    $("#tqStatus").removeClass("red").removeClass("green").addClass(clss);
}

const dlsOptions = {
    '0': 'ASAP - Killmails will post as they are received.',
    '1': '1 hour - killmails will post when they are 1 hour old.',
    '2': '3 hours - killmails will post when they are 3 hours old.',
    '3': '8 hours - killmails will post when they are 8 hours old. ',
    '4': '24 hours - killmails will post when they are 24 hours old.',
    '5': '72 hours - killmails will post when they are 72 hours (3 days) old.'
}

const defaultSSOScopes = [
    'esi-killmails.read_killmails.v1',
    'esi-killmails.read_corporation_killmails.v1',
    'esi-fittings.write_fittings.v1'
];

function updateDLS(e) {
    let slider = $(this);
    let val = parseInt(slider.val() || 0, 10);
    if (Number.isNaN(val) || val < 0 || val > 5) val = 0;

    $('#dls-slider').val(val);
    $('#login-delay-slider').val(val);
    $("#dls-value").text(dlsOptions[val]);
    $("#login-delay-value").text(dlsOptions[val]);
    $("#dls-login").attr('href', `/ccpoauth2/${val}/`);
}

function parseDelayFromAuthPath(urlPath) {
    if (!urlPath) return null;
    const parts = String(urlPath).split('?')[0].match(/^\/ccpoauth2\/(\d+)\/?$/);
    if (!parts || !parts[1]) return null;
    const parsed = parseInt(parts[1], 10);
    if (Number.isNaN(parsed) || parsed < 0 || parsed > 5) return null;
    return parsed;
}

function interceptLoginClick(e) {
    if (e.which && e.which !== 1) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    const href = $(this).attr('href') || '/ccpoauth2/';
    if (!href.startsWith('/ccpoauth2/')) return;
    if (href.startsWith('/ccpoauth2-')) return;

    e.preventDefault();
    openLoginOptionsModal(href);
}

function interceptLoginSubmit(e) {
    const action = $(this).attr('action') || '/ccpoauth2/';
    if (!action.startsWith('/ccpoauth2/')) return;

    e.preventDefault();
    openLoginOptionsModal(action);
}

function openLoginOptionsModal(loginTarget) {
    const modal = $('#loginOptionsModal');
    if (modal.length == 0) {
        window.location = loginTarget;
        return;
    }

    const delayFromTarget = parseDelayFromAuthPath(loginTarget);
    const currentDelay = parseInt($('#dls-slider').val() || 0, 10);
    const delay = delayFromTarget == null ? currentDelay : delayFromTarget;
    $('#login-delay-slider').val(delay);
    updateDLS.call($('#login-delay-slider'));

    modal.find('.login-scope').prop('checked', true);
    syncLoginScopeAllCheckbox();
    showModal('#loginOptionsModal');
}

function continueLoginWithOptions() {
    const delay = parseInt($('#login-delay-slider').val() || 0, 10);
    const selectedScopes = [];
    $('#loginOptionsModal .login-scope:checked').each(function() {
        selectedScopes.push($(this).val());
    });

    let loginURL = `/ccpoauth2/${delay}/`;
    const scopes = selectedScopes.length > 0 ? selectedScopes : ['publicData'];
    if (scopes.join(',') !== defaultSSOScopes.join(',')) {
        loginURL += `?scopes=${encodeURIComponent(scopes.join(','))}`;
    }

    window.location = loginURL;
}

function loginScopeAllChange() {
    const checked = $(this).is(':checked');
    $('#loginOptionsModal .login-scope').prop('checked', checked);
    syncLoginScopeAllCheckbox();
}

function syncLoginScopeAllCheckbox() {
    const scopes = $('#loginOptionsModal .login-scope');
    const checkedCount = scopes.filter(':checked').length;
    const totalCount = scopes.length;
    const allScopes = $('#login-scope-all');

    allScopes.prop('checked', totalCount > 0 && checkedCount === totalCount);
    allScopes.prop('indeterminate', checkedCount > 0 && checkedCount < totalCount);
}

console.log('common.js loaded');

function stopPropagation(e) {
    e.stopPropagation();
}


function showToast(message, duration = 3000) {
	// Ensure a container exists
	let container = document.getElementById('toast-container');
	if (!container) {
		container = document.createElement('div');
		container.id = 'toast-container';
		document.body.appendChild(container);
	}

	// Create toast element
	const toast = document.createElement('div');
	toast.className = 'toast';
	toast.textContent = message;

	container.appendChild(toast);

	setTimeout(() => { toast.classList.add('show'); }, 10);


	// Hide and remove after duration
	setTimeout(hideToast, 3000);
}

function hideToast() {
	let container = document.getElementById('toast-container');
	if (container) container.remove();
}

let setTimeTimeoutHandle = null;
function setTime() {
	clearTimeout(setTimeTimeoutHandle);
	const now = new Date();
	const time = now.toUTCString().slice(17, 22) + " UTC";
	const el = document.getElementById("my_clock");
	if (el.textContent === time) return;
	el.textContent = time;

	// now determine approximate sleep time until the next minute
	const seconds = now.getUTCSeconds();
	let sleepTime = (60 - seconds) * 1000 + 50; // add a small buffer
	setTimeTimeoutHandle = setTimeout(setTime, sleepTime);
}
