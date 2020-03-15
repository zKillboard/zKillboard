<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

global $mdb;

$type = (string) $argv[1];
$id = (int) $argv[2];
$r = $mdb->getCollection("information")->update(['type' => $type, 'id' => $id], ['$set' => ['obscene' => true, 'name' => '']]);
if ($r['n'] > 0) {
    print_r($r);
    $queue = new RedisTimeQueue('zkb:' . $type, 9600);
    $queue->remove($id);
    $queue->add($id); // Forces immediate repull
    echo "Updated\n";
} else echo "Not found... \n";
