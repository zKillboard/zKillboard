<?php

require_once "../init.php";

use cvweiss\redistools\RedisCache;

global $gankKillBotWebhook;

if ($redis->get("zkb:gankcheck") == "true") exit();

$concord = $mdb->getCollection("killmails")->find(['involved.corporationID' => 1000125])->sort(['killID' => -1])->limit(50000);
$added = [];

while ($concord->hasNext()) {
    $kill = $concord->next();
    if ($kill['killID'] < 68300000) break;
    $systemID = $kill['system']['solarSystemID'];
    $involved = $kill['involved'];
    $victim = $involved[0];
    $likelyVictims = $mdb->find("killmails", ['involved.characterID' => $victim['characterID'], 'killID' => ['$lt' => $kill['killID']]], ['killID' => -1], 5);
    foreach ($likelyVictims as $lvictim) {
        if (in_array($lvictim['killID'], $added) === true) continue;
        if (@$lvictim['involved'][0]['groupID'] == 29) continue;
        if ($systemID != $lvictim['system']['solarSystemID']) continue;

        if ( $lvictim['zkb']['totalValue'] < 1000000 || @$lvictim['warID'] > 0 || @$lvictim['ganked'] == true || ($kill['killID'] - $lvictim['killID']) > 200 || $lvictim['awox'] == true) continue;
        $concorded = false;
        foreach ($lvictim['involved'] as $i) {
            if (@$i['corporationID'] == 1000125) {
                $concorded = true;
            }
        }
        $raw = Kills::getEsiKill($lvictim['killID']);
        $valid = false;
        foreach ($raw['attackers'] as $a) {
            if (@$a['character_id'] == $victim['characterID'] && $a['damage_done'] >= 0) {
                $valid = true;
            }
        }
        if ($concorded == false && $valid == true) {
            $added[] = $lvictim['killID'];
            $mdb->set("killmails", ['killID' => $lvictim['killID']], ['ganked' => true]);
            $mdb->getCollection("killmails")->update(['killID' => $lvictim['killID']], ['$addToSet' => ['labels' => 'ganked']]);
            $mdb->getCollection("ninetyDays")->update(['killID' => $lvictim['killID']], ['$addToSet' => ['labels' => 'ganked']]);
            $mdb->getCollection("oneWeek")->update(['killID' => $lvictim['killID']], ['$addToSet' => ['labels' => 'ganked']]);
            Util::out("Marking " . $lvictim['killID'] . " as ganked.");
            RedisCache::delete("killDetail:" . $lvictim['killID']);
            RedisCache::delete( "zkb::detail:" . $lvictim['killID']);
        }
    }
}

$redis->setex("zkb:gankcheck", 900, "true");
