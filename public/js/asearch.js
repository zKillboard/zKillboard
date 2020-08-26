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
    $("#dtstart").on('change', doQuery).datetimepicker({format: 'Y-m-d H:i'});
    $("#dtend").on('change', doQuery).datetimepicker({format: 'Y-m-d H:i'});

    doQuery();
});

function addEntity(suggestion) {
    if (ids.indexOf(suggestion.data.id) !== -1) return;
    ids.push(suggestion.data.id);

    delete suggestion.data.groupBy;
    if (suggestion.data.type != 'label') suggestion.data.type = suggestion.data.type + 'ID';
    switch (suggestion.data.type) {
        case 'label':

            break;
        case 'systemID':
        case 'constellationID':
        case 'regionID':
            if ($("#location").html() == 'All systems') $("#location").html("");
            add('location', suggestion);
            break;
        default:
            add('neutrals', suggestion);

    }
    doQuery();
}

var ids = [];
var filters = {location: [], attackers: [], neutrals: [], victims: []};

var html = '<div type=":type" id=":id">:name</div>';
function getHTML(suggestion) {
    var left = $("<span>").addClass("glyphicon").addClass("glyphicon-chevron-left").attr('direction', 'left').on('click', moveLeft).css('cursor', 'pointer');
    var right = $("<span>").addClass("glyphicon").addClass("glyphicon-chevron-right").attr('direction', 'right').on('click', moveRight).css('cursor', 'pointer');
    var remove = $("<span>").addClass("glyphicon").addClass("glyphicon-remove").on('click', moveOut).css('cursor', 'pointer').css('color', 'red');
    var data = $("<span>")
        .attr("id", suggestion.data.type + ':' + suggestion.data.id)
        .attr("entity-id", suggestion.data.id)
        .attr("entity-type", suggestion.data.type)
        .html(suggestion.data.type.replace('ID', '') + ': ' + suggestion.value);
    return $("<div>").append(left).append(data).append(right).append(remove).attr('time-id', 'id-' + Date.now()).addClass('filter').addClass('filter-' + suggestion.data.type);
}

function add(id, suggestion) {
    var newhtml = getHTML(suggestion);
    var tag = $("#" + id);

    tag.append(newhtml);

    var timeid = newhtml.attr('time-id');
    if (filters[id] == undefined) filters[id] = [];
    filters[id][timeid] = suggestion.data;
}

var xhr = undefined;
function doQuery() {
    $("#killmails-list").html("");
    if (xhr != undefined) xhr.abort();
    killlistmessage('fetching');
    xhr = $.ajax('/asearchquery/', {
        data: getFilters(),
        method: 'get',
        error: handleError,
        success: applyQueryResult,
        timeout: 30000 // 30 seconds
    });
}

function getFilters() {
    var retVal = {};
    var keys = Object.keys(filters);   
    for (var i = 0; i < keys.length; i++) {
        retVal[keys[i]] = Object.values(filters[keys[i]]);
    }
    retVal.labels = [];
    $(".filter-btn.btn-primary").each(function() { retVal.labels.push($(this).html()); });
    retVal.epoch = { start: $("#dtstart").val(), end: $("#dtend").val()};
    console.log(retVal);
    return retVal;
}

function applyQueryResult(data, textStatus, jqXHR) {
    $(".killlistmessage").remove();
    killIDs = data;
    if (data.length == 0) killlistmessage("no results");
    else popEm();
}

function handleError(jqXHR, textStatus, errorThrown) {
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

    var timeid = parent.attr('time-id');
    var data = filters[location][timeid];

    if (arr[location] != undefined) {
        delete filters[location][timeid];
        filters[arr[location]][timeid] = data;
        parent.detach().appendTo('#' + arr[location]);
        doQuery();
    }
}

function moveOut() {
    var parent = $(this).parent();
    var location = parent.parent().attr('id');

    var timeid = parent.attr('time-id');
    var data = filters[location][timeid];

    //ids = ids.splice(ids.indexOf(filters[location][timeid].id));
    ids = remove(ids, filters[location][timeid].id);
    delete filters[location][timeid];
    parent.remove();


    if (location == 'location' && $("#location").html() == "") $("#location").html('All systems');
    doQuery();
}

function doDateCleanup() {
    $(".tr-date").each(cleanDates);
}

function cleanDates() {
    var element = $(this);
    var date = element.attr('date');
    var allWithDate= $("[date='" + date + "']");
    if (allWithDate.length >= 2) {
        for (var i = 1; i < allWithDate.length; i++) allWithDate[i].remove();
    }
}

function toggleFilterBtn() {
    var element = $(this);
    if (element.hasClass('btn-primary')) element.removeClass('btn-primary').blur();
    else element.addClass('btn-primary').blur();
    doQuery();
}

// ugh, 2020 and still no good way to do this natively
function remove(arr, value) {
    var retval = [];
    for (var i = 0; i < arr.length; i++) {
        if (arr[i] !== value) retval.push(arr[i]);
    }
    return retval;
}
