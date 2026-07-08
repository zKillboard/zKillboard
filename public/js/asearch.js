var types = ['character', 'corporation', 'alliance', 'faction', 'shipType', 'group', 'location', 'solarSystem', 'region'];
var allowChange = true;
var first_load = true;
var asearchRollTimeTimer = null;
var asearchClickCatchTimer = null;
var asearchHistoryNavigation = false;

var radios = { sort: { sortBy: 'date', sortDir: 'desc' } };  // to be deprecated
var asfilter = { location: [], attackers: [], neutrals: [], victims: [], items: [], sort: { sortBy: 'date', sortDir: 'desc' } };

$(document).ready(function () {
	zkbInitAsearch();
});

function zkbInitAsearch() {
	var container = document.getElementById('asearchcontent');
	if (!container) return;

	var initKey = window.location.pathname + window.location.hash;
	if (container.getAttribute('data-zkb-asearch-init-key') === initKey) return;
	container.setAttribute('data-zkb-asearch-init-key', initKey);

	allowChange = true;
	first_load = true;
	filtersStringified = undefined;
	radios = { sort: { sortBy: 'date', sortDir: 'desc' } };
	asfilter = { location: [], attackers: [], neutrals: [], victims: [], items: [], sort: { sortBy: 'date', sortDir: 'desc' } };
	checkCharID();
}

window.zkbInitAsearch = zkbInitAsearch;

function checkCharID() {
	/*if (characterID == -1) return setTimeout(checkCharID, 100);
	if (characterID == 0) {
		$(".contentrequiredlogin.content").remove();
	} else {
		$(".contentrequiredlogin.login").remove();
		loadasearch();
	}*/
	loadasearch();
}

function loadasearch() {
	$('.asearch-autocomplete').autocomplete({
		autoSelectFirst: false,
		serviceUrl: '/cache/1hour/autocomplete/',
		dataType: 'json',
		groupBy: 'groupBy',
		onSelect: function (suggestion) {
			addEntity(suggestion);
			$('.asearch-autocomplete').val('');
		},
		error: function (xhr) {
			console.log("ERROR", xhr);
		}
	}).focus();


	$("#btn_save").off('click.zkb-asearch').on('click.zkb-asearch', btn_save);
	$("#btn_export").off('click.zkb-asearch').on('click.zkb-asearch', btn_export);
	$("#exportCsv").off('click.zkb-asearch').on('click.zkb-asearch', exportCsv);
	$("#toggleGroupLayout").off('click.zkb-asearch').on('click.zkb-asearch', toggleGroupLayout);
	$(".tfilter").off('click.zkb-asearch').on('click.zkb-asearch', selectTimeFilter);
	$(".filter-btn").off('click.zkb-asearch').on('click.zkb-asearch', toggleFilterBtn);
	$(".radio-btn").not(".tfilter").off('click.zkb-asearch').on('click.zkb-asearch', toggleRadioBtn);

	$("#rolling-times").off('click.zkb-asearch').on('click.zkb-asearch', toggleRollingTime);
	$("#togglefilters").off('click.zkb-asearch').on('click.zkb-asearch', toggleFiltersClick);

	$("#dtstart").off('input.zkb-asearch').on('input.zkb-asearch', clickPage1);
	$("#dtend").off('input.zkb-asearch').on('input.zkb-asearch', clickPage1);

	$("#includeAssociates").off('change.zkb-asearch').on('change.zkb-asearch', doQuery.bind(null, 'groups'));

	if (window.location.hash != '') {
		setFilters();
	} else {
		adjustTime(null, $("#stats-epoch-week"));
	}

	startAsearchLoops();
	$(".btn-page.btn-primary:not(.notafilter)").click();

	$(document).off('zkb:spa:popstate.asearch').on('zkb:spa:popstate.asearch', asearchPopstate);

	$("#clickToDigCheckbox").off('change.zkb-asearch').on('change.zkb-asearch', updateDrillDownPreference);

	first_load = false;
	doQuery();
};

function asearchPopstate() {
	if (!document.getElementById('asearchcontent')) return;
	asearchHistoryNavigation = true;
	try {
		if (window.location.hash == '') resetFilters();
		else setFilters();
		$(".btn-page.btn-primary:not(.notafilter)").click();
		filtersStringified = undefined;
		doQuery();
	} finally {
		asearchHistoryNavigation = false;
	}
}

function datepick() {
	if (allowChange) {
		$(this).datetimepicker({ format: 'Y-m-d H:i' });
		clickPage1();
	}
}

function toggleRollingTime(event, enabled) {
	//console.log('toggle rolling time');
	if (enabled == undefined) {
		enabled = !($('#rolling-times').hasClass('btn-primary'));
	}

	$('#rolling-times').blur().removeClass('btn-secondary').removeClass('btn-primary');

	if (enabled) {
		$('#rolling-times').addClass('btn-primary');
	}
	else $('#rolling-times').addClass('btn-secondary');
}

function toggleGroupLayout(event, enabled) {
	if (event) event.preventDefault();
	if (enabled == undefined) enabled = !$("#toggleGroupLayout").hasClass("btn-primary");
	$("#page").toggleClass("d-none", enabled);
	$("#result-groups-column").toggleClass("col-md-2", !enabled).toggleClass("col-12", enabled);
	$("#result-groups-all").toggleClass("row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3", enabled);
	$("#result-groups-all > div").toggleClass("col", enabled);
	$("#toggleGroupLayout").toggleClass("btn-primary", enabled).toggleClass("btn-secondary", !enabled);
	if (allowChange) setHash();
}

