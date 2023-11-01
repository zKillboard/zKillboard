<?php

require_once "../init.php";

use cvweiss\redistools\RedisTimeQueue;

$cursor = $mdb->getCollection("information")->find()->sort(['_id' => -1]);

while ($cursor->hasNext()) {
    $row = $cursor->next();
    $type = $row['type'];
    $id = $row['id'];

    if ($type == "typeID") continue;
    if ($type == "categoryID" || $type == "starID" || $type == "marketGroupID" || $type == "warID") continue;

    if ($mdb->findDoc("killmails", ['involved.' . $type => $id]) != null) {
        if ($mdb->count("statistics", ['type' => $type, 'id' => $id]) == 0) {
            $mdb->insert("statistics", ['type' => $type, 'id' => $id, 'reset' => true]);
            $raw = $row['type'] . ":" . $row['id'];
            $redis->sadd("queueStatsSet", $raw);
            Util::out("Added $raw");
        }
    }
}
