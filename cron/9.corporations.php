<?php

require_once '../init.php';

if ($kvc->get("zkb:noapi") == "true") exit();
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();

$guzzler = new Guzzler();


$currentSecond = "";
$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->get("zkb:420ed")) break;
    $t = new Timer();
    $row = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => ['$gt' => 1]], ['lastApiUpdate' => 1]);
    if ($row == null) {
        sleep(1);
        continue;
    }
    $id = $row['id'];

    $hasRecent = $mdb->exists("ninetyDays", ['involved.corporationID' => $id]);
    while ($currentSecond == date('His')) usleep(50);
    $currentSecond = date('His');

    $url = "$esiServer/corporations/$id/";
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    $headers = ['X-Compatibility-Date' => '2026-07-21'];
    if (!empty($row['etag'])) $headers['If-None-Match'] = $row['etag'];
    if (!empty($row['last-modified'])) $headers['If-Modified-Since'] = $row['last-modified'];
    $guzzler->call($url, "updateCorp", "failCorp", $params, $headers);

    $guzzler->finish();
    $time = $t->stop();
    sleep(1);
}
$guzzler->finish();

function failCorp(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something
        case 503: // server error
        case 504: // gateway timeout
        case 200: // timeout...
                  //$mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * -2)]);
            break;
        case 420:
            $guzzler->finish();
            exit();
        default:
            Util::out("/corporations/ failed for $id with code $code");
    }
}

function updateCorp(&$guzzler, &$params, &$content)
{
    try {
        $redis = $params['redis'];
        $mdb = $params['mdb'];
        $row = $params['row'];
        $id = $row['id'];

        if ($content == "") {
            if (@$params['STATUS_CODE'] == 304) $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now()]);
            else echo "empty content\n";
            return;
        }

        $json = json_decode($content, true);
        if (!is_array($json) || @$json['name'] == "") return; // bad data, ignore it
        unset($json['description'], $json['shares']);
        $ceoID = (int) @$json['ceo_id'];
        $creatorID = (int) @$json['creator_id'];

        $updates = $json;
        $updates['lastApiUpdate'] =  $mdb->now();
        if (isset($json['name']) && $json['name'] != "") $updates['name'] = (string) @$json['name'];
        if (isset($json['ticker']) && $json['ticker'] != "") $updates['ticker'] = (string) @$json['ticker'];
        unset($updates['type']);
        $updates['ceoID'] = (int) @$json['ceo_id'];
        $updates['creatorID'] = $creatorID;
        $updates['memberCount'] = (int) @$json['member_count'];
        $updates['allianceID'] = (int) @$json['alliance_id'];
        $updates['factionID'] = (int) @$json['faction_id'];
        $updates['homeStationID'] = (int) @$json['home_station_id'];
        if (isset($json['tax_rate'])) $updates['taxRate'] = (float) @$json['tax_rate'];
        $updates['url'] = (string) @$json['url'];
        $updates['war_eligible'] = (isset($json['war_eligible']) ? $json['war_eligible'] : false);
        $headers = @$params['HEADERS'];
        if (isset($headers['etag'][0])) $updates['etag'] = $headers['etag'][0];
        if (isset($headers['last-modified'][0])) $updates['last-modified'] = $headers['last-modified'][0];

        $currentWar = $mdb->findDoc("information", ['type' => 'warID', 'finished' => ['$exists' => false], '$or' => [['aggressor.corporation_id'=> $id], ['defender.corporation_id'=> $id]]]);
        $updates['has_wars'] = ($currentWar != null);

        foreach (array_unique([$ceoID, $creatorID]) as $characterID) {
            if ($characterID <= 1) continue;
            $characterExists = $mdb->count('information', ['type' => 'characterID', 'id' => $characterID]);
            if ($characterExists == 0) {
                $mdb->insertUpdate('information', ['type' => 'characterID', 'id' => $characterID], ['name' => "Character $characterID"]);
            }
        }

        if (sizeof($updates)) {
            $mdb->set("information", $row, $updates);
            $redis->del(Info::getRedisKey('corporationID', $row['id']));
        }

        if (isset($json['alliance_id'])) {
            $exists = $mdb->exists("information", ['type' => 'allianceID', 'id' => (int) $json['alliance_id']]);
            if ($exists == false) $mdb->insert("information", ['type' => 'allianceID', 'id' => (int) $json['alliance_id'], 'name' => 'Alliance ' . (int) $json['alliance_id']]);
        }
    } catch (Exception $ex) {
        Util::out(print_r($ex, true));
    }
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
