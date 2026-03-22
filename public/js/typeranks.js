$(document).ready(function() {
    applyStyles();
    initRankTableServerSorting();
});

function initRankTableServerSorting() {
    $('.rank-table').each(function() {
        const headers = $(this).find('thead th[data-sort-key]');

        headers.each(function() {
            const header = $(this);
            const sortKey = header.attr('data-sort-key');
            const defaultDir = getDefaultDirection(sortKey);
            const isCurrent = (ranksSortKey === sortKey);
            const nextDirection = isCurrent && ranksSortDir === 'desc' ? 'asc' : 'desc';
            const direction = isCurrent ? nextDirection : defaultDir;
            const pip = isCurrent ? (ranksSortDir === 'asc' ? '▲' : '▼') : '•';

            header.css('cursor', 'pointer');
            header.find('.sort-pip').remove();
            header.append(`<span class='sort-pip text-muted' style='font-size: 9px; margin-left: 4px;'>${pip}</span>`);
            header.attr('title', `Sort by ${direction.toUpperCase()}`);
            header.on('click', function() {
                window.location.href = buildURL(ranksType, ranksKL, ranksGroup, ranksEpoch, 1, sortKey, direction);
            });
        });
    });
}

function getDefaultDirection(sortKey) {
    const defaults = {
        overallRank: ranksKL === 'k' ? 'asc' : 'desc',
        shipsDestroyed: 'desc',
        sdRank: 'asc',
        shipsLost: 'desc',
        slRank: 'asc',
        pointsDestroyed: 'desc',
        pdRank: 'asc',
        pointsLost: 'desc',
        plRank: 'asc',
        iskDestroyed: 'desc',
        idRank: 'asc',
        iskLost: 'desc',
        ilRank: 'asc',
    };
    return defaults[sortKey] || 'desc';
}

function applyStyles() {
    $('#type-' + ranksType.replace('ID', '')).addClass('btn-primary');
    $('#epoch-' + ranksEpoch).addClass('btn-primary');
    $('#kl-' + ranksKL).addClass('btn-primary');
    $('#group-' + ranksGroup).addClass('btn-primary');

    $('.epoch-btn').each(updateEpochBtn);
    $('.kl-btn').each(updateKLBtn);
    $('.group-btn').each(updateSoloBtn);

    let page = Number(ranksPage);
    $("#page-1").text(page - 2).toggle((page - 2) >= 1);
    $("#page-2").text(page - 1).toggle((page - 1) >= 1);
    $("#page-3").text(page);
    $("#page-4").text(page + 1).toggle(ranksHasMore == 'y');
    $("#page-5").text(page + 2).toggle(ranksHasMore == 'y');
    $(".page-btn").each(updatePageBtn);
}

function updateEpochBtn() {
    const t = $(this);
    const epoch = t.attr('id').replace('epoch-', '');
    t.attr('href', buildURL(ranksType, ranksKL, ranksGroup, epoch, ranksPage, ranksSortKey, ranksSortDir));
}

function updateKLBtn() {
    const t = $(this);
    const kl = t.attr('id').replace('kl-', '').substr(0, 1);
    t.attr('href', buildURL(ranksType, kl, ranksGroup, ranksEpoch, ranksPage, ranksSortKey, ranksSortDir));
}

function updateSoloBtn() {
    const t = $(this);
    const solo = t.attr('id').replace('group-', '').toLowerCase();
    t.attr('href', buildURL(ranksType, ranksKL, solo, ranksEpoch, ranksPage, ranksSortKey, ranksSortDir));
}

function updatePageBtn() {
    const t = $(this);
    const page = t.text();
    if (t.css('display') == 'none') return t.remove();
    t.attr('href', buildURL(ranksType, ranksKL, ranksGroup, ranksEpoch, page, ranksSortKey, ranksSortDir));
}

const baseURL = '/:ranksType/ranks/:ranksKL/:ranksGroup/:ranksEpoch/:page/';
const baseSortedURL = '/:ranksType/ranks/:ranksKL/:ranksGroup/:ranksEpoch/:page/:sort/:dir/';
function buildURL(type, kl, group, epoch, page, sortKey, sortDir) {
    let url = sortKey ? baseSortedURL : baseURL;

    url = url.replace(':ranksType', type);
    url = url.replace(':ranksKL', kl);
    url = url.replace(':ranksGroup', group);
    url = url.replace(':ranksEpoch', epoch);
    url = url.replace(':page', page);
    if (sortKey) {
        url = url.replace(':sort', encodeURIComponent(sortKey));
        url = url.replace(':dir', encodeURIComponent(sortDir || 'desc'));
    }

    return url;
}
