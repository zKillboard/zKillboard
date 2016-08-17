<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$queueSocial = new RedisQueue('queueSocial');
$minute = date('Hi');

while ($beSocial && $minute == date('Hi')) {
    $killID = $queueSocial->pop();
    if ($killID != null) {
        beSocial($killID);
    }
}

function beSocial($killID)
{
    global $mdb, $redis, $fullAddr, $twitterName, $imageServer;

    $twitMin = 10000000000;
    $kill = $mdb->findDoc('killmails', ['killID' => $killID]);

    $hours24 = time() - 86400;
    $victimInfo = $kill['involved'][0];
    $totalPrice = $kill['zkb']['totalValue'];
    $noTweet = $kill['dttm']->sec < $hours24 || $victimInfo == null || $totalPrice < $twitMin;
    if ($noTweet) {
        return;
    }

    Info::addInfo($victimInfo);

    $url = "$fullAddr/kill/$killID/";
    $message = $victimInfo['shipName'].' worth '.Util::formatIsk($totalPrice)." ISK was destroyed! $url";

    $name = isset($victimInfo['characterName']) ? $victimInfo['characterName'] : $victimInfo['corporationName'];
    $name = Util::endsWith($name, 's') ? $name."'" : $name."'s";
    $message = "$name $message #tweetfleet #eveonline";

    $mdb->getCollection('killmails')->update(['killID' => $killID], ['$unset' => ['social' => true]]);

    $message = strlen($message) > 120 ? str_replace(' worth ', ': ', $message) : $message;
    $message = strlen($message) > 120 ? str_replace(' was destroyed!', '', $message) : $message;

    $redisMessage = [
        'action' => 'bigkill',
        'title' => "$name " . $victimInfo['shipName'],
        'iskStr' => Util::formatIsk($totalPrice)." ISK",
        'url' => $url,
        'image' => $imageServer . "/Render/" . $victimInfo['shipTypeID'] . "_128.png"
            ];
    $redis->publish("public", json_encode($redisMessage, JSON_UNESCAPED_SLASHES));
    return strlen($message) <= 120 ? sendMessage($message) : false;
}

function sendMessage($message)
{
    global $consumerKey, $consumerSecret, $accessToken, $accessTokenSecret;
    $twitter = new Twitter($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);

    return $twitter->send($message);
}
