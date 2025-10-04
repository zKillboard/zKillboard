<?php

require_once "../init.php";

if (date('Hi') != "1000") exit();

MongoCursor::$timeout = -1;

$typeIDs = $mdb->getCollection("itemmails")->distinct("typeID");
$total = sizeof($typeIDs);
$current = 0;
foreach ($typeIDs as $typeID) {
    $name = Info::getInfoField("typeID", $typeID, "name");
    $iter = $mdb->getCollection("itemmails")->find(['typeID' => $typeID])->sort(['killID' => -1]);
    $count = 0;
    $removed = 0;
    $remove_queue = [];
    while ($iter->hasNext()) {
        $row = $iter->next();
        $count++;
        if ($count > 50) {
            $remove_queue[] = $row['killID'];
            $removed++;
        }
    }
    
    if (sizeof($remove_queue) > 0) $mdb->set("itemmails", ['killID' => ['$in' => $remove_queue]], ['remove' => true]);
    $current++;
    if ($removed > 0) Util::out("($current/$total) $typeID - $name removed: $removed");
}
$mdb->remove("itemmails", ['remove' => true]);
