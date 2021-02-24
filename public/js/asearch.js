var types = ['character', 'corporation', 'alliance', 'faction', 'shipType', 'group', 'region', 'solarSystem', 'location'];

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
        }).focus();;
    $(".filter-btn").on('click', toggleFilterBtn);
    $(".radio-btn").on('click', toggleRadioBtn);
    $("#dtstart").on('change', clickPage1).datetimepicker({format: 'Y-m-d H:i'});
    $("#dtend").on('change', clickPage1).datetimepicker({format: 'Y-m-d H:i'});

    clickPage1();
});

function addEntity(suggestion) {
    console.log("suggestion", suggestion);
    delete suggestion.data.groupBy;
    if (suggestion.data.type != 'label' && suggestion.data.type.indexOf('ID') < 0) suggestion.data.type = suggestion.data.type + 'ID';
    switch (suggestion.data.type) {
        case 'label':

            break;
        case 'systemID':
        case 'constellationID':
        case 'regionID':
            asfilter.location.push(suggestion.data);
            if ($("#location").html() == 'All systems') $("#location").html("");
            add('location', suggestion);
            break;
        default:
            asfilter.neutrals.push(suggestion.data);
            add('neutrals', suggestion);

    }
    clickPage1();
}

var radios = { sort: { sortBy: 'date', sortDir: 'desc' }};  // to be depracated
var asfilter = {location: [], attackers: [], neutrals: [], victims: [], sort: { sortBy: 'date', sortDir: 'desc' }};

function getHTML(suggestion) {
    var left = $("<span>").addClass("glyphicon").addClass("glyphicon-chevron-left").attr('direction', 'left').on('click', moveLeft).css('cursor', 'pointer');
    var right = $("<span>").addClass("glyphicon").addClass("glyphicon-chevron-right").attr('direction', 'right').on('click', moveRight).css('cursor', 'pointer');
    var remove = $("<span>").addClass("glyphicon").addClass("glyphicon-remove").on('click', moveOut).css('cursor', 'pointer').css('color', 'red');
    var data = $("<span>")
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

var xhrs = [];
var filtersStringified = undefined;
function doQuery() {
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
    console.log(f2);
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
}

function getFilters() {
    var retVal = asfilter;
    retVal.labels = [];
    $(".filter-btn.btn-primary").each(function() { retVal.labels.push($(this).html()); });
    retVal.epoch = { start: $("#dtstart").val(), end: $("#dtend").val()};
    retVal.radios = radios;
    return retVal;
}

function applyKillQueryResult(data, textStatus, jqXHR) {
    console.log('https://zkillboard.com/' + this.url);
    $(".killlistmessage").remove();
    killIDs = data.kills;
    if (data.kills.length == 0) killlistmessage("no results");
    else popEm();
}

function applyCountQueryResult(data, textStatus, jqXHR) {
    console.log('https://zkillboard.com/' + this.url);
    if (data.exceeds == true) {
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
    console.log('ajax error: ' + errorThrown + ' ' + textStatus);
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
    } else {
        // We're done, now do some cleanup
        killListAd(true);
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
    $('#page1').click();
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
