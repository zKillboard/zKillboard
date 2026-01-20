<?php

require_once "../init.php";

$cursor = $mdb->getCollection("killmails")->find(['labels' => 'padding']);

$reset = [];
foreach ($cursor as $killmail) {
    foreach ($killmail['involved'] as $involved) {
        foreach ($involved as $type => $id) {
            if (@$reset["$type:$id"] == true) continue;
            $mdb->set('statistics', ['type' => $type, 'id' => (int) $id], ['reset' => true]);
            $reset["$type:$id"] = true;
        }
    }
}
