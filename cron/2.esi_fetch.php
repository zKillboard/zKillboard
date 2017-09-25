<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once "../init.php";

$guzzler = new Guzzler(25, 10);
$rows = $mdb->getCollection("crestmails")->find();
$esimails = $mdb->getCollection("esimails");

$redis->sort("esi2Fetch", ['sort' => 'desc']);

$count = 0;
$minute = date("Hi");
while ($minute == date("Hi")) {
    while ($redis->llen("esi2Fetch") > 0 && $minute == date("Hi")) {
        $raw = $redis->lpop("esi2Fetch");
        $row = split(":", $raw);
        $killID = $row[0];

        $hash = $row[1];
        if (strlen($hash) == 0) continue;
        $url = "https://esi.tech.ccp.is/v1/killmails/$killID/$hash/";
        $params = ['row' => $row, 'mdb' => $mdb, 'redis' => $redis, 'killID' => $killID, 'esimails' => $esimails, 'raw' => $raw];
        $guzzler->call($url, "success", "fail", $params);

        $guzzler->tick();
        $count++;
    }
    usleep(100000);
    $guzzler->tick();
}
$guzzler->finish();

function fail($guzzler, $params, $ex) {
    $raw = $params['raw'];
    $redis = $params['redis'];

    Util::out("esi fetch failure: ($raw) " . $ex->getMessage());
    $redis->rpush("esi2Fetch", $raw);
    $sucFail = new RedisTtlCounter('ttlc:EsiFail', 300);
    $sucFail->add(uniqid());
}

function success(&$guzzler, &$params, &$content) {
    $esimails = $params['esimails'];
    $doc = json_decode($content, true);
    $esimails->insert($doc);

    $queueProcess = new RedisQueue('queueProcess');
    $queueProcess->push($params['killID']);

    $sucFail = new RedisTtlCounter('ttlc:EsiSuccess', 300);
    $sucFail->add(uniqid());
}
