var types = ['character', 'corporation', 'alliance', 'faction', 'shipType', 'group', 'location', 'solarSystem', 'region'];
var allowChange = true;
var first_load = true;

var radios = { sort: { sortBy: 'date', sortDir: 'desc' } };  // to be deprecated
var asfilter = { location: [], attackers: [], neutrals: [], victims: [], sort: { sortBy: 'date', sortDir: 'desc' } };

$(document).ready(function () {
	checkCharID();
});

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


	$("#btn_save").on('click', btn_save);
	$(".tfilter").on('click', adjustTime);
	$(".filter-btn").on('click', toggleFilterBtn);
	$(".radio-btn").on('click', toggleRadioBtn);

	$("#rolling-times").on('click', toggleRollingTime);
	$("#togglefilters").on('click', toggleFiltersClick);

	$("#dtstart").on('change', datepick);
	$("#dtend").on('change', datepick);

	$("#includeAssociates").on('change', doQuery.bind(null, 'groups'));

	if (window.location.hash != '') {
		setFilters();
	} else {
		adjustTime(null, $("#stats-epoch-week"));
	}

	setInterval(rollTime, 5000);
	$(".btn-page.btn-primary:not(.notafilter)").click();

	window.addEventListener('popstate', function () {
		setFilters();
		$(".btn-page.btn-primary:not(.notafilter)").click();
	});

	$("#clickToDigCheckbox").on('change', updateDrillDownPreference);

	first_load = false;
	doQuery();
};

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

	$('#rolling-times').blur().removeClass('btn-default').removeClass('btn-primary');

	if (enabled) {
		$('#rolling-times').addClass('btn-primary');
	}
	else $('#rolling-times').addClass('btn-default');
}

function rollTime() {
	var roll = ($('#rolling-times').hasClass('btn-primary'));
	if (roll == false) return;
	var currentStartTime = $('#dtstart').val();
	var currentEndTime = $('#dtend').val();
	adjustTime(null, $(".tfilter.btn-primary").first());

	if ((currentStartTime != $('#dtstart').val()) || (currentEndTime != $('#dtend').val())) clickPage1();
}

var lastEpochSelected = null;
function adjustTime(event, triggerButton) {
	var element = $(triggerButton == null ? this : triggerButton);
	var epoch = element.val();

	var date = new Date();
	var now = Math.floor(date.getTime() / 1000);
	var startTime = null;
	var endTime = null;

	var isDisabled = true;
	var isRolling = true;

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
	if (isDisabled == false) $("#dtstart").focus();
}

function getFormattedTime(unixtime) {
	if (unixtime == null) return '';
	var date = new Date(unixtime * 1000);
	return date.getUTCFullYear() + '/' + zeroPad(date.getUTCMonth() + 1) + '/' + zeroPad(date.getUTCDate()) + ' ' + zeroPad(date.getUTCHours()) + ':' + zeroPad(date.getUTCMinutes());
}

function zeroPad(text) {
	return ('00' + text).slice(-2);
}

function createSuggestion(json, slot) {
	addEntity({
		value: json.name,
		data: {
			type: json.type,
			id: json.id
		}
	}, slot);
}

