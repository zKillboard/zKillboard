<?php

require_once "../init.php";

if (date("j") % 7 != 0) exit(); // 7th, 14th, 21st, 28th
if (date("Hi") != 1000) exit(); 

$coll = "ninetyDays";
$iter = $mdb->getCollection($coll)->find(['labels' => 'pvp']);

while ($iter->hasNext()) {
    $killmail = $iter->next();
    $killmail = $mdb->findDoc("killmails", ['killID' => $killmail['killID']]);
    if (!isset($killmail['padhash'])) continue;
    if (@$killmail['reset'] == true) continue;
    $padhash = $killmail['padhash'];
    if ($redis->get("zkb:padhash:$padhash") == "true") $isPadded = true;
    else $isPadded = ($mdb->count("killmails", ['padhash' => $padhash]) >= 3);

    if ($isPadded) {
        $redis->setex("zkb:padhash:$padhash", 86400, "true");
        $mdb->set("killmails", ['padhash' => $padhash, 'labels' => 'pvp'], ['reset' => true], true);
    }
}
