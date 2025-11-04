<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis;

    $allis = addStats($mdb->find("information", ['type' => 'allianceID', 'war_eligible' => true, 'has_wars' => false ], ['memberCount' => -1], 100));
    $corps = addStats($mdb->find("information", ['type' => 'corporationID', 'allianceID' => 0, 'war_eligible' => true, 'has_wars' => false ], ['memberCount' => -1], 100));

    return $container['view']->render($response, 'war_eligible.html', ['allis' => $allis, 'corps' => $corps]);
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
