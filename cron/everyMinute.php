<?php

use cvweiss\redistools\RedisTtlCounter;

require_once "../init.php";

$load = Util::getLoad();
$redisLoad = (int) $redis->get("zkb:load");
if ($load >= 15 && $redisLoad < 20) {
    $redis->incrBy("zkb:load", 1);
    $redisLoad++;
} 
if ($redis->get("zkb:reinforced") == true && $load >= 13) {
    // Do nothing, maintain reinforced
}
else if ($redisLoad > 0 && $load < 15) {
    $redis->incrBy("zkb:load", -1);
    $redisLoad--;
}
$redis->set("zkb:reinforced", ($redisLoad >= 15 && $allowReinforced ? 1 : 0));

// Set the top kill for api requests to use
$topKillID = $mdb->findField('killmails', 'killID', [], ['killID' => -1]);
$redis->setex('zkb:topKillID', 86400, $topKillID);

$redis->set('zkb:TopIsk', json_encode(Stats::getTopIsk(array('pastSeconds' => (7 * 86400), 'limit' => 6, 'npc' => false))));
$redis->set('zkb:TopIskShips', json_encode(Stats::getTopIsk(array('categoryID' => 6, 'pastSeconds' => (7 * 86400), 'limit' => 6, 'npc' => false))));
$redis->set('zkb:TopIskStructures', json_encode(Stats::getTopIsk(array('categoryID' => ['$ne' => 6], 'pastSeconds' => (7 * 86400), 'limit' => 6, 'npc' => false))));
$redis->set('zkb:TopSpecialLosses', json_encode(Stats::getTopIsk(array('regionID' => 10000004, 'pastSeconds' => (7 * 86400), 'limit' => 6, 'npc' => false))));

$redis->set("zkb:totalChars", $redis->zcard("zkb:characterID"));
$redis->set("zkb:totalCorps", $redis->zcard("zkb:corporationID"));
$redis->set("zkb:totalAllis", $redis->zcard("zkb:allianceID"));

$arr = [];
$greenTotal = 0;
$redTotal = 0;
for ($i = 0; $i < 7; $i++) {
    $green = "zkb:loot:green:" . date('Y-m-d', time() - ($i * 86400));
    $red = "zkb:loot:red:" . date('Y-m-d', time() - ($i * 86400));
    $greenTotal += $redis->get($green);
    $redTotal += $redis->get($red);
}
if ($redis->get("tqCountInt") >= 1000) {
    $arr[] = ['typeID' => 0, 'name' => 'Loot Fairy', 'dV' => $greenTotal, 'lV' => $redTotal];
    $items = [40520, 44992];
    $date = date('Ymd');
    foreach ($items as $item) {
        $d =  new RedisTtlCounter("ttlc:item:$item:dropped", 86400 * 7);
        $dSize = $d->count();
        $l = new RedisTtlCounter("ttlc:item:$item:destroyed", 86400 * 7);
        $lSize = $l->count();
        $name = Info::getInfoField("typeID", $item, "name");
        $price = Price::getItemPrice($item, $date);
        $arr[] = ['typeID' => $item, 'name' => $name, 'price' => $price, 'dropped' => $dSize, 'destroyed' => $lSize, 'dV' => ($dSize * $price), 'lV' => ($lSize * $price)];
    }
    $redis->set("zkb:ttlc:items:index", json_encode($arr));
}

$i = Mdb::group("payments", ['characterID'], ['ref_type' => 'player_donation', 'dttm' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1, 'dttm' => -1], 10);
Info::addInfo($i);
$redis->set("zkb:topDonators", json_encode($i));

$result = Mdb::group("sponsored", ['killID'], ['entryTime' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1], 6);
$sponsored = [];
foreach ($result as $kill) {
    if ($kill['iskSum'] <= 0) continue;
    $killmail = $mdb->findDoc("killmails", ['killID' => $kill['killID']]);
    if ($killmail == null) continue;
    Info::addInfo($killmail);
    $killmail['victim'] = $killmail['involved'][0];
    $killmail['zkb']['totalValue'] = $kill['iskSum'];

    $sponsored[$kill['killID']] = $killmail;
}
$redis->set("zkb:sponsored", json_encode($sponsored));

$unique = sizeof($mdb->getCollection("scopes")->distinct("corporationID", ['scope' => 'esi-killmails.read_corporation_killmails.v1', 'iterated' => true]));
$redis->set("tqCorpApiESICount", $unique);

$redisMessage = null;
if ($redis->get("twitch-online") != "") {
    $redisMessage = ['action' => 'twitch-online', 'channel' => $redis->get("twitch-online")];
} else {
    $redisMessage = ['action' => 'twitch-offline'];
}
$redis->publish('public', json_encode($redisMessage, JSON_UNESCAPED_SLASHES));
