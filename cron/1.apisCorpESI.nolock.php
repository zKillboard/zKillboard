<?php

require_once "../init.php";

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

$charID = @$argv[1];
$esi = new RedisTimeQueue('tqCorpApiESI', 3600);

if ($charID !== null) {
    pullEsiKills((int) $charID, $esi);
    exit();
}

if (date('i') == 49 || $esi->size() == 0) {
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_corporation_killmails.v1']);
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

    usleep($esiCalls->count() < 350 && $esiFailure->count() < 50 && $charID > 0 ? $usleep : 1000000);
}

function pullEsiKills($charID, $esi) {
    global $redis, $mdb;

    $maxSiteKillID = $mdb->findField('killmails', 'killID', ['cacheTime' => 60], ['killID' => -1]);

    $row = $mdb->findDoc("scopes", ['characterID' => (int) $charID, 'scope' => 'esi-killmails.read_corporation_killmails.v1']);
    if ($row === null) {
        $esi->remove($charID);
        return;
    }

    $killsAdded = 0;
    $prevMaxKillID = isset($row['maxKillID']) ? $row['maxKillID'] : 0;
    $maxKillID = 0;
    $minKillID = 999999999999;

    $refreshToken = $row['refreshToken'];
    $fullStop = false;

    $accessToken = CrestSSO::getAccessToken($charID, null, $refreshToken);
    if ($accessToken === null) {
        $mdb->remove("scopes", $row);
        return;
    }
    if (isset($accessToken['error'])) {
        $esi->remove($charID);

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

    $corpID = (int) Info::getInfoField("characterID", $charID, 'corporationID');
    do {
        $url = "https://esi.tech.ccp.is/v1/corporations/$corpID/killmails/recent/";

        $fields = ['max_count' => 50, 'datasource' => 'tranquility'];
        if ($minKillID !== 999999999999) $fields['max_kill_id'] = $minKillID;

        $raw = ESI::curl($url, $fields, $accessToken);
        $json = json_decode($raw, true);

        if (isset($json['error']) || !is_array($json)) {
            $httpCode = (int) @$json['httpCode'];
            if ($httpCode == 403) {
                $mdb->remove("scopes", $row);
                $esi->remove($charID);
            } else Util::out("$url httpCode $httpCode");
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

    $corpID = Info::getInfoField('characterID', $charID, 'corporationID');

    $mdb->set("scopes", $row, ['maxKillID' => $maxKillID, 'corporationID' => $corpID, 'lastFetch' => $mdb->now()]);
    $mdb->remove("scopes", ['characterID' => $charID, 'corporationID' => $corpID, 'scope' => 'corporationKillsRead']);
    if ($redis->get("apiVerified:$corpID") == null) {
        $charName = Info::getInfoField('characterID', $charID, 'name');
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        Util::out("$corpName ($charName) is now ESI verified");
    }
    $redis->setex("apiVerified:$corpID", 86400, time());

    // Check active chars once an hour, check inactive chars less often
    $esi->setTime($charID, time() + (3600 * ($maxKillID > ($maxSiteKillID - 1000000) ? 1 : rand(20,24))));

    if ($killsAdded > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        if ($name === null) $name = $charID;
        while (strlen("$killsAdded") < 3) $killsAdded = " " . $killsAdded;
        $cname = Info::getInfoField('corporationID', $corpID, 'name');
        ZLog::add("$killsAdded kills added by corp $cname (ESI)", 0);
    }
}
