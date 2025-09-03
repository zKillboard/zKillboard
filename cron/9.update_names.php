<?php

require_once "../init.php";

if ($redis->get("zkb:noapi") == "true") exit();

$rset = "zkb:updatenames";
$rsetLoad = "zkb:updatenames:" . date('Ymd');

$guzzler = new Guzzler();

if ($redis->get($rsetLoad) != "true" && $redis->scard($rset) <= 100) {
    addToRset($redis, $rset, $mdb->getCollection('ninetyDays')->distinct('involved.characterID'));
    addToRset($redis, $rset, $mdb->getCollection('ninetyDays')->distinct('involved.corporationID'));
    addToRset($redis, $rset, $mdb->getCollection('ninetyDays')->distinct('involved.allianceID'));
}
$redis->srem($rset, "");
$redis->srem($rset, "1");

$minute = date("Hi");

do {
    $set = [];
    while (sizeof($set) < 1000 && sizeof($set) < $redis->scard($rset)) {
        $next = $redis->srandmember($rset);
        if (!in_array($next, $set)) 
            $set[] = $next;
    }
    if (sizeof($set) > 0) {
        doCall($guzzler, $mdb, $redis, $rset, $set);
        $guzzler->finish();
    }
    sleep(15);
} while ($minute == date("Hi"));

if ($redis->scard($rset) == 0) $redis->setex($rsetLoad, 86400, "true");

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
        $match = ['type' => $row['category'] . "ID", 'id' => $row['id']];
        $current = $mdb->findDoc("information", $match);
        if (@$current['name'] != $name) {
            Util::out("Name update " . @current['name'] . " -> $name");
            $mdb->set("information", ['type' => $row['category'] . "ID", 'id' => $row['id']], ['name' => $name, 'l_name' => strtolower($name)]);
        }
        $redis->srem($rset, $row['id']);
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
