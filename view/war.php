<?php

function handler($request, $response, $args, $container) {
    $warID = (int) $args['warID'];
$warData = War::getWarInfo($warID);
$warFinished = @$warData['finished'] === true;
//ZLog::log(print_r($warData, true));

$p = array('warID' => $warID);
$kills = Kills::getKills($p);

$topPods = array();
$topIsk = array();
$topPoints = array();
$topKillers = array();
$page = 1;
$pageTitle = "War $warID";

$p['kills'] = true;
if (!$warFinished) {
    $p['pastSeconds'] = (7 * 86400);
}

$top = array();
$top[] = Info::doMakeCommon('Top Characters', 'characterID', Stats::getTop('characterID', $p));
$top[] = Info::doMakeCommon('Top Corporations', 'corporationID', Stats::getTop('corporationID', $p));
$top[] = Info::doMakeCommon('Top Alliances', 'allianceID', Stats::getTop('allianceID', $p));
$top[] = Info::doMakeCommon('Top Ships', 'shipTypeID', Stats::getTop('shipTypeID', $p));
$top[] = Info::doMakeCommon('Top Systems', 'solarSystemID', Stats::getTop('solarSystemID', $p));

$p['limit'] = 5;
$topIsk = array(); //Stats::getTopIsk($p);
unset($p['pastSeconds']);
unset($p['kills']);

// get latest kills
$killsLimit = 50;
$p['limit'] = $killsLimit;
$preKills = Kills::getKills($p);
$kills = array();
$agrID = $warData['aggressor']['id'] ?? 0;
$dfdID = $warData['defender']['id'] ?? 0;

foreach ($preKills as $kill) {
    $victim = $kill['victim'];
    if (@$victim['corporationID'] == $dfdID || @$victim['allianceID'] == $dfdID) {
        $kill['displayAsKill'] = true;
        $kill['victimWarSide'] = 'defender';
        $kill['finalBlowWarSide'] = 'aggressor';
    } else {
        $kill['displayAsLoss'] = true;
        $kill['victimWarSide'] = 'aggressor';
        $kill['finalBlowWarSide'] = 'defender';
    }
    $vics = array();
    foreach (array('characterID', 'corporationID', 'allianceID', 'shipTypeID', 'groupID', 'factionID') as $key) {
        if (isset($kill['victim'][$key])) {
            $vics[] = $kill['victim'][$key];
        }
    }
    $kill['vics'] = implode(',', $vics);
    if (isset($kill['dttm'])) {
        $kill['unixtime'] = $kill['dttm']->toDateTime()->getTimestamp();
    }
    $kills[] = $kill;
}

return $container->get('view')->render($response->withHeader('Cache-Tag', "wars,war,war:$warID"), 'index.html', array('war' => $warData, 'wars' => array($warData), 'topPods' => $topPods, 'topIsk' => $topIsk, 'topPoints' => $topPoints, 'topKillers' => $top, 'kills' => $kills, 'page' => $page, 'pageType' => 'war', 'pager' => false, 'pageTitle' => $pageTitle));

}
