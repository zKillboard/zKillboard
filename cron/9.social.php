<?php

require_once '../init.php';

global $beSocial;
if ($beSocial != true) 
{
	$redis->del("queueSocial");
	exit();
}

$queueSocial = new RedisQueue('queueSocial');
$timer = new Timer();
while ($timer->stop() < 59000) {
    $killID = $queueSocial->pop();
    if ($killID != null) {
        beSocial($killID);
    }
}

function beSocial($killID)
{
    global $beSocial, $mdb;

    if (!isset($beSocial)) {
        $beSocial = false;
    }
    if ($beSocial === false) {
        return;
    }

    if ($killID < 0) {
        return;
    }
    $ircMin = 10000000000;
    $twitMin = 10000000000;

    $kill = $mdb->findDoc('killmails', ['killID' => $killID]);

    if (@$kill['social'] == true) {
        return;
    }
    $hours24 = time() - 86400;
    if ($kill['dttm']->sec < $hours24) {
        return;
    }

    // Get victim info
    $victimInfo = $kill['involved'][0];
    if ($victimInfo == null) {
        return;
    }
    $totalPrice = $kill['zkb']['totalValue'];

    Info::addInfo($victimInfo);

    // Reduce spam of freighters and jump freighters
    $shipGroupID = $victimInfo['groupID'];
    if (in_array($shipGroupID, array(513, 902))) {
        $shipPrice = Price::getItemPrice($victimInfo['shipTypeID'], date('Ymd'));
        $ircMin += $shipPrice;
        $twitMin += $shipPrice;
    }

    $worthIt = false;
    $worthIt |= $totalPrice >= $ircMin;
    if (!$worthIt) {
        return;
    }

    $tweetIt = false;
    $tweetIt |= $totalPrice >= $twitMin;

    global $fullAddr, $twitterName;
    $url = "$fullAddr/kill/$killID/";

    if ($url == '') {
        $url = "$fullAddr/kill/$killID/";
    }
    $message = '|g|'.$victimInfo['shipName'].'|n| worth |r|'.Util::formatIsk($totalPrice)." ISK|n| was destroyed! $url";
    if (!isset($victimInfo['characterName'])) {
        $victimInfo['characterName'] = $victimInfo['corporationName'];
    }
    if (strlen($victimInfo['characterName']) < 25) {
        $name = $victimInfo['characterName'];
        if (Util::endsWith($name, 's')) {
            $name .= "'";
        } else {
            $name .= "'s";
        }
        $message = "$name $message";
    }
    $mdb->getCollection('killmails')->update(['killID' => $killID], ['$unset' => ['social' => true]]);

    //Log::irc("$message");
    $message = Log::stripIRCColors($message);

    $message .= ' #tweetfleet #eveonline';
    if (strlen($message) > 120) {
        $message = str_replace(' worth ', ': ', $message);
    }
    if (strlen($message) > 120) {
        $message = str_replace(' was destroyed!', '', $message);
    }
    if ($tweetIt && strlen($message) <= 120) {
        $return = Twit::sendMessage($message);
        $twit = "https://twitter.com/{$twitterName}/status/".$return->id;
        Log::irc("Message was also tweeted: |g|$twit");
    }
}