function rollTime() {
	if (!document.getElementById('asearchcontent')) {
		asearchRollTimeTimer = null;
		return;
	}

	try {
		var roll = ($('#rolling-times').hasClass('btn-primary'));
		if (roll == false) return;
		var currentStartTime = toUTCISOString($('#dtstart').val());
		var currentEndTime = toUTCISOString($('#dtend').val());
		adjustTime(null, $(".tfilter.btn-primary").first());

		if ((currentStartTime != $('#dtstart').val()) || (currentEndTime != $('#dtend').val())) clickPage1();
	} finally {
		asearchRollTimeTimer = setTimeout(rollTime, 5000);
	}
}

var lastEpochSelected = null;
function selectTimeFilter(event) {
	if (event) event.preventDefault();
	var element = $(this);
	var parent = element.parent();

	parent.children(".tfilter").removeClass("btn-primary").addClass("btn-secondary");
	element.removeClass("btn-secondary").addClass("btn-primary").blur();

	radios.epoch = element.text().toLowerCase();
	adjustTime(null, element);
	clickPage1();
}

function adjustTime(event, triggerButton) {
	var element = $(triggerButton == null ? this : triggerButton);
	var epoch = element.val();

	var date = new Date();
	var now = Math.floor(date.getTime() / 1000);
	var startTime = null;
	var endTime = null;

	var isDisabled = true;
	var isRolling = false;

	switch (epoch) {
		case 'week':
			startTime = now - (86400 * 7);
			startTime = startTime - (startTime % 900);
			break;
		case 'recent':
			startTime = now - (86400 * 90);
			startTime = startTime - (startTime % 900);
			break;
		case 'alltime':
			// no changes needed
			break;
		case 'current month':
			startTime = Math.floor(new Date(date.getFullYear(), date.getUTCMonth(), 1, 0, 0, 0).getTime() / 1000);
			startTime = startTime - (startTime % 86400);
			isRolling = false;
			break;
		case 'prior month':
			startTime = Math.floor(new Date(date.getFullYear(), date.getUTCMonth() - 1, 1, 0, 0, 0).getTime() / 1000);
			endTime = Math.floor(new Date(date.getFullYear(), date.getUTCMonth(), 1, 0, -1, 0).getTime() / 1000);
			startTime = startTime - (startTime % 86400);
			endTime = endTime - (endTime % 86400) - 60;
			isRolling = false;
			break;
		case 'custom':
			isDisabled = false;
			isRolling = false;
	}

	$('#dtstart').prop('disabled', isDisabled).val(getFormattedTime(startTime));
	$('#dtend').prop('disabled', isDisabled).val(getFormattedTime(endTime));
	toggleRollingTime(null, isRolling);
	//if (isDisabled == false) $("#dtstart").focus();
}

function toUTCISOString(datetimeValue) {
	if (datetimeValue == null || datetimeValue.length == 0) return '';
	// Example input: "2025-10-23T10:30"
	const [datePart, timePart] = datetimeValue.split('T');
	const [year, month, day] = datePart.split('-').map(Number);
	const [hour = 0, minute = 0] = timePart.split(':').map(Number);

	// Construct UTC date explicitly
	const utcDate = new Date(Date.UTC(year, month - 1, day, hour, minute));
	return utcDate.toISOString(); // "2025-10-23T10:30:00.000Z"
}


function getFormattedTime(unixtime) {
	if (unixtime == null) return '';
	var date = new Date(unixtime * 1000);
	// convert the unixtime to datetime-local format
	return date.getUTCFullYear() + '-' + zeroPad(date.getUTCMonth() + 1) + '-' + zeroPad(date.getUTCDate()) + 'T' + zeroPad(date.getUTCHours()) + ':' + zeroPad(date.getUTCMinutes());
}

function zeroPad(text) {
	return ('00' + text).slice(-2);
}

function createSuggestion(json, slot) {
	addEntity({
		value: json.name,
		data: {
			type: json.type,
			id: json.id,
			pip: json.pip
		}
	}, slot);
}

function addEntity(suggestion, slot = 'neutrals') {
	delete suggestion.data.groupBy;
	if (suggestion.data.type == 'item') suggestion.data.type = 'typeID';
	else if (suggestion.data.type == 'ship') suggestion.data.type = 'shipID';
	else if (suggestion.data.type != 'label' && suggestion.data.type.indexOf('ID') < 0) suggestion.data.type = suggestion.data.type + 'ID';
	if (suggestion.data.type == 'itemID') suggestion.data.type = 'typeID';
	switch (suggestion.data.type) {
		case 'label':

			break;
		case 'systemID':
		case 'constellationID':
		case 'regionID':
			// Clear "All systems" placeholder if it exists
			if ($("#location").html().trim() == "All systems") {
				$("#location").html("");
			}
			asfilter.location.push(suggestion.data);
			add('location', suggestion);
			break;
		case 'typeID':
			asfilter.items.push(suggestion.data);
			add('items', suggestion);
			break;
		default:
			asfilter[slot].push(suggestion.data);
			add(slot, suggestion);

	}
	clickPage1();
}

