<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once "../init.php";

$guzzler = new Guzzler(1);
$esimails = null;

$badHashes = $mdb->find("killmails", ['zkb.hash' => null]);
foreach ($badHashes as $row) {
    $killID = $row['killID'];
    $rawMail = $mdb->findDoc("esimails", ['killmail_id' => $killID]);
    $hash = getCrestHash($killID, $rawMail);
    echo "$killID $hash $esiServer/killmails/$killID/$hash/\n";
    $guzzler->call("$esiServer/killmails/$killID/$hash/", "success", "fail", ['row' => $row, 'mdb' => $mdb, 'redis' => $redis, 'killID' => $killID, 'hash' => $hash, 'esimails' => $esimails]);
    $guzzler->finish();
}

function fail($guzzler, $params, $ex) {
echo "fail\n";
die();
return;
    $mdb = $params['mdb'];
    $row = $params['row'];

    $code = $ex->getCode();
    switch ($code) {
        case 0:
        case 420:
        case 500:
        case 502: // Do nothing, the server messed up and we'll try again in a minute
        case 503:
        case 504:
        default:
            Util::out("esi fetch failure ($code): " . $ex->getMessage());
    }
}

function success(&$guzzler, &$params, &$content) {
    global $redis;

    $mdb = $params['mdb'];
    $row = $params['row'];
    $killID = $params['killID'];
    $hash = $params['hash'];

    $mdb->insert("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => true]);
    $mdb->set("killmails", ['killID' => $killID], ['zkb.hash' => $hash]);
}

// https://forums.eveonline.com/default.aspx?g=posts&m=4900335#post4900335
function getCrestHash($killID, $killmail)
{
    $victim = $killmail['victim'];
    $victimID = ((int) @$victim['character_id']) == 0 ? 'None' : $victim['character_id'];

    $attackers = $killmail['attackers'];
    $attacker = null;
    if ($attackers != null) {
        foreach ($attackers as $att) {
            if ($att['final_blow'] != 0) {
                $attacker = $att;
            }
        }
    }
    if ($attacker == null) {
        $attacker = $attackers[0];
    }
    $attackerID = ((int) @$attacker['character_id']) == 0 ? 'None' : $attacker['character_id'];

    $shipTypeID = $victim['ship_type_id'];

    $dttm = (strtotime($killmail['killmail_time']) * 10000000) + 116444736000000000;

    $string = "$victimID$attackerID$shipTypeID$dttm";

    $sha = sha1($string);

    return $sha;
}
