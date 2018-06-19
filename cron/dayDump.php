<?php

require_once "../init.php";

$day = date('Ymd', time() - (11 * 3600));
$key = "zkb:dayDump:$day";
if ($redis->get($key) == "true") exit();

Util::out("Populating dayDumps");

$changed = 0;
$curDay = null;
$curDayRow = null;
$cursor = $mdb->getCollection("killmails")->find([], ['dttm' => 1, 'killID' => 1, 'zkb.hash' => 1, '_id' => 0])->sort(['killID' => 1]);
foreach ($cursor as $row) {
    $time = $row['dttm']->sec;
    $time = $time - ($time % 86400);
    $date = date('Ymd', $time);
    $killID = $row['killID'];
    $hash = trim($row['zkb']['hash']);
    if ($killID <= 0 || $hash == "") continue;

    if ($curDay != $date) {
        if ($curDayRow != null && $changed > 0) $mdb->save("daydump", $curDayRow);
        if ($changed > 0) Util::out("Populating dayDump $curDay ($changed)");
        $curDayRow = null;
        $changed = 0;
        $redis->set("zkb:firstkillid:$date", $killID);
    }
    $curDay = $date;
    if ($curDayRow == null) {
        $curDayRow = $mdb->findDoc("daydump", ['day' => $date]);
        if ($curDayRow == null) $curDayRow = ['day' => $date];
    }

    if (isset($curDayRow[$killID])) continue;
    if (@$curDayRow[$killID] != $hash) {
        $curDayRow[$killID] = $hash;
        $changed ++;
    }
}
if ($curDayRow != null) $mdb->save("daydump", $curDayRow);

$redis->setex($key, 86400, "true");