function addEntity(suggestion, slot = 'neutrals') {
	delete suggestion.data.groupBy;
	if (suggestion.data.type != 'label' && suggestion.data.type.indexOf('ID') < 0) suggestion.data.type = suggestion.data.type + 'ID';
	switch (suggestion.data.type) {
		case 'label':

			break;
		case 'systemID':
		case 'constellationID':
		case 'regionID':
			asfilter.location.length = 0;
			asfilter.location.push(suggestion.data);
			$("#location").html("");
			add('location', suggestion);
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
	//console.log(suggestion.data.type, suggestion.data.id, suggestion.data.value);
	var left = $("<span>").addClass('btn').addClass('btn-sm').addClass('btn-success').addClass("glyphicon").addClass("glyphicon-chevron-left").attr('direction', 'left').on('click', moveLeft);
	var right = $("<span>").addClass('btn').addClass('btn-sm').addClass('btn-success').addClass("glyphicon").addClass("glyphicon-chevron-right").attr('direction', 'right').on('click', moveRight);
	var remove = $("<span>").addClass('btn').addClass('btn-sm').addClass("glyphicon").addClass("glyphicon-remove").addClass('alert-danger').on('click', moveOut);
	var data = $("<span>")
		.addClass("entity")
		.addClass('btn')
		.attr("id", suggestion.data.type + ':' + suggestion.data.id)
		.attr("entity-id", suggestion.data.id)
		.attr("entity-type", suggestion.data.type)
		.html(suggestion.data.type.replace('ID', '') + ': ' + suggestion.value);
	return $("<div>").attr('entity-type', suggestion.data.type).attr('entity-id', suggestion.data.id).append(left).append(data).append(right).append(remove).attr('time-id', 'id-' + Date.now()).addClass('filter').addClass('filter-' + suggestion.data.type);
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
	$(".glyphicon-remove").click();
	$(".btn.btn-primary:not(.notafilter)").removeClass("btn-primary").addClass("btn-default");

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

	filter.includeAssociates = $("#includeAssociates").prop('checked') == true;

	var hash = '';
	if (Object.keys(filter).length > 0) hash = '#' + JSON.stringify(filter);
	if ((window.location.pathname + window.location.hash) != (window.location.pathname + hash)) history.pushState("", document.title, window.location.pathname + hash);
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
function doQuery(queryType = 'all') {
	if (first_load) return;
	if (!allowChange) return;

	console.log('doQuery executing')

	var f = getFilters();
	var stringified = JSON.stringify(f);
	if (filtersStringified === stringified) {
		return;
	}
	filtersStringified = stringified;

	while (xhrs.length > 0) {
		var xhr = xhrs.pop();
		xhr.abort();
	}

	if (queryType == 'all' || queryType == 'kills') {
		$("#killmails-list").html("");
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
		$("#result-groups-count").html("");
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

		$("#result-groups-labels").html("");
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

		$("#result-groups-distincts").html("");
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
			$("#result-groups-" + types[i]).html("");
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

	setHash();
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
	$("#result-groups-distincts").html(data);
}

function applyLabelsResult(data, textStatus, jqXHR) {
	$("#result-groups-labels").html(data);
}

function applyKillQueryResult(data, textStatus, jqXHR) {
	$(".killlistmessage").remove();
	killIDs = data.kills;
	if (data.kills.length == 0) killlistmessage("no results - expand timespan, adjust pagination, or reduce filters...");
	else popEm();
}

function applyCountQueryResult(data, textStatus, jqXHR) {
	if (data == null || data.exceeds == true) {
		$("#result-groups-count").html("Timespan > 31 Days");
		return;
	}
	if (data.kills == 0) $("#result-groups-count").html('')
	// get the integer percentages for each of these
	let pctDropped = data.isk > 0 ? Math.round((data.dropped / data.isk) * 100) : 0;
	let pctDestroyed = data.isk > 0 ? Math.round((data.destroyed / data.isk) * 100) : 0;
	let pctFitted = data.isk > 0 ? Math.round((data.fitted / data.isk) * 100) : 0;

	let count = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Killmails</span><span class="small"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span raw="${data.kills}" format="format-int-once"></span></div>`;
	let isk = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Total</span><span class="small"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span raw="${data.isk}" format="format-isk-once"></span></div>`;
	let fitted = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Fitted</span><span class="small" raw="${pctFitted}" format="format-pct-once"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span raw="${data.fitted}" format="format-isk"></span></div>`;
	let dropped = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Dropped</span><span class="small" raw="${pctDropped}" format="format-pct-once"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span class="green" raw="${data.dropped}" format="format-isk-once"></span></div>`;
	let destroyed = `<div style="display:flex; justify-content:space-between; align-items:flex-end;"><span>Destroyed</span><span class="small" raw="${pctDestroyed}" format="format-pct-once"></span></div><div style="display:flex; justify-content:space-between; align-items:flex-end;"><span></span><span class="red" raw="${data.destroyed}" format="format-isk-once"></span></div>`;

	let html = [count, isk, fitted, dropped, destroyed].join('<span style="display:block; height:0.5em;"></span>');
	$("#result-groups-count").html(html);
}

function applyGroupQueryResult(data, textStatus, jqXHR) {
	$("#result-groups-" + this.title).html(data);
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
		$.get("/cache/1hour/killlistrow/" + killID + "/", function (data) {
			$("#kill-" + killID).replaceWith(data);
			doDateCleanup();
		});
		popEm();
	}
}

var lefts = { 'neutrals': 'attackers', 'victims': 'neutrals' };
function moveLeft() {
	move(this, lefts);
}

var rights = { 'neutrals': 'victims', 'attackers': 'neutrals' };
function moveRight() {
	move(this, rights);
}

function move(element, arr) {
	var parent = $(element).parent();
	var location = parent.parent().attr('id');

	var data = { type: parent.attr('entity-type'), id: parent.attr('entity-id') };
	filterCleanup(data);

	if (arr[location] != undefined) {
		asfilter[arr[location]].push(data);
		parent.detach().appendTo('#' + arr[location]);
		clickPage1();
	}
}

function moveOut() {
	var parent = $(this).parent();
	var location = parent.parent().attr('id');

	var data = { type: parent.attr('entity-type'), id: parent.attr('entity-id') };
	filterCleanup(data);

	parent.remove();

	if (location == 'location' && $("#location").html() == "") $("#location").html('All systems');
	clickPage1();
}

function doDateCleanup() {
	var rows = $("#killmails-list tr.tr-date");
	for (var i = rows.length - 1; i > 0; i--) { var r = $(rows[i]); var n = $(rows[i - 1]); if (r.attr('date') != '' && r.attr('date') == n.attr('date')) r.remove(); }
}

function toggleFilterBtn() {
	var element = $(this);
	if (element.hasClass('btn-primary')) element.removeClass('btn-primary').blur();
	else element.addClass('btn-primary').blur();
	clickPage1();
}

function toggleRadioBtn() {
	var element = $(this);
	var parent = element.parent();
	var variable = parent.attr('zkill-var');
	var key = parent.attr('zkill-key');
	parent.children().each(function () {
		$(this).removeClass('btn-primary').addClass('btn-default');
	});
	element.removeClass('btn-default').addClass('btn-primary');
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

	if (has_primary) element.removeClass('btn-primary').addClass('btn-default').blur();
	else element.removeClass('btn-default').addClass('btn-primary').blur();
	toggleFilters();
}

// Toggle filters being displayed
function toggleFilters() {
	if (allowChange == false) return;
	var displayed = $("#togglefilters").hasClass("btn-primary");
	$(".asearchfilters").toggle(displayed);
	$(".tr-date").toggle(displayed);

	var filters = [];
	$(".entity").each(function () { filters.push($(this).html().split(': ')[1]); });
	filters.push($(".tfilter.btn-primary").attr('title'));
	$(".filter-btn.btn-primary").each(function () { filters.push($(this).attr('data-label')); });
	var sort = $(".sorttype.btn-primary").html() + ' ' + $(".sortorder.btn-primary").html();
	if (sort != 'Date Desc') filters.push(sort);
	if ($(".pagenum.btn-primary").html() != '1') filters.push('Page ' + $(".pagenum.btn-primary").html());

	var title = (displayed ? 'Advanced Search' : filters.join(', '));
	$("#titlecontent").text(title);
}

async function btn_save() {
	let res = await fetch("/asearchsave/?url=" + encodeURIComponent(window.location.href));
	console.log(res);
	if (res.ok) {
		let short = await res.text();
		console.log(short);
		navigator.clipboard.writeText(short)
			.then(() => {
				console.log("Copied to clipboard!");
				$("#btn_save").text("Clipped").addClass('btn-info').blur();
				showToast('Saved URL copied to clipboard');
				setTimeout(() => { $("#btn_save").text("Save").removeClass('btn-info').blur(); }, 3000);
			})
			.catch(err => {
				console.error("Failed to copy:", err);
				alert('Failed to copy to your clipboard, see console for details');
			});
	} else {
		alert('Error trying to save: ' + res.statusCode);
	}
}

function assignClickCatch() {
	$("#clickablecontent a:not(.clickCatch):not(.nocatch)").addClass("clickCatch").on('click', clickCatch);
}
setInterval(assignClickCatch, 250);

async function clickCatch(e) {
	let altPressed = e.metaKey || e.altKey || $("#clickToDigCheckbox").is(':checked');
	if (!altPressed) return true; // default behavior

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


	e.preventDefault();
	return false;
}

function updateDrillDownPreference(e) {
	localStorage.setItem('drilldown-enabled', $("#clickToDigCheckbox").is(':checked') ? 'true' : 'false');
}

document.getElementById("exportCsv").addEventListener("click", function () {
	let groups = $("#result-groups-all table");

	const wb = XLSX.utils.book_new();

	let tabdata = [{ 'About': 'This export is beta, it is currently not meant as a killmail export.\nPlanned features include showing the filters used, different file name to reflect filters, etc.' }];
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
});