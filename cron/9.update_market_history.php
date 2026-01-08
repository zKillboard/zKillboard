<?php

require_once "../init.php";


if ($kvc->get("zkb:noapi") == "true") exit();

$rset = "zkb:updatemarket";
$rsetLoad = "zkb:updatemarket:" . date('Ymd', time() - 40500);

if ($redis->get($rsetLoad) == "true") exit();

$guzzler = new Guzzler(5);

if ($redis->scard($rset) == 0) {
    msuccess($guzzler, ['page' => 0, 'rset' => $rset, 'redis' => $redis], "---");
    $guzzler->finish();
}

$fetched = [];
$minute = date("Hi");
while ($minute == date("Hi") && $redis->scard($rset) > 0) {
    $type_id = (int) $redis->srandmember($rset);

    // Ensure the type_id is public and has a market group https://github.com/esi/esi-issues/issues/1429
    $item = $mdb->findDoc("information", ['type' => 'typeID', 'id' => $type_id]);
    if (@$item['published'] !== true || ((int) @$item['market_group_id']) == 0) {
        $redis->srem($rset, $type_id);
        continue;
    }
    if (in_array($type_id, $fetched)) { sleep(1); continue; }
    $fetched[] = $type_id;

    $guzzler->call("https://esi.evetech.net/markets/10000002/history?type_id=$type_id", "isuccess", "ifail", ['mdb' => $mdb, 'redis' => $redis, 'type_id' => $type_id, 'rset' => $rset]);
}
$guzzler->finish();

if ($redis->scard($rset) == 0) $redis->setex($rsetLoad, 86400, "true");

function isuccess(&$guzzler, $params, $content) {
    $redis = $params['redis'];
    $rset = $params['rset'];
    $mdb = $params['mdb'];
    $type_id = (int) $params['type_id'];

    $json = json_decode($content, true);
    $inserts = 0;
    foreach ($json as $record) {
        $record["type_id"] = $type_id;
        if ($mdb->findDoc("markethistory", ['type_id' => $type_id, 'date' => $record['date']]) == null) {                                $mdb->insert("markethistory", $record);
            $inserts++;
        }
    }
    //if ($inserts > 0) Util::out("Market History type_id $type_id - $inserts inserts.");$json = json_decode($content, true);
    $redis->srem($rset, $type_id);
}

function ifail(&$guzzler, $params, $ex) {
    $type_id = (int) $params['type_id'];
    $rset = $params['rset'];
    $redis = $params['redis'];

    $redis>srem($rset, $type_id);
    Util::out("market history item fetch fail: $type_id with http code " . $ex->getCode());
}

function msuccess(&$guzzler, $params, $content) {
    $rset = $params['rset'];
    $page = $params['page'];
    $redis = $params['redis'];

    $json = json_decode($content == "---" ? "[]" : $content, true);
    foreach ($json as $type_id) {
        $redis->sadd($rset, $type_id);
    }

    if ($content == "---" || sizeof($json) == 1000) {
        $page++;
        Util::out("Fetching market ids page $page");
        $guzzler->call("https://esi.evetech.net/markets/10000002/types?page=$page", "msuccess", "mfail", ['page' => $page, 'redis' => $redis, 'rset' => $rset]);
    }
}

function mfail(&$guzzler, &$params, $ex) {
    Util::out(print_r($ex, true));
    exit();
}
