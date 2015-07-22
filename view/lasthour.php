<?php

$parameters = array('limit' => 10, 'kills' => true, 'pastSeconds' => 3600, 'cacheTime' => 30);
$alltime = false;

$topKillers[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $parameters));
$topKillers[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters));
$topKillers[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $parameters));

$topKillers[] = array('type' => 'faction', 'data' => Stats::getTop('factionID', $parameters));
$topKillers[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $parameters));
$topKillers[] = array('type' => 'region', 'data' => Stats::getTop('regionID', $parameters));

$topKillers[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $parameters));
$topKillers[] = array('type' => 'group', 'data' => Stats::getTop('groupID', $parameters));

unset($parameters['kills']);
$parameters['losses'] = true;
$topLosers[] = array('type' => 'character', 'ranked' => 'Losses', 'data' => Stats::getTop('characterID', $parameters));
$topLosers[] = array('type' => 'corporation', 'ranked' => 'Losses', 'data' => Stats::getTop('corporationID', $parameters));
$topLosers[] = array('type' => 'alliance', 'ranked' => 'Losses', 'data' => Stats::getTop('allianceID', $parameters));

$topLosers[] = array('type' => 'faction', 'ranked' => 'Losses', 'data' => Stats::getTop('factionID', $parameters));
$topLosers[] = array('type' => 'ship', 'ranked' => 'Losses', 'data' => Stats::getTop('shipTypeID', $parameters));
$topLosers[] = array('type' => 'group', 'ranked' => 'Losses', 'data' => Stats::getTop('groupID', $parameters));

$app->render('lasthour.html', array('topKillers' => $topKillers, 'topLosers' => $topLosers, 'time' => date('H:i')));
