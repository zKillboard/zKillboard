<?php

require_once "../init.php";

// If you want your zkillboard to listen to RedisQ, add the following line to config.php
// $listenRedisQ = true;

global $listenRedisQ;

if ($listenRedisQ == null || $listenRedisQ == false) exit();

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://redisq.zkillboard.com/listen.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

$timer = new Timer();
while ($timer->stop() < 58000) {
    $raw = curl_exec($ch);
    $json = json_decode($raw, true);
    if (!isset($json['package'])) exit(); // Something's wrong , exit and try again later
    $killmail = $json['package'];
    if ($killmail == null) continue;

    $killID = $killmail['killID'];
    if (checkFilter($killmail['killmail']) == false) continue;

    $hash = $killmail['zkb']['hash'];
    if (!$mdb->exists("crestmails", ['killID' => $killID, 'hash' => $hash])) $mdb->save("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => false]);
}

curl_close($ch);

function checkFilter($killmail)
{
    global $characters, $corporations, $alliances;

    if (sizeof($characters) == 0 && sizeof($corporations) == 0 && sizeof($alliances) == 0) return true;

    $hasID = checkEntities([$killmail['victim']]);
    $hasID |= checkEntities($killmail['attackers']);
    return $hasID;
}

function checkEntities($entities)
{
    global $characters, $corporations, $alliances;

    $hasID = false;
    foreach ($entities as $entity)
    {
        if ($characters != null) foreach ($characters as $character) $hasID |= hasID($entity, 'character', $character);
        if ($corporations != null) foreach ($corporations as $corporation) $hasID |= hasID($entity, 'corporation', $corporation);
        if ($alliances != null) foreach ($alliances as $alliance) $hasID |= hasID($entity, 'alliance', $alliance);
    }
    return $hasID;
}

function hasID($entity, $type, $id)
{
    return @$entity[$type]['id'] != 0 && @$entity[$type]['id'] == $id;
}
