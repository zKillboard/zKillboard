<?php

require_once '../init.php';

if ($kvc->get("zkb:noapi") == "true") exit();

$guzzler = new Guzzler(5);
$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->get("zkb:reinforced") == true) break;
    $rows = $mdb->find("information", ['type' => 'characterID'], ['nextApiUpdate' => 1], 5);
    if (sizeof($rows) == 0) {
        $guzzler->sleep(1);
        continue;
    }
    $row = reset($rows);
    if (isset($row['nextApiUpdate']) && $row['nextApiUpdate'] instanceof MongoDB\BSON\UTCDateTime && $row['nextApiUpdate']->toDateTime()->getTimestamp() > time()) {
        $guzzler->sleep(1);
        continue;
    }

    $t = new Timer();
    $requests = 0;
    foreach ($rows as $row) {
        if (isset($row['nextApiUpdate']) && $row['nextApiUpdate'] instanceof MongoDB\BSON\UTCDateTime && $row['nextApiUpdate']->toDateTime()->getTimestamp() > time()) continue;

        $id = (int) $row['id'];
        if ($id <= 1) {
            $mdb->set("information", $row, ['nextApiUpdate' => $mdb->now(7 * 86400)]);
            continue;
        }
        if (@$row['corporationID'] == 1000001) {
            $mdb->set("information", $row, ['nextApiUpdate' => $mdb->now(7 * 86400)]);
            continue;
        }

        $statistics = $mdb->findDoc('statistics', ['type' => 'characterID', 'id' => $id], [], ['topShipsUpdated' => 1]);
        // topShipsUpdated only exists for characters seen on a killmail in the trailing year.
        $refreshInterval = isset($statistics['topShipsUpdated']) ? 86400 : (7 * 86400);
        if (!isset($row['nextApiUpdate']) && isset($row['lastApiUpdate']) && $row['lastApiUpdate'] instanceof MongoDB\BSON\UTCDateTime) {
            $lastApiUpdate = $row['lastApiUpdate']->toDateTime()->getTimestamp();
            if ($lastApiUpdate > (time() - $refreshInterval)) {
                $mdb->set("information", $row, ['nextApiUpdate' => new MongoDB\BSON\UTCDateTime(($lastApiUpdate + $refreshInterval) * 1000)]);
                continue;
            }
        }

        $url = "$esiServer/characters/$id";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row, 'refreshInterval' => $refreshInterval];
        $headers = ['X-Compatibility-Date' => '2026-07-21'];
        if (!empty($row['etag'])) $headers['If-None-Match'] = $row['etag'];
        if (!empty($row['last-modified'])) $headers['If-Modified-Since'] = $row['last-modified'];
        $guzzler->call($url, "updateChar", "failChar", $params, $headers);
        $requests++;
    }
    $guzzler->finish();
    do {
        usleep(10000);
    } while ($t->stop() < (max(1, $requests) * 50));
}      
$guzzler->finish();

function failChar(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something...
        case 503: // server error
        case 504: // gateway timeout
        case 200: // timeout...
        case 400: // who knows what's ccp doing here
            Util::out("ERROR $id");
            $guzzler->sleep(1);
            $mdb->set("information", $row, ['nextApiUpdate' => $mdb->now(3600)]);
            break;
        case 404: // not deleting it...
            $mdb->set("information", $row, ['nextApiUpdate' => $mdb->now(86400 * 21)]);
            $guzzler->sleep(1);
            break;
        case 420:
            $guzzler->finish();
            exit();
        default:
            Util::out("/characters/ failed for $id with code $code");
            $mdb->set("information", $row, ['nextApiUpdate' => $mdb->now(3600)]);
    }
}

function updateChar(&$guzzler, &$params, &$content)
{
    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];
    $id = (int) $row['id'];

    if ($content == "") {
        $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(), 'nextApiUpdate' => $mdb->now($params['refreshInterval'])]);
        return;
    }

    $content = Util::eliminateBetween($content, '"description"', '"faction_id"');
    $content = Util::eliminateBetween($content, '"description"', '"gender"');

    $json = json_decode($content, true);
    if (@$json['name'] == "") {
        $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(), 'nextApiUpdate' => $mdb->now($params['refreshInterval'])]);
        return; // bad data, ignore it
    }
    if (json_last_error() != 0) {
        Util::out("Character $id JSON issue: " . json_last_error() . " " . json_last_error_msg());
        return;
    }

    $corpID = (int) $json['corporation_id'];

    $updates = $json;
    if (@$row['obscene'] == true) {
        compareAttributes($updates, "name", @$row['name'], "Character " . $row['id']);
        compareAttributes($updates, "obscene_name", @$row['name'], (string) $json['name']);
    } else if (@$row['name'] == "") {
        compareAttributes($updates, "name", @$row['name'], (string) $json['name']);
    }
    if (isset($json['security_status'])) compareAttributes($updates, "secStatus", @$row['secStatus'], (double) $json['security_status']);

    if (@$row['name'] != "" && strpos($updates['name'], " Citizen ") === false) unset($updates['name']); // Names will no longer be updated here

    $corpExists = $mdb->count('information', ['type' => 'corporationID', 'id' => $corpID]);
    if ($corpExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => $corpID]);
    }

    $updates['lastApiUpdate'] = $mdb->now();
    $updates['nextApiUpdate'] = $mdb->now($corpID == 1000001 ? (7 * 86400) : $params['refreshInterval']);
    $headers = @$params['HEADERS'];
    if (isset($headers['etag'][0])) $updates['etag'] = $headers['etag'][0];
    if (isset($headers['last-modified'][0])) $updates['last-modified'] = $headers['last-modified'][0];
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

function ew_ignore($guzzler, $params, $content)
{
    if (strlen($content) > 0) Util::out($content);
}