function getHTML(suggestion) {
	suggestion.data.type = suggestion.data.type.replace(/[\W_]+/g, '');
	suggestion.data.id = parseInt(suggestion.data.id);
	suggestion.value = suggestion.value.replaceAll('<', '').replaceAll('>', '');
	var entityImage = getEntityImage(suggestion.data.type, suggestion.data.id);
	//console.log(suggestion.data.type, suggestion.data.id, suggestion.data.value);
	var left = $("<span>").addClass('btn').addClass('btn-sm').addClass('btn-success').addClass("fas").addClass("fa-chevron-left").attr('direction', 'left').on('click', moveLeft);
	var right = $("<span>").addClass('btn').addClass('btn-sm').addClass('btn-success').addClass("fas").addClass("fa-chevron-right").attr('direction', 'right').on('click', moveRight);
	var remove = $("<span>").addClass('btn').addClass('btn-sm').addClass("fas").addClass("fa-times").addClass("filter-remove").addClass('alert-danger').on('click', moveOut);
	left.css({ flex: "0 0 auto", width: "34px", display: "inline-flex", alignItems: "center", justifyContent: "center", borderRadius: "6px 0 0 6px", marginRight: "3px" });
	right.css({ flex: "0 0 auto", width: "34px", display: "inline-flex", alignItems: "center", justifyContent: "center", borderRadius: 0 });
	remove.css({ flex: "0 0 auto", width: "30px", display: "inline-flex", alignItems: "center", justifyContent: "center", borderRadius: "0 6px 6px 0" });
	var imageWrap = $("<span>")
		.css({ width: "42px", height: "42px", marginRight: "0.55em", flex: "0 0 42px", display: "inline-flex", alignItems: "center", justifyContent: "flex-start", overflow: "hidden" });
	var image = $("<img>")
		.attr("src", entityImage.src)
		.attr("alt", "")
		.attr("loading", "lazy")
		.attr("decoding", "async")
		.css({ width: "42px", height: "42px", flex: "0 0 42px", objectFit: "contain", objectPosition: "left center" })
		.addClass("eveimage img-rounded");
	if (entityImage.onerror != undefined) image.attr("onerror", entityImage.onerror);
	if ((suggestion.data.type == 'shipID' || suggestion.data.type == 'shipTypeID') && suggestion.data.pip) {
		image = $("<span>")
			.addClass("shipImageSpan")
			.css({ width: "42px", height: "42px", "--size": "42px", "--sizei": "42", margin: 0 })
			.append(image)
			.append($("<img>").addClass("pip").attr("src", "/img/pips/" + suggestion.data.pip).attr("alt", ""));
	}
	imageWrap.append(image);
	var data = $("<span>")
		.addClass("entity")
		.addClass('btn')
		.css({ display: "inline-flex", alignItems: "center", justifyContent: "flex-start", textAlign: "left", flex: "1 1 auto", width: "auto", minWidth: 0, padding: "4px 10px 4px 0", borderRadius: 0, color: "#ddd", backgroundColor: "#3f3f3f" })
		.attr("id", suggestion.data.type + ':' + suggestion.data.id)
		.attr("entity-id", suggestion.data.id)
		.attr("entity-type", suggestion.data.type)
		.attr("entity-name", suggestion.value)
		.append(imageWrap)
		.append($("<span>").text(suggestion.value));
	return $("<div>")
		.attr('entity-type', suggestion.data.type)
		.attr('entity-id', suggestion.data.id)
		.attr('entity-pip', suggestion.data.pip || "")
		.attr('time-id', 'id-' + Date.now())
		.css({ display: "flex", alignItems: "stretch", borderRadius: "7px", overflow: "hidden", padding: 0, backgroundColor: "#3f3f3f" })
		.addClass('filter')
		.addClass('filter-' + suggestion.data.type)
		.append(left)
		.append(data)
		.append(right)
		.append(remove);
}

function getEntityImage(type, id) {
	var size = 64;
	var image = { src: "/img/empty_32.png" };

	switch (type) {
		case 'characterID':
			image.src = "https://image.eveonline.com/Character/" + id + "_" + size + ".jpg";
			break;
		case 'corporationID':
			image.src = "https://image.eveonline.com/Corporation/" + id + "_" + size + ".png";
			break;
		case 'allianceID':
			image.src = "https://image.eveonline.com/Alliance/" + id + "_" + size + ".png";
			break;
		case 'factionID':
			image.src = "https://image.eveonline.com/Corporation/" + id + "_" + size + ".png";
			break;
		case 'shipID':
		case 'shipTypeID':
			image.src = "https://image.eveonline.com/Type/" + id + "_" + size + ".png";
			break;
		case 'typeID':
			image.src = "https://images.evetech.net/types/" + id + "/icon?size=" + size;
			image.onerror = "this.onerror=function(){this.removeAttribute('onerror'); this.src='/img/icons/" + id + "_64.png';}; this.src='https://images.evetech.net/types/" + id + "/bp?size=" + size + "';";
			break;
		case 'groupID':
			image.src = "https://images.evetech.net/types/1/icon?size=" + size;
			image.onerror = "this.removeAttribute('onerror'); this.src='/img/empty_32.png';";
			break;
		case 'systemID':
		case 'solarSystemID':
			image.src = id < 32000000 ? "/img/nohus/systems/" + id + ".png" : "/img/empty_32.png";
			break;
		case 'constellationID':
			image.src = "/img/nohus/constellations/" + id + ".png";
			break;
		case 'regionID':
			image.src = "/img/nohus/regions/" + id + ".png";
			break;
		case 'locationID':
			image.src = "https://image.eveonline.com/Type/" + id + "_" + size + ".png";
			image.onerror = "this.removeAttribute('onerror'); this.src='/img/empty_32.png';";
			break;
	}

	return image;
}

