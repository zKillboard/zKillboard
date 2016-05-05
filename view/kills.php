<?php

$limit = 50;
$maxPage = 100;
if ($page > $maxPage && $type == '') {
    $app->redirect("/kills/page/$maxPage/");
}
if ($page > $maxPage && $type != '') {
    $app->redirect("/kills/$type/page/$maxPage/");
}

switch ($type) {
    case '5b':
        $kills = Kills::getKills(['iskValue' => 5000000000]);
    break;
    case '10b':
        $kills = Kills::getKills(['iskValue' => 10000000000]);
    break;
    case 'bigkills':
        $kills = Kills::getKills(array('groupID' => array(547, 485, 513, 902, 941, 30, 659, 883), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'citadels':
        $kills = Kills::getKills(array('groupID' => array(1657), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'awox':
        $kills = Kills::getKills(['awox' => true, 'page' => $page]);
    break;
    case 't1':
        $kills = Kills::getKills(array('groupID' => array(419, 27, 29, 547, 26, 420, 25, 28, 941, 463, 237, 31), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 't2':
        $kills = Kills::getKills(array('groupID' => array(324, 898, 906, 540, 830, 893, 543, 541, 833, 358, 894, 831, 902, 832, 900, 834, 380), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 't3':
        $kills = Kills::getKills(array('groupID' => array(963), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'frigates':
        $kills = Kills::getKills(array('groupID' => array(324, 893, 25, 831, 237), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'destroyers':
        $kills = Kills::getKills(array('groupID' => array(420, 541), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'cruisers':
        $kills = Kills::getKills(array('groupID' => array(906, 26, 833, 358, 894, 832, 963), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'battlecruisers':
        $kills = Kills::getKills(array('groupID' => array(419, 540), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'battleships':
        $kills = Kills::getKills(array('groupID' => array(27, 898, 900), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'solo':
        $kills = Kills::getKills(array('losses' => true, 'solo' => true, 'limit' => $limit, '!shipTypeID' => 670, '!groupID' => array(237, 31), 'cacheTime' => 3600, 'page' => $page));
    break;
    case 'capitals':
        $kills = Kills::getKills(array('groupID' => array(547, 485, 1538), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'freighters':
        $kills = Kills::getKills(array('groupID' => array(513, 902, 941), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'supers':
        $kills = Kills::getKills(array('groupID' => array(30, 659), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'dust':
        $kills = Kills::getKills(array('groupID' => array(351064, 351210), 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'dust_vehicles':
        $kills = Kills::getKills(array('groupID' => '351210', 'limit' => $limit, 'cacheTime' => 300, 'losses' => true, 'page' => $page));
    break;
    case 'lowsec':
        $kills = Kills::getKills(array('lowsec' => true, 'page' => $page));
    break;
    case 'highsec':
        $kills = Kills::getKills(array('highsec' => true, 'page' => $page));
    break;
    case 'nullsec':
        $kills = Kills::getKills(array('nullsec' => true, 'page' => $page));
    break;
    case 'w-space':
        $kills = Kills::getKills(array('w-space' => true, 'page' => $page));
    break;
    default:
        $kills = Kills::getKills(array('combined' => true, 'page' => $page));
    break;
}

$app->render('kills.html', array('kills' => $kills, 'page' => $page, 'killsType' => $type, 'pager' => true));
