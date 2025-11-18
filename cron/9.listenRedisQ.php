<?php

require_once '../init.php';

// If you want your zkillboard to listen to RedisQ, add the following line to config.php
// $listenRedisQ = true;

if ($listenRedisQ !== true) exit();
if ($listenRedisQID === null) {
    Util::out("Please define listenRedisQID in your config.php");
    exit();
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://zkillredisq.stream/listen.php?queueID=$listenRedisQID");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15); // RedisQ should answer within 10 seconds...
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 second connection timeout

$minute = date('Hi');

while ($minute == date('Hi')) {
    $raw = curl_exec($ch);
    
    // Check for curl errors
    if ($raw === false) {
        $error = curl_error($ch);
        Util::out("RedisQ curl error: $error");
        exit();
    }
    
    $json = json_decode($raw, true);
    if (!isset($json['package'])) {
        // Something's wrong, exit and try again later
        exit();
    }
    
    $killmail = $json['package'];

    $killID = (int) @$killmail['killID'];
    $hash = @$killmail['zkb']['hash'];
    if ($killID == 0 || $hash == "") exit();

    if (!$mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash])) {
        $mdb->save('crestmails', ['killID' => $killID, 'hash' => $hash, 'processed' => false, 'RedisQ']);
    }
}

curl_close($ch);

function checkFilter($killmail)
{
    global $characters, $corporations, $alliances;

    if (@sizeof($characters) == 0 && @sizeof($corporations) == 0 && @sizeof($alliances) == 0) {
        return true;
    }

    $hasID = checkEntities([$killmail['victim']]);
    $hasID |= checkEntities($killmail['attackers']);

    return $hasID;
}

function checkEntities($entities)
{
    global $characters, $corporations, $alliances;

    $hasID = false;
    foreach ($entities as $entity) {
        if ($characters != null) {
            foreach ($characters as $character) {
                $hasID |= hasID($entity, 'character', $character);
            }
        }
        if ($corporations != null) {
            foreach ($corporations as $corporation) {
                $hasID |= hasID($entity, 'corporation', $corporation);
            }
        }
        if ($alliances != null) {
            foreach ($alliances as $alliance) {
                $hasID |= hasID($entity, 'alliance', $alliance);
            }
        }
    }

    return $hasID;
}

function hasID($entity, $type, $id)
{
    return @$entity[$type]['id'] != 0 && @$entity[$type]['id'] == $id;
}
