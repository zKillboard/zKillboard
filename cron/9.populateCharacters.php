<?php

require_once '../init.php';

$counter = 0;
$information = $mdb->getCollection('information');
$queueCharacters = new RedisTimeQueue('tqCharacters', 86400);
$timer = new Timer();
$counter = 0;

$i = date('i');
if ($i == 15) {
    $characters = $information->find(['type' => 'characterID']);
    foreach ($characters as $char) {
        $queueCharacters->add($char['id']);
    }
}
