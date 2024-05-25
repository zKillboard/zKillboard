<?php

require_once "../init.php";

if (!(date("e") == 1 && date("Hi") == 1100)) exit(); // Monday at 1100am UTC

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
