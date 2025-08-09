<?php 

require_once "../init.php";

$rows = $mdb->find("esimails", ['victim.items.item_type_id' => 74523, 'killmail_id' => ['$gt' => 120000000]]);

foreach ($rows as $row) {
    $killID = (int) $row['killmail_id'];
    echo "$killID\n";
    //Killmail::deleteKillmail($killID);
}
