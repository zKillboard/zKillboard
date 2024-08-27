$(document).ready(loadOverview);

function loadOverview() {
    if (typeof $ === 'undefined') return setTimeout(loadOverview, 1);

    if ($("#killlist").length > 0) $.get('/cache/bypass/killlist/?u=' + window.location.pathname, prepKills);
}
