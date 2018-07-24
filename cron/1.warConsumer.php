<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:420prone") == "true") exit();

$queueWars = new RedisQueue('queueWars');

if ($queueWars->size() == 0 && $redis->get("zkb:iterateWars") != "true") {
    $wars = $mdb->getCollection('information')->find(['type' => 'warID']);
    foreach ($wars as $war) {
        if (@$war['finished'] == true) continue;
        $queueWars->push($war['id']);
    }
}

$guzzler = new Guzzler();

$minute = date('Hi');
while ($minute == date('Hi')) {
    $id = $queueWars->pop();
    if ($id == null) break;
    $warRow = $mdb->findDoc('information', ['type' => 'warID', 'id' => $id]);
    $params = ['warRow' => $warRow];
    $url = "$esiServer/v1/wars/$id/";
    $guzzler->call($url, "success", "fail", $params, ['etag' => true], 'GET');
}
$guzzler->finish();

if ($queueWars->size() == 0 && $redis->get("zkb:iterateWars") != "true") {
    $redis->setex("zkb:iterateWars", 3600, "true");
}

function success(&$guzzler, &$params, &$content)
{
    global $mdb, $esiServer;

    if ($content == "") return;

    $war = json_decode($content, true);
    $warRow = $params['warRow'];
    $id = $params['warRow']['id'];

    $war['lastApiUpdate'] = $mdb->now();
    $war['id'] = $id;
    if (!isset($war['aggressor']['id'])) $war['aggressor']['id'] = isset($war['aggressor']['alliance_id']) ? $war['aggressor']['alliance_id'] : $war['aggressor']['corporation_id'];
    if (!isset($war['defender']['id'])) $war['defender']['id'] = isset($war['defender']['alliance_id']) ? $war['defender']['alliance_id'] : $war['defender']['corporation_id'];
    if (!isset($war['aggressor']['name'])) {
        $corpName = isset($war['aggressor']['corporation_id']) ? Info::getInfoField("corporationID", $war['aggressor']['corporation_id'], "name") : "";
        $alliName = isset($war['aggressor']['alliance_id']) ? Info::getInfoField("allianceID", $war['aggressor']['alliance_id'], "name") : "";
        $war['aggressor']['name'] = $alliName != "" ? $alliName : $corpName;
    }
    if (!isset($war['defender']['name'])) {
        $corpName = isset($war['defender']['corporation_id']) ? Info::getInfoField("corporationID", $war['defender']['corporation_id'], "name") : "";
        $alliName = isset($war['defender']['alliance_id']) ? Info::getInfoField("allianceID", $war['defender']['alliance_id'], "name") : "";
        $war['defender']['name'] = $alliName != "" ? $alliName : $corpName;
    }
    $mdb->insertUpdate('information', ['type' => 'warID', 'id' => $id], $war);

    $prevKills = @$warRow['agrShipsKilled'] + @$warRow['dfdShipsKilled'];
    $currKills = $war['aggressor']['ships_killed'] + $war['defender']['ships_killed'];

    $mdb->set("information", $war, ['agrShipsKilled' => (int) $war['aggressor']['ships_killed'], 'dfdShipsKilled' => (int) $war['defender']['ships_killed']]);

    // Don't fetch killmail api for wars with no kill count change
    if ($prevKills != $currKills) {
        $baseKmHref = "$esiServer/v1/wars/$id/killmails/";
        $page = floor($mdb->count('warmails', ['warID' => $id]) / 2000);
        if ($page == 0) $page = 1;
        $params['baseKmHref'] = $baseKmHref;
        $params['page'] = $page;
        $guzzler->call("$baseKmHref?page=$page", "killmailSuccess", "fail", $params, [], 'GET');
    }
}

function killmailSuccess(&$guzzler, &$params, &$content)
{
    global $mdb;

    $killmails = $content == "" ? [] : json_decode($content, true);
    $warRow = $params['warRow'];
    $id = $warRow['id'];

    foreach ($killmails as $kill) {
        $killID = (int) $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $mdb->insertUpdate('warmails', ['warID' => $id, 'killID' => $killID]);
        if (!$mdb->exists('crestmails',  ['killID' => $killID, 'hash' => $hash])) {
            $mdb->insert('crestmails', ['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'war', 'added' => Mdb::now()]);
            Util::out("New WARmail $killID");
        }
    }
    if (sizeof($killmails) > 1999) {
        $page = $params['page'];
        $page++;
        $params['page'] = $page;
        $baseKmHref = $params['baseKmHref'];
        $guzzler->call("$baseKmHref?page=$page", "killmailSuccess", "fail", $params, [], 'GET');
    }
}

function fail($guzzler, $params, $exception)
{

}
