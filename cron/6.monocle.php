<?php

require_once "../init.php";

if ($kvc->get("zkb:checkMonocles") != true) exit();

function clearOverview($id)
{
    global $redis;

    $redis->del("zkb:overview:character:$id");
    $redis->del("zkb:overview:characterID:$id");
    $redis->sadd("queueCacheTags", "overview:$id");
}

function updateInformationMonocle($id, $supermonocle = false)
{
    global $mdb, $redis;

    $id = (int) $id;
    if ($id <= 0) return;

    $values = ['monocle' => true];
    if ($supermonocle) {
        $values['supermonocle'] = true;
    }
    $mdb->set("information", ['type' => 'characterID', 'id' => $id], $values);
    $redis->del(Info::getRedisKey('characterID', $id));
}

$rows = $mdb->getCollection("payments")->aggregate([
    ['$match' => ['characterID' => ['$exists' => true], 'isk' => ['$exists' => true]]],
    ['$group' => ['_id' => '$characterID', 'isk' => ['$sum' => '$isk']]],
    ['$match' => ['isk' => ['$gte' => 10000000000]]],
    ['$lookup' => ['from' => 'users', 'localField' => '_id', 'foreignField' => 'characterID', 'as' => 'user']],
    ['$unwind' => '$user'],
    ['$match' => ['user.supermonocle' => ['$ne' => true]]],
    ['$project' => ['_id' => 0, 'characterID' => '$_id', 'isk' => 1]],
], ['allowDiskUse' => true]);
foreach ($rows as $row) {
    $id = (int) $row['characterID'];
    $isk = $row['isk'];
    Util::out("$id super monocled $isk");
    $mdb->set("users", ['characterID' => $id], ['monocle' => true, 'supermonocle' => true]);
    updateInformationMonocle($id, true);
    clearOverview($id);

    Util::sendEveMail($id, "Super Monocle!", "You have given at least 10000000000 ISK to zKillboard! In appreciation of your exceptionally deep pockets a super monocle will show up very soon on your character's zKillboard page. Thank you! \n\n<a href=\"https://zkillboard.com/character/$id/\">Your zKillboard character page.</a>");
    sleep(1);
}

$rows = $mdb->getCollection("payments")->aggregate([
    ['$match' => ['characterID' => ['$exists' => true], 'isk' => ['$exists' => true]]],
    ['$group' => ['_id' => '$characterID', 'isk' => ['$sum' => '$isk']]],
    ['$match' => ['isk' => ['$gte' => 1000000000]]],
    ['$lookup' => ['from' => 'users', 'localField' => '_id', 'foreignField' => 'characterID', 'as' => 'user']],
    ['$unwind' => '$user'],
    ['$match' => ['user.monocle' => ['$ne' => true]]],
    ['$project' => ['_id' => 0, 'characterID' => '$_id', 'isk' => 1]],
], ['allowDiskUse' => true]);
foreach ($rows as $row) {
    $id = (int) $row['characterID'];
    $isk = $row['isk'];
    Util::out("$id monocled $isk");
    $mdb->set("users", ['characterID' => $id], ['monocle' => true]);
    updateInformationMonocle($id);
    clearOverview($id);

    Util::sendEveMail($id, "Monocle!", "You have given at least 1000000000 ISK to zKillboard! In appreciation of your deep pockets a monocle will show up very soon on your character's zKillboard page. Thank you! \n\n<a href=\"https://zkillboard.com/character/$id/\">Your zKillboard character page.</a>");
    sleep(1);
}

$kvc->del("zkb:checkMonocles");
