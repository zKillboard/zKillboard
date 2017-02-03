<?php

require_once "../init.php";

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

$charID = @$argv[1];
$esi = new RedisTimeQueue('tqApiESI', 3600);
if ($charID !== null) {
    pullEsiKills((int) $charID, $esi);
    exit();
}

if (date('i') == 22 || $esi->size() == 0) {
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_killmails.v1']);
    foreach ($esis as $row) {
        $charID = $row['characterID'];
        $esi->add($charID);
    }
}

$esiCalls = new RedisTtlCounter('ttlc:esiCalls', 10);
$esiFailure = new RedisTtlCounter('ttlc:esiFailure', 300);

$usleep = max(50000, min(1000000, floor((1 / (($esi->size() + 1) / 3600)) * 700000)));
$redirect = str_replace("/cron/", "/cron/logs/", __FILE__) . ".log";
$minute = date('Hi');
while ($minute == date('Hi')) {
    $charID = (int) $esi->next();
    if ($charID > 0) exec("cd " . __DIR__ . " ; php " . __FILE__ . " $charID >>$redirect 2>>$redirect &");

    usleep($esiCalls->count() < 350 && $esiFailure->count() < 100 ? $usleep : 1000000);
}

function pullEsiKills($charID, $esi) {
    global $redis, $mdb;

    $maxSiteKillID = $mdb->findField('killmails', 'killID', ['cacheTime' => 60], ['killID' => -1]);

    $row = $mdb->findDoc("scopes", ['characterID' => $charID, 'scope' => 'esi-killmails.read_killmails.v1']);
    if ($row === null) {
        $esi->remove($charID);
        $redis->del("apiVerified:$charID");
        return;
    }

    $killsAdded = 0;
    $prevMaxKillID = isset($row['maxKillID']) ? $row['maxKillID'] : 0;
    $maxKillID = 0;
    $minKillID = 999999999999;

    $refreshToken = $row['refreshToken'];
    $fullStop = false;

    $accessToken = CrestSSO::getAccessToken($charID, null, $refreshToken);
    if (isset($accessToken['error'])) {
        switch ($accessToken['error']) {
            case "invalid_grant":
                $mdb->remove("scopes", $row);
                $redis->del("apiVerified:$charID");
                break;
            default:
                Util::out("Unknown ESI error $charID :\n" . print_r($accessToken, true));
                $esi->setTime($charID, time() + 60);
        }
        return;
    }

    $headers = [];
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Authorization: Bearer ' . $accessToken;

    do {
        $url = "https://esi.tech.ccp.is/v1/characters/$charID/killmails/recent/";
        $fields = ['max_count' => 50, 'datasource' => 'tranquility'];
        if ($minKillID !== 999999999999) $fields['max_kill_id'] = $minKillID;

        $raw = ESI::curl($url, $fields, $accessToken);
        $json = json_decode($raw, true);

        if (isset($json['error'])) {
            $esi->setTime($charID, time() + 300);
            return;
        }

        foreach ($json as $kill) {
            if (!isset($kill['killmail_id'])) {
                $fullStop = true;
                break;
            }
            $killID = $kill['killmail_id'];
            $hash = $kill['killmail_hash'];
            $minKillID = min($minKillID, $killID);
            $maxKillID = max($maxKillID, $killID);

            $exists = $mdb->exists('crestmails', ['killID' => $killID]);
            if (!$exists) {
                try {
                    $mdb->getCollection('crestmails')->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'esi', 'added' => $mdb->now()]);
                    $killsAdded++;
                } catch (MongoDuplicateKeyException $ex) {
                    // ignore it *sigh*
                }
            }
        }
    } while (sizeof($json) > 0 && $fullStop == false && $prevMaxKillID < $minKillID);

    $corpID = (int) Info::getInfoField("characterID", $charID, 'corporationID');
    $mdb->set("scopes", $row, ['maxKillID' => $maxKillID, 'corporationID' => $corpID, 'lastFetch' => $mdb->now()]);
    $mdb->remove("apisCrest", ['characterID' => $charID]);
    $mdb->remove("apis", ['type' => 'char', 'userID' => $charID]);
    $redis->setex("apiVerified:$charID", 86400, time());

    // Check active chars once an hour, check inactive chars every 16-20 hours
    $esi->setTime($charID, time() + (3600 * ($maxKillID > ($maxSiteKillID - 1000000) ? 1 : rand(16,20))));

    if ($killsAdded > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        if ($name === null) $name = $charID;
        while (strlen("$killsAdded") < 3) $killsAdded = " " . $killsAdded;
        ZLog::add("$killsAdded kills added by char $name (ESI)", $charID);
        if ($killsAdded >= 10) User::sendMessage("$killsAdded kills added for char $name", $charID);
    }
}
