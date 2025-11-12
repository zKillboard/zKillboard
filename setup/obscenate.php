<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

global $mdb;

if (!isset($argv[1]) || !isset($argv[2])) {
    echo "Usage: php obscenate.php <type> <id>\n";
    echo "Example: php obscenate.php characterID 123456\n";
    exit(1);
}

$type = (string) $argv[1];
$id = (int) $argv[2];
$result = $mdb->getCollection("information")->updateOne(['type' => $type, 'id' => $id], ['$set' => ['obscene' => true, 'name' => '']]);
$r = ['n' => $result->getModifiedCount()];
if ($r['n'] > 0) {
    print_r($r);
    $queue = new RedisTimeQueue('zkb:' . $type, 9600);
    $queue->remove($id);
    $queue->add($id); // Forces immediate repull
    echo "Updated\n";
} else echo "Not found... \n";
