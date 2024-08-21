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
        return;
}

$topLists = array();
if ($type == 'kills') {
    $topLists[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $parameters));
    $topLists[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters));
    $topLists[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $parameters));
    $topLists[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $parameters));
    $topLists[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $parameters));
    $parameters['!factionID'] = 0;
    $topLists[] = array('name' => 'Top Faction Characters', 'type' => 'character', 'data' => Stats::getTop('characterID', $parameters));
    $topLists[] = array('name' => 'Top Faction Corporations', 'type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters));
}

$app->render('top.html', array('topLists' => $topLists, 'page' => $page, 'type' => $type));
