<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$key = "zkb:populateEntities";
if ($redis->get($key) == "true") exit();

populateEntity($mdb, "characterID");
populateEntity($mdb, "corporationID");
populateEntity($mdb, "allianceID");

$redis->setex($key, 3600, "true");

function populateEntity($mdb, $type) {
    $rtq = new RedisTimeQueue("zkb:$type", 86400);
    $result = $mdb->getCollection("information")->find(['type' => $type]);

    foreach ($result as $row) {
        $rtq->add($row['id']);
    }
}
