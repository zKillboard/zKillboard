<?php

use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

if ($redis->get("zkb:reinforced") == true) exit();
$guzzler = new Guzzler(10, 1);
$chars = new RedisTimeQueue("zkb:characterID", 86400);
$maxKillID = $mdb->findField("killmails", "killID", [], ['killID' => -1]) - 5000000;

$mod = 3;
$dayMod = date("j") % $mod;
$minute = date('Hi');
while ($minute == date('Hi')) {
    Status::checkStatus($guzzler, 'esi');
    $id = (int) $chars->next();
    if ($id > 0) {
        $row = $mdb->findDoc("information", ['type' => 'characterID', 'id' => $id]);
        if (strpos(@$row['name'], 'characterID') === false && isset($row['corporationID'])) {
            $charMaxKillID = (int) $mdb->findField("killmails", "killID", ['involved.characterID' => $id], ['killID' => -1]);
            if ($maxKillID > $charMaxKillID && ($id % $mod != $dayMod)) continue;
        }

        $url = "$esiServer/v4/characters/$id/";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row, 'rtq' => $chars];
        $guzzler->call($url, "updateChar", "failChar", $params);
        if (Status::getStatus('esi', false) > 200) sleep(1);
    }
    else $guzzler->tick();
}      
$guzzler->finish();

function failChar(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];
    $rtq = $params['rtq'];

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something...
        case 503: // server error
        case 504: // gateway timeout
        case 200: // timeout...
            $rtq->setTime($id, (time() - 86400) + rand(3600, 7200));
            break;
        default:
            Util::out("/v4/characters/ failed for $id with code $code");
    }
}

function updateChar(&$guzzler, &$params, &$content)
{
    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];
    $json = json_decode($content, true);

    $id = $row['id'];
    $corpID = (int) $json['corporation_id'];

    $updates = [];
    compareAttributes($updates, "name", @$row['name'], (string) $json['name']);
    compareAttributes($updates, "corporationID", @$row['corporationID'], $corpID);
    compareAttributes($updates, "allianceID", @$row['allianceID'], (int) Info::getInfoField("corporationID", $corpID, 'allianceID'));
    compareAttributes($updates, "factionID", @$row['factionID'], 0);
    compareAttributes($updates, "secStatus", @$row['secStatus'], (double) $json['security_status']);

    $corpExists = $mdb->count('information', ['type' => 'corporationID', 'id' => $corpID]);
    if ($corpExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => $corpID]);
        $corps = new RedisTimeQueue("zkb:corporationID", 86400);
        $corps->add($corpID);
    }

    $updates['lastApiUpdate'] = $mdb->now();
    $mdb->set("information", $row, $updates);
    if (sizeof($updates) > 1) {
        $redis->del(Info::getRedisKey('characterID', $id));
    }
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
