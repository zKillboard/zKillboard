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
    $raw  = $mdb->findDoc('esimails', ['killmail_id' => $killID]);
    unset($raw['_id']);
    unset($raw['sequence']);
    $raw['zkb'] = $kill['zkb'];
    $redis->publish("killstream", json_encode($raw));

    $hours24 = time() - 86400;
    if ($kill['dttm']->sec < $hours24) return;

    $channels = ['none:*' => true, 'all:*' => true];
    foreach ($kill['involved'] as $involved) {
        $channels['character:' . (int) @$involved['characterID']] = true;
        $channels['corporation:' . (int) @$involved['corporationID']] = true;
        $channels['alliance:' . (int) @$involved['allianceID']] = true;
        $channels['faction:' . (int) @$involved['factionID']] = true;
        $channels['ship:' . (int) @$involved['shipTypeID']] = true;
        $channels['group:' . (int) @$involved['groupID']] = true;
    }
    $channels["system:" . $kill['system']['solarSystemID']] = true;
    $channels["constellation:" . $kill['system']['constellationID']] = true;
    $channels["region:" . $kill['system']['regionID']] = true;
    $channels["location:" . $kill['zkb']['locationID']] = true;
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
        'url' => "https://zkillboard.com/kill/$killID/"
            ];
    $msg = json_encode($redisMessage, JSON_UNESCAPED_SLASHES);
    foreach ($channels as $channel) {
        $redis->publish($channel, $msg);
    }
}
