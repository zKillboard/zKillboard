<?php

// Handle content type for compatibility
if (!isset($GLOBALS['capture_render_data'])) {
    $app->contentType('application/json; charset=utf-8');
}
global $mdb, $redis;

$hostname = gethostname();
$res = ['host' => $hostname];

try {
    $res['redis'] = true;
    $res['redis-error'] = null;
} catch (Exception $e) {
    $res['redis'] = false;
    $res['redis-error'] = $e->getMessage(); 
}

try {
    $mdb->findDoc("killmails");
    $res['mongo'] = true;
    $res['mongo-error'] = null;
    $r = $mdb->getDb()->command(['hello' => 1]);
    $master = $r['primary'] ?? '';
    $res['isMongoPrimary'] = str_contains($master, "${hostname}:");
} catch (Exception $e) {
    $res['mongo'] = false;
    $res['mongo-error'] = $e->getMessage();
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// Handle JSON output for compatibility
if (isset($GLOBALS['capture_render_data'])) {
    $GLOBALS['json_output'] = json_encode($res);
    return;
} else {
    echo json_encode($res);
}
