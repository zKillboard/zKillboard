<?php

$alltime = false;
$parameters = array();
// $time is an array
if (!isset($time)) {
    $parameters = array('limit' => 10, 'kills' => true);
}
    switch ($page) {
        case 'monthly':
            $parameters['year'] = date('Y');
            $parameters['month'] = date('n');
            break;
        case 'weekly':
            $parameters['year'] = date('Y');
            $parameters['week'] = date('W');
            break;
        default:
            die('Not supported yet.');
    }

$topLists = array();
if ($type == 'kills') {
    $topLists[] = array('type' => 'character', 'data' => Stats::getTopPilots($parameters, $alltime));
    $topLists[] = array('type' => 'corporation', 'data' => Stats::getTopCorps($parameters, $alltime));
    $topLists[] = array('type' => 'alliance', 'data' => Stats::getTopAllis($parameters, $alltime));
    $topLists[] = array('type' => 'ship', 'data' => Stats::getTopShips($parameters, $alltime));
    $topLists[] = array('type' => 'system', 'data' => Stats::getTopSystems($parameters, $alltime));
    //$topLists[] = array("type" => "weapon", "data" => Stats::getTopWeapons($parameters, $alltime));
    $parameters['!factionID'] = 0;
    $topLists[] = array('name' => 'Top Faction Characters', 'type' => 'character', 'data' => Stats::getTopPilots($parameters, $alltime));
    $topLists[] = array('name' => 'Top Faction Corporations', 'type' => 'corporation', 'data' => Stats::getTopCorps($parameters, $alltime));
    $topLists[] = array('name' => 'Top Faction Alliances', 'type' => 'alliance', 'data' => Stats::getTopAllis($parameters, $alltime));
} elseif ($type == 'points') {
    $topLists[] = array('name' => 'Top Character Points', 'ranked' => 'Points', 'type' => 'character', 'data' => Stats::getTopPointsPilot($parameters));
    $topLists[] = array('name' => 'Top Corporation Points', 'ranked' => 'Points', 'type' => 'corporation', 'data' => Stats::getTopPointsCorp($parameters));
    $topLists[] = array('name' => 'Top Alliance Points', 'ranked' => 'Points', 'type' => 'alliance', 'data' => Stats::getTopPointsAlli($parameters));
    $parameters['!factionID'] = 0;
    $topLists[] = array('name' => 'Top Faction Character Points', 'ranked' => 'Points', 'type' => 'character', 'data' => Stats::getTopPointsPilot($parameters));
    $topLists[] = array('name' => 'Top Faction Corporation Points', 'ranked' => 'Points', 'type' => 'corporation', 'data' => Stats::getTopPointsCorp($parameters));
    $topLists[] = array('name' => 'Top Faction Alliance Points', 'ranked' => 'Points', 'type' => 'alliance', 'data' => Stats::getTopPointsAlli($parameters));
}

$app->render('top.html', array('topLists' => $topLists, 'page' => $page, 'type' => $type));
