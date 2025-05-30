<?php

require_once "../init.php";

$date = date("Ymd");
if ($redis->get("zkb:map-activity:$date") == "true") exit();

$checkem = ["involved.characterID"];

$redis->del("zkb:activity_map");
foreach ($checkem as $check) {
    $ids = $mdb->getCollection("ninetyDays")->distinct($check);
    foreach ($ids as $id) {
        $info = $mdb->findDoc("information", ['id' => $id]);
        if (@$info['type'] == 'characterID') continue;
        $activity = ['max' => 0];
        for ($day = 0; $day <= 6; $day++ ) {
            for ($hour = 0; $hour <= 23; $hour++) {
                $count = $mdb->count("activity", ['id' => (int) $id, 'day' => $day, 'hour' => $hour]);
                if ($count > 0) $activity[$day][$hour] = $count;
                $activity['max'] = max($activity['max'], $count);
            }
        }
        if ($activity['max'] > 0) $redis->hset("zkb:activity_map", $id, serialize($activity));
    }
}

$redis->rename("zkb:activity_map", "zkb:activity");
$redis->setex("zkb:map-activity:$date", 86400, "true");
