<?php

require_once '../init.php';

use cvweiss\redistools\RedisTimeQueue;

if ($redis->get("zkb:universeLoaded") != "true") exit("Universe not yet loaded...\n");

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:420prone") == "true") exit();
$guzzler = new Guzzler();
$corps = new RedisTimeQueue("zkb:corporationID", 86400);

$minute = date('Hi');
while ($minute == date('Hi')) {
    $id = (int) $corps->next();
    if ($id > 0) {
        $row = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => $id]);

        $url = "$esiServer/v4/corporations/$id/";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
        $a = isset($row['lastApiUpdate'])? ['etag' => true] : [];
        $guzzler->call($url, "updateCorp", "failCorp", $params, $a);
    } else {
        $guzzler->tick();
        sleep(1);
    }
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
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * -2)]);
            break;
        case 420:
            $guzzler->finish();
            exit();
        default:
            Util::out("/v4/corporations/ failed for $id with code $code");
    }
}

function updateCorp(&$guzzler, &$params, &$content)
{
    if ($content == "") return;

    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];

    $content = str_replace('\u', '', $content);
    $json = json_decode($content, true);

    $ceoID = (int) $json['ceo_id'];

    $updates = ['lastApiUpdate' => $mdb->now()];
    compareAttributes($updates, "name", @$row['name'], (string) $json['name']);
    compareAttributes($updates, "ticker", @$row['ticker'], (string) $json['ticker']);
    compareAttributes($updates, "ceoID", @$row['ceoID'], $ceoID);
    compareAttributes($updates, "memberCount", @$row['memberCount'], (int) $json['member_count']);
    compareAttributes($updates, "allianceID", @$row['allianceID'], (int) @$json['alliance_id']); 
    compareAttributes($updates, "factionID", @$row['factionID'], (int) @$json['faction_id']);

    // Does the CEO exist in our info table?
    $ceoExists = $mdb->count('information', ['type' => 'characterID', 'id' => $ceoID]);
    if ($ceoExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'characterID', 'id' => $ceoID], []);
    }

    if (sizeof($updates)) {
        $mdb->set("information", $row, $updates);
        $redis->del(Info::getRedisKey('corporationID', $row['id']));
    }

    if (isset($json['alliance_id'])) {
        $queueAllis = new RedisTimeQueue('zkb:allianceID', 9600);
        $row = ['type' => 'allianceID', 'id' => (int) $json['alliance_id']];
        if (!$queueAllis->isMember($json['alliance_id']) || $mdb->count("information", $row) == 0) {
            Util::out("Corporation adding new alliance " . $json['alliance_id']);
            $defaultName = "allianceID " . $json['alliance_id'];
            $mdb->insertUpdate('information', $row, ['name' => $defaultName]);
            $queueAllis->add($json['alliance_id']);
        }
    }
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
