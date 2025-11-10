<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$queueSocial = new RedisQueue('queueSocial');
$minute = date('Hi');

while ($minute == date('Hi')) {
    $killID = $queueSocial->pop();
    if ($killID > 0 ) {
        if ($beSocial === true) beSocial($killID);
    } else sleep(1);
}

function beSocial($killID)
{
    global $mdb, $redis, $fullAddr, $twitterName, $imageServer, $queueSocial, $bigKillBotWebhook;

    $twitMin = 10000000000;
    $kill = $mdb->findDoc('killmails', ['killID' => $killID]);

    $hours24 = time() - 86400;
    $victimInfo = $kill['involved'][0];

    // Don't announce NPC Sotiyos
    if ($victimInfo['corporationID'] < 1999999 && @$victimInfo['characterID'] == 0) return;

    $totalPrice = $kill['zkb']['totalValue'];
    if ($kill['vGroupID'] == 902) $twitMin += 5000000000; // Jump Freighters, 15b
    if (in_array($kill['vGroupID'], [1657, 1404, 1406])) $twitMin = 25000000000; // Citadels, Eng. Complexes, and Refineries, 25b
    if ($kill['vGroupID'] == 883) $twitMin += 5000000000; // Rorquals, 15b
    $noTweet = $kill['dttm']->toDateTime()->getTimestamp() < $hours24 || $victimInfo == null || $totalPrice < $twitMin;
    if (((int) @$kill['locationID']) == 60012256 && $kill['attackerCount'] > 100 && $totalPrice > 1000000000) $noTweet = false; // whack a bot
    if ($noTweet) {
        return;
    }

    Info::addInfo($victimInfo);

    $url = "$fullAddr/kill/$killID/";
    $message = $victimInfo['shipName'].' worth '.Util::formatIsk($totalPrice)." ISK was destroyed! $url";
    $attempts = 0;
    do {
        $name = getName($victimInfo);
        if ($name == "") sleep(1);
    } while ($name == "" && $attempts < 10);
    if ($name == "") {
        sleep(1);
        $queueSocial->push($killID);
        return;
    }
    $message = adjustMessage($name, $message);

    $redisMessage = [
        'action' => 'bigkill',
        'title' => "$name " . $victimInfo['shipName'],
        'iskStr' => Util::formatIsk($totalPrice)." ISK",
        'url' => $url,
        'image' => $imageServer . "types/" . $victimInfo['shipTypeID'] . "/render?size=128"
    ];
    $redis->publish("public", json_encode($redisMessage, JSON_UNESCAPED_SLASHES));
    Discord::webhook($bigKillBotWebhook, $url);
}

function adjustMessage($name, $message)
{
    $newMessage = "$name $message #tweetfleet #eveonline";
    $message = (strlen($newMessage) <= 260) ? $newMessage : $message;

    $message = strlen($message) > 260 ? str_replace(' worth ', ': ', $message) : $message;
    $message = strlen($message) > 260 ? str_replace(' was destroyed!', '', $message) : $message;

    return $message;
}

function getName($victimInfo)
{
    $name = "";
    if (strlen(@$victimInfo['characterName'] ?: '') > 0) $name = $victimInfo['characterName'];
    if (strlen(@$victimInfo['allianceName'] ?: '') > 0) $name = $victimInfo['allianceName'];
    else if ($victimInfo['corporationID'] > 1999999 || $name == "") $name = $victimInfo['corporationName'];
    $name = Util::endsWith($name, 's') ? $name."'" : $name."'s";

    return $name;
}
