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
    if ($kill['vGroupID'] == 902) $twitMin += 5000000000; // Jump Freighters, 15b
    if ($kill['vGroupID'] == 1657) $twitMin += 5000000000; // Citadels, 15b
    if ($kill['vGroupID'] == 883) $twitMin += 5000000000; // Rorquals, 15b
    $noTweet = $kill['dttm']->sec < $hours24 || $victimInfo == null || $totalPrice < $twitMin;
    if ($noTweet) {
        return;
    }

    Info::addInfo($victimInfo);

    $url = "$fullAddr/kill/$killID/";
    $message = $victimInfo['shipName'].' worth '.Util::formatIsk($totalPrice)." ISK was destroyed! $url";
    $name = getName($victimInfo);
    $message = adjustMessage($name, $message);

    $redisMessage = [
        'action' => 'bigkill',
        'title' => "$name " . $victimInfo['shipName'],
        'iskStr' => Util::formatIsk($totalPrice)." ISK",
        'url' => $url,
        'image' => $imageServer . "/Render/" . $victimInfo['shipTypeID'] . "_128.png"
            ];
    $redis->publish("public", json_encode($redisMessage, JSON_UNESCAPED_SLASHES));
    sendMessage($message);
}

function adjustMessage($name, $message)
{
    $newMessage = "$name $message #tweetfleet #eveonline";
    $message = (strlen($newMessage) <= 140) ? $newMessage : $message;

    $message = strlen($message) > 120 ? str_replace(' worth ', ': ', $message) : $message;
    $message = strlen($message) > 120 ? str_replace(' was destroyed!', '', $message) : $message;

    return $message;
}

function getName($victimInfo)
{
    $name = "";
    if (strlen(@$victimInfo['characterName']) > 0) $name = $victimInfo['characterName'];
    if (strlen(@$victimInfo['allianceName']) > 0) $name = $victimInfo['allianceName'];
    else $name = $victimInfo['corporationName'];
    $name = Util::endsWith($name, 's') ? $name."'" : $name."'s";

    return $name;
}

function sendMessage($message)
{
    try {
        global $consumerKey, $consumerSecret, $accessToken, $accessTokenSecret;
        $twitter = new Twitter($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);

        return $twitter->send($message);
    } catch (Exception $ex) {
        Util::out("Failed sending tweet: " . $ex->getMessage());
    }
}
