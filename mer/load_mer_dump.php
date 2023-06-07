<?php

require_once "../init.php";

// https://forums.eveonline.com/default.aspx?g=posts&m=4900335#post4900335
function getCrestHash($killID, $killmail)
{
    $victim = $killmail['victim'];
    if ($victim == null) return null;
    $victimID = $victim['character_id'] == 0 ? 'None' : $victim['character_id'];

    $attackers = $killmail['killer'];
    $attacker = $killmail['killer'];
    $attackerID = $attacker['character_id'] == 0 ? 'None' : $attacker['character_id'];

    $shipTypeID = $victim['ship_type_id'];

    $dttm = (strtotime($killmail['kill_dt']) * 10000000) + 116444736000000000;

    $string = "$victimID$attackerID$shipTypeID$dttm";

    $sha = sha1($string);

    return $sha;
}

$count = 0;
$raw = file_get_contents("/var/www/zkillboard.com/mer/kill_dump.json");
$json = json_decode($raw, true);
foreach ($json as $kill) {
    $killID = $kill['kill_id'];
    $crest = getCrestHash($kill['kill_id'], $kill);
    if ($crest != null) {
        $row = $mdb->findDoc("crestmails", ['killID' => $killID, 'hash' => $crest]);
        if ($row == null) {
            //echo "https://esi.evetech.net/latest/killmails/$killID/$crest/\n";
            $mdb->insert("crestmails", ['killID' => $killID, 'hash' => $crest, 'processed' => false, 'labeled' => false]);
$count ++;
if ($count % 1000 == 0) echo ".";
//if ($count > 1000) exit();
        }
    }
}
echo "\n$count\n";
