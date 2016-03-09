<?php

$months = 3;

$data = array();
$parameters = ['groupID' => 30, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
$data['titans']['data'] = Stats::getTop('characterID', $parameters);
$data['titans']['title'] = 'Titans';

$parameters = ['groupID' => 659, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
$data['supercarriers']['data'] = Stats::getTop('characterID', $parameters);
$data['supercarriers']['title'] = 'Supercarriers';

$app->render('intel.html', array('data' => $data));
