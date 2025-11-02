<?php

global $redis;

$array = json_decode($redis->get("zkb:topKillsByShip"), true);

// Handle rendering for Slim 3 compatibility
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['render_template'] = 'bigisk.html';
	$GLOBALS['render_data'] = array('topSet' => $array);
} else {
	// Fallback for any remaining Slim 2 usage
	$app->render('bigisk.html', array('topSet' => $array));
}
