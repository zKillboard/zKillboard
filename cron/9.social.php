<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$queueSocial = new RedisQueue('queueSocial');
$minute = date('Hi');

while ($beSocial && $minute == date('Hi')) {
    $killID = $queueSocial->pop();
    if ($killID > 0 ) {
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
    if ($kill['vGroupID'] == 902) $twitMin += 5000000000;
    $noTweet = $kill['dttm']->sec < $hours24 || $victimInfo == null || $totalPrice < $twitMin;
    if ($noTweet) {
        return;
    }

    Info::addInfo($victimInfo);

    $url = "$fullAddr/kill/$killID/";
    $message = $victimInfo['shipName'].' worth '.Util::formatIsk($totalPrice)." ISK was destroyed! $killID $url";

    $name = "";
    if (strlen(@$victimInfo['characterName']) > 0) $name = $victimInfo['characterName'];
    if (strlen(@$victimInfo['allianceName']) > 0) $name = $victimInfo['allianceName'];
    else $name = $victimInfo['corporationName'];
    $name = Util::endsWith($name, 's') ? $name."'" : $name."'s";
    $newMessage = "$name $message #tweetfleet #eveonline";
    if (strlen($newMessage) <= 140) $message = $newMessage;

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
    try {
        global $consumerKey, $consumerSecret, $accessToken, $accessTokenSecret;
        $twitter = new Twitter($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);

        return $twitter->send($message);
    } catch (Exception $ex) {
        print_r($ex);
        // just ignore it
    }
}
