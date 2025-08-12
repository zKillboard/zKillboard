<?php 

require_once "../init.php";

$rows = $mdb->find("esimails", ['victim.items.item_type_id' => ['$in' => [81976, 82317, 82316, 82318, 82319, 81975, 81977, 81978]], 'killmail_id' => ['$lte' => 120000000]]);

$redis->set("zkb:statsStop", "true");
sleep(60);
foreach ($rows as $row) {
    $killID = (int) $row['killmail_id'];
//    echo "$killID\n";
//    Killmail::deleteKillmail($killID);
}
$redis->del("zkb:statsStop");
