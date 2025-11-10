<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$queuePublish = new RedisQueue('queuePublish');
$minute = date('Hi');

$topKillID = max(1, $mdb->findField('killmails', 'killID', [], ['killID' => -1]));

while ($minute == date('Hi')) {
    $killID = (int) $queuePublish->pop();
    if ($killID > 0 ) {
        if ($redis->get("tobefetched") > 1000 && $killID < ($topKillID - 10000)) continue;
        if ($redis->get("zkb:published:$killID") == "true") continue;
        publish($killID);
        $redis->setex("zkb:published:$killID", 86400, "true");
    } else sleep(1);
}

function publish($killID)
{
    global $mdb, $redis, $imageServer, $esiServer, $fullAddr;

    $kill = $mdb->findDoc('killmails', ['killID' => $killID]);
    $raw = Kills::getEsiKill($killID);
    unset($raw['_id']);

    $zkb = $kill['zkb'];
    $zkb['npc'] = @$kill['npc'];
    $zkb['solo'] = @$kill['solo'];
    $zkb['awox'] = @$kill['awox'];
    $zkb['labels'] = @$kill['labels'];
    $zkb['url'] = "https://zkillboard.com/kill/$killID/";
    $raw['zkb'] = $zkb;

    $hours24 = time() - 86400;
    if ($kill['dttm']->toDateTime()->getTimestamp() < $hours24) return;

    $channels = ['none:*' => true, 'all:*' => true];
    foreach ($kill['involved'] as $involved) {
        $channels['character:' . (int) @$involved['characterID']] = true;
        $channels['corporation:' . (int) @$involved['corporationID']] = true;
        $channels['alliance:' . (int) @$involved['allianceID']] = true;
        $channels['faction:' . (int) @$involved['factionID']] = true;
        $channels['ship:' . (int) @$involved['shipTypeID']] = true;
        $channels['group:' . (int) @$involved['groupID']] = true;
    }
    foreach ($kill['labels'] as $label) {
        $channels['label:' . $label] = true;
    }
    $channels['label:all'] = true;
    $channels["system:" . $kill['system']['solarSystemID']] = true;
    $channels["constellation:" . $kill['system']['constellationID']] = true;
    $channels["region:" . $kill['system']['regionID']] = true;
    $channels["location:" . ((int) @$kill['zkb']['locationID'])] = true;
    if ($zkb['totalValue'] >= 10000000000) $channels['total_value:10b+'] = true;
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
        'ship_type_id' => (int) @$victimInfo['shipTypeID'],
        'group_id' => (int) @$victimInfo['groupID'],
        'url' => "https://zkillboard.com/kill/$killID/",
            ];
    foreach ($channels as $channel) {
        $redisMessage['channel'] = $channel;
        $msg = json_encode($redisMessage, JSON_UNESCAPED_SLASHES);
        $redis->publish($channel, $msg);
    }

    $totalPrice = $kill['zkb']['totalValue'];
    $url = "$fullAddr/kill/$killID/";
    $redisMessage = [
        'action' => 'bigkill',
        'title' => "$name " . $victimInfo['shipName'],
        'iskStr' => Util::formatIsk($totalPrice)." ISK",
        'url' => $url,
        'image' => $imageServer . "types/" . $victimInfo['shipTypeID'] . "/render?size=128"
    ];
    foreach ($channels as $channel) {
        $redisMessage['channel'] = $channel;
        $msg = json_encode($redisMessage, JSON_UNESCAPED_SLASHES);
        $redis->publish('tracker:' . $channel, $msg);
    }
}