function add(id, suggestion) {
	var newhtml = getHTML(suggestion);
	var tag = $("#" + id);

	tag.append(newhtml);
}

function setFilters(hashfilters) {
	// load up that filter
	var hash = window.location.hash.substr(1);
	hash = decodeURI(hash).replaceAll('<', '').replaceAll('>', '');
	hashfilters = JSON.parse(hash);
	//console.log(hash);
	//console.log(hashfilters);

	allowChange = false;

	// Reset the search
	toggleGroupLayout(null, false);
	$(".filter-remove").click();
	$(".btn.btn-primary:not(.notafilter)").removeClass("btn-primary").addClass("btn-secondary");

	var keys = Object.keys(hashfilters);
	for (const key in hashfilters) {
		var value = hashfilters[key];
		if (key == 'buttons') {
			for (j = 0; j < value.length; j++) {
				$("[value='" + value[j] + "']").click();
			}
		}

		var promises = [];
		switch (key) {
			case 'includeAssociates':
				$("#includeAssociates").prop('checked', value);
				break;
			case 'dtstart':
			case 'dtend':
				$("#" + key).val(value);
				break;
			case 'location':
			case 'attackers':
			case 'neutrals':
			case 'victims':
			case 'items':
				for (var i = 0; i < value.length; i++) {
					var url = '/asearchinfo/?type=' + value[i].type + '&id=' + value[i].id;
					promises.push($.ajax({
						url: url,
						success: function (json) { createSuggestion(json, key); }
					}));
				}
				break;
		}
	}
	// Promise.all(promises); // actually does nothing here since we don't await
	allowChange = true;
	toggleFilters();
}

function resetFilters() {
	allowChange = false;
	toggleGroupLayout(null, false);
	$(".filter-remove").click();
	$(".btn.btn-primary:not(.notafilter)").removeClass("btn-primary").addClass("btn-secondary");
	radios = { sort: { sortBy: 'date', sortDir: 'desc' } };
	asfilter = { location: [], attackers: [], neutrals: [], victims: [], items: [], sort: { sortBy: 'date', sortDir: 'desc' } };
	$("#includeAssociates").prop('checked', false);
	$("#stats-epoch-week").removeClass("btn-secondary").addClass("btn-primary");
	adjustTime(null, $("#stats-epoch-week"));
	allowChange = true;
	toggleFilters();
}

function setHash() {
	var buttons = [];
	$(".btn.btn-primary").each(function () {
		var elem = $(this);
		var value = elem.attr('value');
		if (value == 'prior month' || value == 'current month') value = 'custom';
		if (value != null && value.length > 0) buttons.push(value);
	});
	var filter = {};
	if (buttons.length > 0) filter.buttons = buttons;
	if (buttons.indexOf('custom') >= 0 && $("#dtstart").val() != '') filter.dtstart = $("#dtstart").val();
	if (buttons.indexOf('custom') >= 0 && $("#dtend").val() != '') filter.dtend = $("#dtend").val();
	filter = setHashAdd(filter, asfilter, 'attackers');
	filter = setHashAdd(filter, asfilter, 'neutrals');
	filter = setHashAdd(filter, asfilter, 'victims');
	filter = setHashAdd(filter, asfilter, 'location');
	filter = setHashAdd(filter, asfilter, 'items');

	filter.includeAssociates = $("#includeAssociates").prop('checked') == true;

	var hash = '';
	if (Object.keys(filter).length > 0) hash = '#' + JSON.stringify(filter);
	if ((window.location.pathname + window.location.hash) != (window.location.pathname + hash)) {
		var historyState = (typeof getSpaHistoryState === 'function') ? getSpaHistoryState() : "";
		history.pushState(historyState, document.title, window.location.pathname + hash);
	}
}

function setHashAdd(filter, asfilter, key) {
	var value = asfilter[key];
	if (value != undefined && value.length > 0) {
		filter[key] = value;
	}
	return filter;
}


