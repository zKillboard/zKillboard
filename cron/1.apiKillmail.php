<?php

use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

$collection = 'Corporation';
$type = substr(strtolower($collection), 0, 4);
$field = strtolower($collection).'ID';
$collection = 'api'.$collection;

$minute = date('Hi');
$timeQueue = new RedisTimeQueue("zkb:{$type}s", 14400);

if (date('i') == 41) {
    $ids = $mdb->getCollection($collection)->distinct($field);
    foreach ($ids as $id) {
        $timeQueue->add($id);
    }
}

while ($minute == date('Hi')) {
    $id = (int) $timeQueue->next();
    if ($id > 0) {
        $api = $mdb->findDoc($collection, [$field => $id], ['lastFetched' => 1]);
        if ($api === null) {
            $timeQueue->remove($id);
            continue;
        }
        $count = $mdb->count("apis", ['keyID' => $api['keyID']]);
        if ($count == 0) {
            $mdb->remove($collection, $api);
            $timeQueue->remove($id);
            continue;
        }
        try {
            $result = KillmailParser::processCharApi($mdb, $apiServer, $type, $api);
            $cachedUntil = $result['cachedUntil'];
            $cachedTime = strtotime($cachedUntil);
            $mdb->set($collection, $api, ['lastFetched' => time(), 'type' => $type]);
            $mdb->set("apis", ['keyID' => $api['keyID']], ['type' => $type]);
            KillmailParser::updateApiRow($mdb, $collection, $api, 0);
            KillmailParser::extendApiTime($mdb, $timeQueue, $api, $type, $cachedTime);
            if ($type == 'corp') $redis->setex("apiVerified:$id", 86400, time());
            else {
                $timeQueue->remove($id);
                $mdb->remove("apisCharacter", $api);
            }
        } catch (Exception $ex) {
            KillmailParser::updateApiRow($mdb, $collection, $api, $ex->getCode());
            $timeQueue->setTime($id, time() + 300);
        }
        sleep(1);
    } else exit();
}
