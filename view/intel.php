<?php

global $redis;

$months = 3;

$data = array();
$parameters = ['groupID' => 30, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
$data['titans']['data'] = unserialize($redis->get("zkb:titans"));
$data['titans']['title'] = 'Titans';

$parameters = ['groupID' => 659, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
$data['supercarriers']['data'] = unserialize($redis->get("zkb:supers"));
$data['supercarriers']['title'] = 'Supercarriers';

$app->render('intel.html', array('data' => $data));