var xhrs = [];
var filtersStringified = undefined;
var asearchRetryTimer = null;
var asearchRetryQueryType = null;
function doQuery(queryType = 'all', isRetry = false) {
	if (first_load) return;
	if (!allowChange) return;

	console.log('doQuery executing')

	var f = getFilters();
	var stringified = JSON.stringify(f);
	if (!isRetry && filtersStringified === stringified) {
		return;
	}
	if (!isRetry) filtersStringified = stringified;

	while (xhrs.length > 0) {
		var xhr = xhrs.pop();
		xhr.abort();
	}

	if (!isRetry) clearAsearchResults(queryType);

	if (queryType == 'all' || queryType == 'kills') {
		var f1 = {};
		Object.assign(f1, f);
		f1.queryType = "kills";
		xhr = $.ajax('/asearchquery/', {
			data: f1,
			method: 'get',
			error: handleError,
			success: applyKillQueryResult,
			timeout: 60000 // 60 seconds
		});
		xhrs.push(xhr);
	}

	if (queryType == 'all' || queryType == 'groups') {
		var f2 = {};
		Object.assign(f2, f);
		f2.queryType = "count";
		xhr = $.ajax('/asearchquery/', {
			data: f2,
			method: 'get',
			error: handleError,
			success: applyCountQueryResult,
			timeout: 60000 // 60 seconds
		});
		xhrs.push(xhr);

		var f3 = {};
		Object.assign(f3, f);
		f3.queryType = "labels";
		xhr = $.ajax('/asearchquery/', {
			data: f3,
			method: 'get',
			error: handleError,
			success: applyLabelsResult,
			timeout: 60000 // 60 seconds
		});
		xhrs.push(xhr);

		var f4 = {};
		Object.assign(f4, f);
		f4.queryType = "distincts";
		xhr = $.ajax('/asearchquery/', {
			data: f4,
			method: 'get',
			error: handleError,
			success: applyDistinctsResult,
			timeout: 60000 // 60 seconds
		});
		xhrs.push(xhr);

		var ff = [];
		for (i = 0; i < types.length; i++) {
			ff[i] = {};
			Object.assign(ff[i], f);
			ff[i].queryType = "groups";
			ff[i].groupType = types[i];
			xhr = $.ajax('/asearchquery/', {
				title: types[i],
				data: ff[i],
				method: 'get',
				error: handleError,
				success: applyGroupQueryResult,
				timeout: 60000 // 60 seconds
			});
			xhrs.push(xhr);
		}
	}

	if (!asearchHistoryNavigation) setHash();
}

function getFilters() {
	var retVal = asfilter;
	retVal.labels = [];
	$(".tfilter.btn-primary").each(function () { retVal.epochbtn = $(this).val(); });
	$(".filter-btn.btn-primary").each(function () { retVal.labels.push($(this).attr('data-label')); });
	$(".andor .btn-primary").each(function () { retVal.labels.push($(this).val()); });
	retVal.epoch = { start: $("#dtstart").val(), end: $("#dtend").val() };
	retVal.radios = radios;
	retVal.includeAssociates = $("#includeAssociates").prop('checked') == true;
	return retVal;
}

function applyDistinctsResult(data, textStatus, jqXHR) {
	if (jqXHR.status == 202) return scheduleAsearchRetry('groups');
	$("#result-groups-distincts").html(data);
}

function applyLabelsResult(data, textStatus, jqXHR) {
	if (jqXHR.status == 202) return scheduleAsearchRetry('groups');
	$("#result-groups-labels").html(data);
}

function applyKillQueryResult(data, textStatus, jqXHR) {
	if (jqXHR.status == 202 || (data && data.processing == true)) return scheduleAsearchRetry('kills');
	$("#killmails-list").html("");
	killIDs = data.kills;
	if (data.kills.length == 0) killlistmessage("no results - expand timespan, adjust pagination, or reduce filters...");
	else popEm();
}

function applyCountQueryResult(data, textStatus, jqXHR) {
	if (jqXHR.status == 202 || (data && data.processing == true)) return scheduleAsearchRetry('groups');
	if (data == null || data.exceeds == true) {
		$("#result-groups-count").html("Timespan > 31 Days");
		return;
	}
	if (data.kills == 0) $("#result-groups-count").html('')
	// get the integer percentages for each of these
	let droppable = data.droppable > 0 ? data.droppable : data.isk;
	let droppableDestroyed = Math.max(0, droppable - data.dropped);
	let pctDropped = droppable > 0 ? Math.round((data.dropped / droppable) * 100) : 0;
	let pctDestroyed = droppable > 0 ? Math.round((droppableDestroyed / droppable) * 100) : 0;
	let pctFitted = data.isk > 0 ? Math.round((data.fitted / data.isk) * 100) : 0;

	let count = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Killmails</span><span class="small"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span raw="${data.kills}" format="format-int-once"></span></div>`;
	let isk = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Total</span><span class="small"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span raw="${data.isk}" format="format-isk-once"></span></div>`;
	let droppablePct = `<span class="small" style="display:inline-flex; gap:12px; align-items:center;"><span class="green" title="Dropped: Percentage of Droppable Value"><span raw="${pctDropped}" format="format-pct-once"></span> <i class="fas fa-check" aria-hidden="true" style="color: inherit;"></i></span><span class="red" title="Destroyed: Percentage of Droppable Value"><span raw="${pctDestroyed}" format="format-pct-once"></span> <i class="fas fa-times" aria-hidden="true" style="color: inherit;"></i></span></span>`;
	let droppableHtml = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Droppable</span><span class="small"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span raw="${droppable}" format="format-isk-once"></span></div><div style="display:flex; justify-content:flex-end; align-items:center; line-height:1.1; margin-top:2px;">${droppablePct}</div>`;
	let fitted = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Fitted</span><span class="small" raw="${pctFitted}" format="format-pct-once"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span raw="${data.fitted}" format="format-isk"></span></div>`;
	let dropped = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Dropped</span><span class="small" raw="${pctDropped}" format="format-pct-once"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span class="green" raw="${data.dropped}" format="format-isk-once"></span></div>`;
	let destroyed = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Destroyed</span><span class="small" raw="${pctDestroyed}" format="format-pct-once"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span class="red" raw="${data.destroyed}" format="format-isk-once"></span></div>`;

	let html = [count, isk, fitted, dropped, destroyed, droppableHtml].join('<span style="display:block; height:0.5em;"></span>');
	$("#result-groups-count").html(html);
}

