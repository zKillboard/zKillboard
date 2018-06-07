<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$queuePublish = new RedisQueue('queuePublish');
$minute = date('Hi');

while ($minute == date('Hi')) {
    $killID = (int) $queuePublish->pop();
    if ($killID > 0 ) {
        publish($killID);
    } else sleep(1);
}

function publish($killID)
{
    global $mdb, $redis, $imageServer;

    $kill = $mdb->findDoc('killmails', ['killID' => $killID]);
    unset($kill['_id']);
    unset($kill['sequence']);
    $redis->publish("killstream", json_encode($kill, true));

    $channels = [];
    foreach ($kill['involved'] as $involved) {
        $channels['character:' . @$involved['characterID']] = true;
        $channels['corporation:' . @$involved['corporationID']] = true;
        $channels['alliance:' . @$involved['allianceID']] = true;
    }
    $channels = array_keys($channels);

    $victimInfo = $kill['involved'][0];
    Info::addInfo($victimInfo);
    if (!isset($victimInfo['characterName'])) return;
    $name = $victimInfo['characterName'];
    $name .= (strtolower(substr($name, -1))) == 's' ? "'" : "'s";
    $redisMessage = [
        'action' => 'littlekill',
        'killID' => $killID,
        'character_id' => (int) @$victimInfo['characterID'],
        'corporation_id' => (int) @$victimInfo['corporationID'],
        'alliance_id' => (int)  @$victimInfo['allianceID'],
        'ship_type_id' => (int) $victimInfo['shipTypeID'],
        'url' => "https://zkillboard.com/kill/$killID/",
        'images' => $imageServer
            ];
    $msg = json_encode($redisMessage, JSON_UNESCAPED_SLASHES);
    foreach ($channels as $channel) {
        $redis->publish($channel, $msg);
    }
}
