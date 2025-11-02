<?php

$baseP = array('limit' => 10, 'kills' => true, 'pastSeconds' => 3600, 'cacheTime' => 30, 'npc' => false);
$types = ['all', 'nullsec', 'lowsec', 'highsec', 'w-space', 'solo'];

if (!in_array($type, $types)) {
	if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
		$GLOBALS['redirect_url'] = '/';
		$GLOBALS['redirect_status'] = 302;
		return;
	} else {
		return $app->redirect('/', 302);
	}
}

$allLists = [];

$parameters = $baseP;
$topKillers = [];
if ($type != 'all') $parameters[$type] = true;

$topKillers[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $parameters));
$topKillers[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters));
$topKillers[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $parameters));

$topKillers[] = array('type' => 'faction', 'data' => Stats::getTop('factionID', $parameters));
$topKillers[] = array('type' => 'location', 'data' => Stats::getTop('locationID', $parameters));
$topKillers[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $parameters));
$topKillers[] = array('type' => 'region', 'data' => Stats::getTop('regionID', $parameters));

$topKillers[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $parameters));
$topKillers[] = array('type' => 'group', 'data' => Stats::getTop('groupID', $parameters));

unset($parameters['kills']);
$parameters['losses'] = true;
$topLosers = [];
$topLosers[] = array('type' => 'character', 'ranked' => 'Losses', 'data' => Stats::getTop('characterID', $parameters));
$topLosers[] = array('type' => 'corporation', 'ranked' => 'Losses', 'data' => Stats::getTop('corporationID', $parameters));
$topLosers[] = array('type' => 'alliance', 'ranked' => 'Losses', 'data' => Stats::getTop('allianceID', $parameters));

$topLosers[] = array('type' => 'faction', 'ranked' => 'Losses', 'data' => Stats::getTop('factionID', $parameters));
$topLosers[] = array('type' => 'ship', 'ranked' => 'Losses', 'data' => Stats::getTop('shipTypeID', $parameters));
$topLosers[] = array('type' => 'group', 'ranked' => 'Losses', 'data' => Stats::getTop('groupID', $parameters));

$allLists[$type] = ['topKillers' => $topKillers, 'topLosers' => $topLosers];

if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['render_template'] = 'lasthour.html';
	$GLOBALS['render_data'] = ['allLists' => $allLists, 'time' => date('H:i'), 'type' => $type, 'types' => $types];
} else {
	$app->render('lasthour.html', ['allLists' => $allLists, 'time' => date('H:i'), 'type' => $type, 'types' => $types]);
}