function applyGroupQueryResult(data, textStatus, jqXHR) {
	if (jqXHR.status == 202) return scheduleAsearchRetry('groups');
	$("#result-groups-" + this.title).html(data);
}

function scheduleAsearchRetry(queryType) {
	asearchRetryQueryType = (asearchRetryQueryType && asearchRetryQueryType != queryType) ? 'all' : queryType;
	if (asearchRetryTimer != null) return;
	asearchRetryTimer = setTimeout(function () {
		var retryQueryType = asearchRetryQueryType || 'all';
		asearchRetryTimer = null;
		asearchRetryQueryType = null;
		doQuery(retryQueryType, true);
	}, 3000);
}

function clearAsearchResults(queryType) {
	if (queryType == 'all' || queryType == 'kills') $("#killmails-list").html("");
	if (queryType == 'all' || queryType == 'groups') {
		$("#result-groups-count").html("");
		$("#result-groups-labels").html("");
		$("#result-groups-distincts").html("");
		for (var i = 0; i < types.length; i++) $("#result-groups-" + types[i]).html("");
	}
}

function handleError(jqXHR, textStatus, errorThrown) {
	//console.log(jqXHR.status);
	filtersStringified = null;
	if (jqXHR.status == 403) killlistmessage('Server Reinforced - no advanced search as this time.');
	else if (jqXHR.status == 408) killlistmessage('Query took too long and timed out.');
	else killlistmessage(errorThrown + ' ' + textStatus);
}

function killlistmessage(message) {
	$(".killlistmessage").remove();
	var tr = $("<tr>").addClass('killlistmessage');
	var td = $("<td>").attr('colspan', 7).html('<i>' + message + '</i>');
	tr.append(td);
	$("#killmails-list").append(tr);
}

var killIDs = [];
function popEm() {
	if (killIDs.length > 0) {
		var killID = killIDs.shift();
		var tr = $("<tr>").attr('id', 'kill-' + killID);
		$("#killmails-list").append(tr);
		$.get("/cache/24hour/killlistrow/" + killID + "/", function (data) {
			$("#kill-" + killID).replaceWith(data);
			doDateCleanup();
		});
		popEm();
	}
}

var lefts = { 'neutrals': 'attackers', 'victims': 'neutrals', 'items': 'victims' };
function moveLeft() {
	move(this, lefts);
}

var rights = { 'neutrals': 'victims', 'attackers': 'neutrals', 'victims': 'items' };
function moveRight() {
	move(this, rights);
}

function move(element, arr) {
	var parent = $(element).parent();
	var location = parent.parent().attr('id');

	var data = { type: parent.attr('entity-type'), id: parent.attr('entity-id') };
	var pip = parent.attr('entity-pip');
	if (pip != undefined && pip != '') data.pip = pip;
	var destination = arr[location];

	if (destination != undefined && canMoveTo(data, destination)) {
		filterCleanup(data);
		asfilter[destination].push(data);
		parent.detach().appendTo('#' + destination);
		clickPage1();
	}
}

function canMoveTo(data, destination) {
	if (destination != 'items') return true;

	return ['characterID', 'corporationID', 'allianceID', 'factionID'].indexOf(data.type) < 0;
}

function moveOut() {
	var parent = $(this).parent();
	var location = parent.parent().attr('id');

	var data = { type: parent.attr('entity-type'), id: parent.attr('entity-id') };
	filterCleanup(data);

	parent.remove();

	// If location div is empty or only has whitespace, show "All systems" placeholder
	if (location == 'location' && $("#location").html().trim() == "") {
		$("#location").html('All systems');
	}
	clickPage1();
}

function doDateCleanup() {
	var rows = $("#killmails-list tr.tr-date");
	for (var i = rows.length - 1; i > 0; i--) { var r = $(rows[i]); var n = $(rows[i - 1]); if (r.attr('date') != '' && r.attr('date') == n.attr('date')) r.remove(); }
}

function toggleFilterBtn() {
	var element = $(this);
	if (element.hasClass('btn-primary')) element.removeClass('btn-primary').addClass('btn-secondary').blur();
	else element.removeClass('btn-secondary').addClass('btn-primary').blur();
	clickPage1();
}

function toggleRadioBtn() {
	var element = $(this);
	var parent = element.parent();
	var variable = parent.attr('zkill-var');
	var key = parent.attr('zkill-key');
	parent.children().each(function () {
		$(this).removeClass('btn-primary').addClass('btn-secondary');
	});
	element.removeClass('btn-secondary').addClass('btn-primary');
	if (key != undefined) radios[variable][key] = $(this).text().toLowerCase();
	else radios[variable] = $(this).text().toLowerCase();

	if (variable == 'page' || variable == 'sort') doQuery('kills');
	else if (variable == 'group-agg-type') doQuery('groups');
	else clickPage1();
}

function clickPage1() {
	if (allowChange) doQuery('all');
	toggleFilters();
}

// ugh, 2020 and still no good way to do this natively
function remove(arr, value) {
	var retval = [];
	for (var i = 0; i < arr.length; i++) {
		if (arr[i] !== value) retval.push(arr[i]);
	}
	return retval;
}

