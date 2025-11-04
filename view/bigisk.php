<?php

function handler($request, $response, $args, $container) {
	global $redis;

	$array = json_decode($redis->get("zkb:topKillsByShip"), true);
	$data = array('topSet' => $array);
	
	return $container->get('view')->render($response, 'bigisk.html', $data);
}
