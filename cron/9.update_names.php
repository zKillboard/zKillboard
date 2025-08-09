<?php

require_once "../init.php";

$rset = "zkb:updatenames";

$guzzler = new Guzzler();

if ($redis->scard($rset) == 0) {
    addToRset($redis, $rset, $mdb->getCollection('ninetyDays')->distinct('involved.characterID'));
    addToRset($redis, $rset, $mdb->getCollection('ninetyDays')->distinct('involved.corporationID'));
    addToRset($redis, $rset, $mdb->getCollection('ninetyDays')->distinct('involved.allianceID'));
}
$redis->srem($rset, "");
$redis->srem($rset, "1");

$set = [];
while (sizeof($set) < 1000 && sizeof($set) < $redis->scard($rset)) {
    $next = $redis->srandmember($rset);
    if (!in_array($next, $set)) 
        $set[] = $next;
}
doCall($guzzler, $mdb, $redis, $rset, $set);
$guzzler->finish();

function doCall($guzzler, $mdb, $redis, $rset, $set) {
    $guzzler->call("https://esi.evetech.net/universe/names", "success", "fail", ['mdb' => $mdb, 'rset' => $rset, 'redis' => $redis, 'set' => $set], [], 'POST_JSON', json_encode($set));
}

function success(&$guzzler, &$params, &$content)
{
    $mdb = $params['mdb'];
    $rset = $params['rset'];
    $redis = $params['redis'];

    $rows = json_decode($content, true);
    foreach ($rows as $row) {
        $name = $row['name'];
        $r = $mdb->set("information", ['type' => $row['category'] . "ID", 'id' => $row['id']], ['name' => $name, 'l_name' => strtolower($name)]);
        if ($r['n'] > 0) $redis->srem($rset, $row['id']);
    }
}

function fail($guzzler, $params, $ex)
{
    $mdb = $params['mdb'];
    $rset = $params['rset'];
    $redis = $params['redis'];

    $set = $params['set'];

    if (sizeof($set) == 1) {
        Util::out("Failure to resolve name for ID: " . $set[0] . " - " . $ex->getMessage());
        $redis->srem($rset, $set[0]);
        return;
    }

    $half = ceil(count($set) / 2);
    list($part1, $part2) = array_chunk($set, $half);

    doCall($guzzler, $mdb, $redis, $rset, $part1);
    doCall($guzzler, $mdb, $redis, $rset, $part2);
}

function addToRSet($redis, $rset, $cursor) {
    foreach ($cursor as $row) {
        $redis->sadd($rset, $row);
    }

}
