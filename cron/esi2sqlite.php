<?php

require_once "../init.php";

if (date('Hi') != "1100") exit();

// initialize
$db = new SQLite3("/home/kmstorage/sqlite/esi_killmails.sqlite");
$db->busyTimeout(30000);

$db->query("create table if not exists killmails (killmail_id integer primary key, mail blob)");
$db->exec("begin transaction");

$count = 0;
$iter = $mdb->getCollection("esimails")->find()->sort(['killmail_id' => 1]);
while ($iter->hasNext()) {
    $next = $iter->next();
    $killID = $next['killmail_id'];

    $results = $db->query("select count(*) count from killmails where killmail_id = $killID");
    $row = $results->fetchArray(SQLITE3_ASSOC);
    if ($row['count'] > 0) {
        Util::out("$killID exists");
        $mdb->remove("esimails", $next);
        continue;
    }
    Util::out("Inserting $killID");

    unset($next['_id']);
    $p = $db->prepare("insert or ignore into killmails (killmail_id, mail) values (:k, :m)");
    $p->bindValue(":k", $killID);
    $p->bindValue(":m", json_encode($next, JSON_UNESCAPED_SLASHES));
    $r = $p->execute();
    $p->close();

    $count++;
    if ($count % 10000 == 0) {
        Util::out("$count $killID");
        $db->exec("commit");
        $db->exec("begin transaction");
    }
}
$db->exec("commit");
$db->close();
