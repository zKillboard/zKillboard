<?php

global $mdb, $redis;

$allis = addStats($mdb->find("information", ['type' => 'allianceID', 'war_eligible' => true, 'has_wars' => false ], ['memberCount' => -1], 100));
$corps = addStats($mdb->find("information", ['type' => 'corporationID', 'allianceID' => 0, 'war_eligible' => true, 'has_wars' => false ], ['memberCount' => -1], 100));


if (isset($GLOBALS['route_args'])) {
    $GLOBALS['render_template'] = 'war_eligible.html';
    $GLOBALS['render_data'] = ['allis' => $allis, 'corps' => $corps];
} else {
    $app->render('war_eligible.html', ['allis' => $allis, 'corps' => $corps]);
}

function addStats($arr) {
    global $mdb;

    $ret = [];
    foreach ($arr as $a) {
        $stats = $mdb->findDoc("statistics", ['type' => $a['type'], 'id' => $a['id']]);
        if ($stats == null) $stats = [];
        $ret[] = array_merge($a, $stats);
    }
    return $ret;
}