// Remove the element from the asfilter 
function filterCleanup(data) {
	var keys = Object.keys(asfilter);
	for (i = 0; i < keys.length; i++) {
		var key = keys[i];
		var value = asfilter[key];
		if (Array.isArray(value)) {
			for (j = 0; j < value.length; j++) {
				if (data.type == value[j].type && data.id == value[j].id) {
					value.splice(j, 1);
					asfilter[key] = value;
					return;
				}
			}
		}
	}
}

function toggleFiltersClick() {
	var element = $(this);
	var has_primary = element.hasClass('btn-primary');

	if (has_primary) element.removeClass('btn-primary').addClass('btn-secondary').blur();
	else element.removeClass('btn-secondary').addClass('btn-primary').blur();
	toggleFilters();
	if (allowChange) setHash();
}

// Toggle filters being displayed
function toggleFilters() {
	if (allowChange == false) return false;
	const displayed = $("#togglefilters").hasClass("btn-primary");
	$(".asearchfilters").toggle(displayed);
	$(".tr-date").toggle(displayed);
	$("#clickToDig").toggle(displayed);
	$("#clickToDigHr").toggle(displayed);

	updateTitle();
}

function updateTitle() {
	const displayed = $("#togglefilters").hasClass("btn-primary");

	var filters = [];
	$(".entity").each(function () {
		var entityText = $(this).attr('entity-name') || $(this).text();
		if (entityText != undefined && entityText !== '') filters.push(entityText);
	});
	var epochTitle = $(".tfilter.btn-primary").attr('title') ?? $(".tfilter.btn-primary").attr('prev-title');	

	if (epochTitle != undefined && epochTitle !== '') filters.push(epochTitle);
	$(".filter-btn.btn-primary").each(function () {
		var label = $(this).attr('data-label');
		if (label != undefined && label !== '') filters.push(label);
	});
	var sortType = $(".sorttype.btn-primary").text().trim();
	var sortOrder = $(".sortorder.btn-primary").text().trim();
	var sort = (sortType + ' ' + sortOrder).trim();
	if (sort !== '' && sort != 'Date Desc') filters.push(sort);
	var currentPage = $(".pagenum.btn-primary").text().trim();
	if (currentPage !== '' && currentPage != '1') filters.push('Page ' + currentPage);

	var title = ' Advanced Search: ' + (filters.length > 0 ? filters.join(', ') : 'No filters selected');
	$("#titlecontent").text(title);
}
if (!window.zkbAsearchTitleInterval) {
	window.zkbAsearchTitleInterval = setInterval(function() {
		if (document.getElementById('asearchcontent')) updateTitle();
	}, 1000); // in case something changes that doesn't trigger an update, like the epoch rolling time
}

async function btn_save(event) {
	if (event) event.preventDefault();

	const button = $("#btn_save");
	button.prop("disabled", true).attr("title", "Saving");

	try {
		let res = await fetch("/asearchsave/?url=" + encodeURIComponent(window.location.href), {
			credentials: "same-origin"
		});
		let savedPath = await res.text();
		if (!res.ok) throw new Error(savedPath || ("Unexpected status " + res.status));
		if (!/^\/asearchsaved\/[a-f0-9]+\/$/i.test(savedPath)) throw new Error(savedPath || "Invalid saved URL");
		let short = window.location.origin + savedPath;

		try {
			if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error("Clipboard API unavailable");
			await navigator.clipboard.writeText(short);
			button.addClass('btn-info').attr("title", "Saved URL copied").blur();
			showToast('Saved URL copied to clipboard');
		} catch (err) {
			console.error("Failed to copy:", err);
			button.addClass('btn-info').attr("title", "Saved URL ready").blur();
			window.prompt('Saved URL', short);
		}
	} catch (err) {
		console.error("Error trying to save:", err);
		alert('Error trying to save: ' + err.message);
	} finally {
		setTimeout(() => { button.prop("disabled", false).removeClass('btn-info').attr("title", "").blur(); }, 3000);
	}
}

function assignClickCatch() {
	if (!document.getElementById('asearchcontent')) {
		asearchClickCatchTimer = null;
		return;
	}

	try {
		$("#clickablecontent a:not(.clickCatch):not(.nocatch)").addClass("clickCatch").on('click', clickCatch);
	} finally {
		asearchClickCatchTimer = setTimeout(assignClickCatch, 250);
	}
}

function startAsearchLoops() {
	if (asearchRollTimeTimer == null) rollTime();
	if (asearchClickCatchTimer == null) assignClickCatch();
}

async function clickCatch(e) {
	let altPressed = e.metaKey || e.altKey || $("#clickToDigCheckbox").is(':checked');
	if (!altPressed) return true; // default behavior

	e.preventDefault();
	e.stopPropagation();
	e.stopImmediatePropagation();

	let name = $(this).text();
	if (name == '' && e.target) name = $(e.target).attr('title');
	let href = $(this).attr('href');
	let split = href.replaceAll('/', ' ').split(' ').filter(Boolean);

	document.querySelector('body').style.minHeight = getComputedStyle(document.querySelector('body')).height;

	console.log(name, split);

	if (split[0] == 'kill') name = 'killID ' + split[1];
	if (name == '') name = split[0] + ' ' + split[1];
	addEntity({
		value: name || split[1],
		data: {
			type: split[0],
			id: split[1]
		}
	});
	return false;
}

function updateDrillDownPreference(e) {
	localStorage.setItem('drilldown-enabled', $("#clickToDigCheckbox").is(':checked') ? 'true' : 'false');
}

