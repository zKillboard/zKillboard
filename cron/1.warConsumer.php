<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("zkb:reinforced") == true) exit();

if ($redis->llen("queueProcess") > 100) exit();
$queueWars = new RedisQueue('queueWars');

if ($queueWars->size() == 0) {
    $wars = $mdb->getCollection('information')->find(['type' => 'warID'])->sort(['id' => -1]);
    foreach ($wars as $war) {
        $timeFinished = @$war['timeFinished'];
        if ($timeFinished != null) {
            $threeDays = date('Y-m-d', (time() - (86400 * 3)));
            $warFinished = substr($timeFinished, 0, 10);

            if ($warFinished < $threeDays) {
                continue;
            }
        }
        $queueWars->push($war['id']);
    }
}

$guzzler = new Guzzler(10);

$minute = date('Hi');
while ($minute == date('Hi')) {
    Status::check('esi');
    $id = $queueWars->pop();
    if ($id == null) break;
    $warRow = $mdb->findDoc('information', ['type' => 'warID', 'id' => $id]);
    $params = ['warRow' => $warRow];
    $url = "$esiServer/v1/wars/$id/";
    $guzzler->call($url, "success", "fail", $params, [], 'GET');
}
$guzzler->finish();

function success(&$guzzler, &$params, &$content)
{
    global $mdb, $esiServer;

    $war = json_decode($content, true);
    $warRow = $params['warRow'];
    $id = $params['warRow']['id'];

    $war['lastApiUpdate'] = $mdb->now();
    $war['id'] = $id;
    $war['finished'] = false;
    if (!isset($war['aggressor']['id'])) $war['aggressor']['id'] = isset($war['aggressor']['alliance_id']) ? $war['aggressor']['alliance_id'] : $war['aggressor']['corporation_id'];
    if (!isset($war['defender']['id'])) $war['defender']['id'] = isset($war['defender']['alliance_id']) ? $war['defender']['alliance_id'] : $war['defender']['corporation_id'];
    $mdb->insertUpdate('information', ['type' => 'warID', 'id' => $id], $war);

    $prevKills = @$warRow['agrShipsKilled'] + @$warRow['dfdShipsKilled'];
    $currKills = $war['aggressor']['ships_killed'] + $war['defender']['ships_killed'];

    $mdb->set("information", $war, ['agrShipsKilled' => (int) $war['aggressor']['ships_killed'], 'dfdShipsKilled' => (int) $war['defender']['ships_killed']]);

    // Don't fetch killmail api for wars with no kill count change
    if ($prevKills != $currKills || true) {
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

    $killmails = json_decode($content, true);
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
