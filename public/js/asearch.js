var types = ['character', 'corporation', 'alliance', 'faction', 'shipType', 'group', 'region', 'solarSystem', 'location'];
var allowChange = true;

var radios = { sort: { sortBy: 'date', sortDir: 'desc' }};  // to be depracated
var asfilter = {location: [], attackers: [], neutrals: [], victims: [], sort: { sortBy: 'date', sortDir: 'desc' }};

$(document).ready(function() {
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
                console.log(xhr);
            }
        }).focus();


    $(".tfilter").on('click', adjustTime);
    $(".filter-btn").on('click', toggleFilterBtn);
    $(".radio-btn").on('click', toggleRadioBtn);

    $("#rolling-times").on('click', toggleRollingTime);
    $("#togglefilters").on('click', toggleFiltersClick);

    $("#dtstart").on('change', datepick);
    $("#dtend").on('change', datepick);

    if (window.location.hash != '') {
        setFilters();
    } else {
        adjustTime(null, $("#stats-epoch-week"));
    }

    setInterval(rollTime, 5000);
    $(".btn-page.btn-primary").click();

    window.addEventListener('popstate', function() {
        setFilters();
        $(".btn-page.btn-primary").click();
    });
});

function datepick() {
    if (allowChange) {
        $(this).datetimepicker({format: 'Y-m-d H:i'});
        clickPage1();
    }
}

function toggleRollingTime(event, enabled) {
    console.log('toggle rolling time');
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

    switch(epoch) {
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
    if (isDisabled == false)  $("#dtstart").focus();
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
    var left = $("<span>").addClass("glyphicon").addClass("glyphicon-chevron-left").attr('direction', 'left').on('click', moveLeft).css('cursor', 'pointer');
    var right = $("<span>").addClass("glyphicon").addClass("glyphicon-chevron-right").attr('direction', 'right').on('click', moveRight).css('cursor', 'pointer');
    var remove = $("<span>").addClass("glyphicon").addClass("glyphicon-remove").on('click', moveOut).css('cursor', 'pointer').css('color', 'red');
    var data = $("<span>")
        .attr("class", "entity")
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
    hash = decodeURI(hash);
    hashfilters = JSON.parse(hash);
console.log(hash);
console.log(hashfilters);

    allowChange = false;

    // Reset the search
    $(".glyphicon-remove").click();
    $(".btn.btn-primary").removeClass("btn-primary").addClass("btn-default");

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
                        success: function(json) { createSuggestion(json, key); }
                    }));
                }
                break;
        }
    }
    Promise.all(promises);
    allowChange = true;
}

function setHash() {
    var buttons = [];
    $(".btn.btn-primary").each(function() {
        var elem = $(this);
        var value = elem.attr('value');
        if (value == 'prior month' || value =='current month') value='custom';
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
function doQuery() {
    if (!allowChange) return;

    var f = getFilters();
    var stringified = JSON.stringify(f);
    if (filtersStringified === stringified) {
        return;
    }
    filtersStringified = stringified;

    $("#killmails-list").html("");
    $("#result-groups-count").html("");
    while (xhrs.length > 0) {
        var xhr = xhrs.pop();
        xhr.abort();
    }

    killlistmessage('fetching');

    var f1 = {};
    Object.assign(f1, f);
    f1.queryType = "kills";
    xhr = $.ajax('/asearchquery/', {
        data: f1,
        method: 'get',
        error: handleError,
        success: applyKillQueryResult,
        timeout: 30000 // 30 seconds
    });
    xhrs.push(xhr);

    var f2 = {};
    Object.assign(f2, f);
    f2.queryType = "count";
    xhr = $.ajax('/asearchquery/', {
        data: f2,
        method: 'get',
        error: handleError,
        success: applyCountQueryResult,
        timeout: 30000 // 30 seconds
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
            timeout: 30000 // 30 seconds
        });
        xhrs.push(xhr);
    }

    setHash();
}

function getFilters() {
    var retVal = asfilter;
    retVal.labels = [];
    $(".tfilter.btn-primary").each(function() { retVal.epochbtn = $(this).val(); });
    $(".filter-btn.btn-primary").each(function() { retVal.labels.push($(this).html()); });
    $(".andor .btn-primary").each(function() { retVal.labels.push($(this).val()); });
    retVal.epoch = { start: $("#dtstart").val(), end: $("#dtend").val()};
    retVal.radios = radios;
    return retVal;
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
    var count = data.kills;
    var isk = data.isk;
    if (count != "") $("#result-groups-count").html("Killmails: " + count + "<br/>ISK: " + isk);
}

function applyGroupQueryResult(data, textStatus, jqXHR) {
    $("#result-groups-" + this.title).html(data);
}

function handleError(jqXHR, textStatus, errorThrown) {
    filtersStringified = null;
    killlistmessage(errorThrown + ' ' + textStatus);
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
        $.get("/cache/1hour/killlistrow/" + killID + "/", function(data) {
                $("#kill-" + killID).replaceWith(data);
                doDateCleanup();
            });
        popEm();
    }
}

var lefts = {'neutrals': 'attackers', 'victims': 'neutrals'};
function moveLeft() {
    move(this, lefts);
}

var rights = {'neutrals': 'victims', 'attackers': 'neutrals'};
function moveRight() {
    move(this, rights);
}

function move(element, arr) {
    var parent = $(element).parent();
    var location = parent.parent().attr('id');

    var data = {type: parent.attr('entity-type'), id: parent.attr('entity-id')};
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

    var data = {type: parent.attr('entity-type'), id: parent.attr('entity-id')};
    filterCleanup(data);

    parent.remove();

    if (location == 'location' && $("#location").html() == "") $("#location").html('All systems');
    clickPage1();
}

function doDateCleanup() {
    var rows = $("#killmails-list tr.tr-date");
    for (var i = rows.length - 1; i > 0; i-- ) {  var r = $(rows[i]);  var n = $(rows[i-1]); if (r.attr('date') != '' && r.attr('date') == n.attr('date')) r.remove(); }
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
    parent.children().each(function() {
        $(this).removeClass('btn-primary').addClass('btn-default');
    });
    element.removeClass('btn-default').addClass('btn-primary');
    if (key != undefined) radios[variable][key] = $(this).text().toLowerCase();
    else radios[variable] = $(this).text().toLowerCase();
    if (variable == 'page') doQuery();
    else clickPage1();
}

function clickPage1() {
    if (allowChange) $('#page1').click();
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
    $(".entity").each(function() { filters.push($(this).html().split(': ')[1]); });
    filters.push($(".tfilter.btn-primary").attr('title'));
    $(".filter-btn.btn-primary").each(function () { filters.push($(this).html()); });
    var sort = $(".sorttype.btn-primary").html() + ' ' + $(".sortorder.btn-primary").html();
    if (sort != 'Date Desc') filters.push(sort);
    if ($(".pagenum.btn-primary").html() != '1') filters.push('Page ' + $(".pagenum.btn-primary").html());
    console.log(filters);

    var title = (displayed ? 'Advanced Search' : filters.join(', '));
    $("#titlecontent").html(title);
}
