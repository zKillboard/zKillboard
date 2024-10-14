$(document).ready(applyStyles);

function applyStyles() {
    $('#type-' + ranksType.replace('ID', '')).addClass('btn-primary');
    $('#epoch-' + ranksEpoch).addClass('btn-primary');
    $('#kl-' + ranksKL).addClass('btn-primary');
    $('#group-' + ranksGroup).addClass('btn-primary');

    $('.epoch-btn').each(updateEpochBtn);
    $('.kl-btn').each(updateKLBtn);

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
    t.attr('href', `/${ranksType}/ranks/${ranksKL}/${epoch}/${ranksPage}/`);
}

function updateKLBtn() {
    const t = $(this);
    const kl = t.attr('id').replace('kl-', '').substr(0, 1);
    t.attr('href', `/${ranksType}/ranks/${kl}/${ranksEpoch}/${ranksPage}/`);
}

function updatePageBtn() {
    const t = $(this);
    const page = t.text();
    if (t.css('display') == 'none') return t.remove();
    t.attr('href', `/${ranksType}/ranks/${ranksKL}/${ranksEpoch}/${page}/`);
}
