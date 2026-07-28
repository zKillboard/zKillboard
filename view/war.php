<?php

function handler($request, $response, $args, $container) {
    $warID = (int) $args['warID'];
$warData = War::getWarInfo($warID);
$warFinished = @$warData['finished'] === true;
//ZLog::log(print_r($warData, true));

$p = array('warID' => $warID);

$topPods = array();
$topIsk = array();
$topPoints = array();
$topKillers = array();
$page = 1;
$pageTitle = "War $warID";

$p['kills'] = true;

$top = array();
$top[] = Info::doMakeCommon('Top Characters', 'characterID', Stats::getTop('characterID', $p));
$top[] = Info::doMakeCommon('Top Corporations', 'corporationID', Stats::getTop('corporationID', $p));
$top[] = Info::doMakeCommon('Top Alliances', 'allianceID', Stats::getTop('allianceID', $p));
$top[] = Info::doMakeCommon('Top Ships', 'shipTypeID', Stats::getTop('shipTypeID', $p));
$top[] = Info::doMakeCommon('Top Systems', 'solarSystemID', Stats::getTop('solarSystemID', $p));

$p['limit'] = 5;
$topIsk = array(); //Stats::getTopIsk($p);
unset($p['kills']);

// get latest kills
$killsLimit = 50;
$p['limit'] = $killsLimit;
$kills = Kills::getKills($p, true, false);

return $container->get('view')->render($response->withHeader('Cache-Tag', "www,wars,war,war:$warID"), 'index.pug', array('war' => $warData, 'wars' => array($warData), 'topPods' => $topPods, 'topIsk' => $topIsk, 'topPoints' => $topPoints, 'topKillers' => $top, 'kills' => $kills, 'page' => $page, 'pageType' => 'war', 'pager' => false, 'pageTitle' => $pageTitle, 'killListRowV2AttackerIDs' => [(int) ($warData['aggressor']['id'] ?? 0)]));

}