function exportCsv() {
	let groups = $("#result-groups-all table");

	const wb = XLSX.utils.book_new();

	let tabdata = [{ 'About': 'This export is not meant as a killmail export.\nPlanned features include showing the filters used, different file name to reflect filters, etc.' }];
	let ws = XLSX.utils.json_to_sheet(tabdata);
	ws['!cols'] = [{ wch: 100 }];
	XLSX.utils.book_append_sheet(wb, ws, "About This Export");

	const data = [];
	for (let group of groups) {
		group = $(group);
		let tabdata = [];
		for (let tr of group.find('tr')) {
			let colHeader = group.attr('data-singular') || 'Header';

			let row = {};
			let columns = $(tr).find('td');
			for (let i = 0; i < columns.length; i++) {
				td = $(columns[i]);
				if (td.attr('colspan')) continue;
				let text = td.text().trim();
				if (text == '') continue;
				row[i == 1 ? colHeader : 'Kills'] = text;
			}
			if (Object.keys(row).length == 0) continue;
			tabdata.push(row);
		}
		if (tabdata.length == 0) continue;
		const ws = XLSX.utils.json_to_sheet(tabdata);

		const colWidths = Object.keys(tabdata[0]).map(key => {
			const maxLen = tabdata.reduce((acc, row) => {
				return Math.max(acc, String(row[key] || "").length);
			}, key.length);
			return { wch: maxLen + 2 }; // +2 padding
		});
		ws['!cols'] = colWidths;

		XLSX.utils.book_append_sheet(wb, ws, group.attr('aria-title'));
	}

	/*const data = [
		{ name: "Squizz", corp: "WHPD", ship: "Tholos" },
		{ name: "Kaelen", corp: "Gruber", ship: "Prospect" }
	];

	// Convert to sheet
	const ws = XLSX.utils.json_to_sheet(data);
	const wb = XLSX.utils.book_new();
	XLSX.utils.book_append_sheet(wb, ws, "Pilots");*/

	// Export
	XLSX.writeFile(wb, "export.xlsx");
}

function buildZkillbotFilter() {
	var parts = [];
	
	var entityFieldMap = {
		'characterID':    'character_id',
		'corporationID':  'corporation_id',
		'allianceID':     'alliance_id',
		'factionID':      'faction_id',
		'shipID':         'ship_type_id',
		'shipTypeID':     'ship_type_id',
		'groupID':        'group_id',
		'systemID':       'solar_system_id',
		'solarSystemID':  'solar_system_id',
		'regionID':       'region_id',
		'constellationID':'constellation_id',
	};

	function getJoinType(slot) {
		var val = $(`#${slot}-joinType .btn-primary`).val() || '';
		return val.endsWith('-or') ? 'or' : 'and';
	}

	function pushEntities(entities, isVictimFlag, joinType) {
		var terms = [];
		for (var i = 0; i < entities.length; i++) {
			var field = entityFieldMap[entities[i].type];
			if (!field) continue;
			var suffix = isVictimFlag !== null ? ';is_victim=' + isVictimFlag : '';
			terms.push(`[${field}=${entities[i].id}${suffix}]`);
		}
		if (terms.length === 0) return;
		if (joinType === 'or' && terms.length > 1) parts.push('(' + terms.join(',') + ')');
		else terms.forEach(function(t) { parts.push(t); });
	}

	pushEntities(asfilter.victims,   'true',  getJoinType('victim'));
	pushEntities(asfilter.attackers, 'false', getJoinType('attackers'));
	pushEntities(asfilter.neutrals,  null,    getJoinType('either'));

	for (var i = 0; i < asfilter.location.length; i++) {
		var e = asfilter.location[i];
		var field = entityFieldMap[e.type];
		if (field) parts.push(field + '=' + e.id);
	}

	$(".filter-btn.btn-primary").each(function () {
		parts.push('labels=' + $(this).attr('data-label'));
	});

	if (parts.length === 0) return null;
	return parts.join(';');
}

function btn_export() {
	var filter = buildZkillbotFilter();
	if (!filter) {
		alert('No filters selected to export!');
		return;
	}

	// Show a modal with the filter and option to copy to clipboard
	var modal = $(`
		<div class="modal fade" id="exportModal" tabindex="-1" role="dialog" aria-labelledby="exportModalLabel" aria-hidden="true">
		  <div class="modal-dialog" role="document">
		    <div class="modal-content">
		      <div class="modal-header">
		        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		      </div>
		      <div class="modal-body">
		        <p>Copy the filter below to use in zkillbot:</p>
		        <input type="text" class="form-control" id="zkillFilterInput" readonly>
		      </div>
		      <div class="modal-footer">
		        <button type="button" class="btn btn-primary" id="copyZkillFilter">Copy to Clipboard</button>
		      </div>
		    </div>
		  </div>
		</div>
	`);
	modal.find('#zkillFilterInput').val('/zkillbot subscribe advanced:' + filter);
	$('body').append(modal);
	const modalEl = modal[0];
	const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
	modalInstance.show();

	$('#copyZkillFilter').on('click', function() {
		var input = document.getElementById('zkillFilterInput');
		input.select();
		input.setSelectionRange(0, 99999); // For mobile devices
		document.execCommand('copy');
		// remove the modal after copying
		modalInstance.hide();
	});	
	modal.on('hidden.bs.modal', function () {
		modal.remove();
	});	

}
