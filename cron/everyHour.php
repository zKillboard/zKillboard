<?php

use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$time = time();
$time = $time - ($time % 3600);
$key = "zkb:everyHour:$time";
if ($kvc->get($key) == true) {
    exit();
}

$killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
$kills = $killsLastHour->count();
$count = $mdb->count('killmails');

if ($kills > 0) {
    Util::out(number_format($kills, 0).' kills added, now at '.number_format($count, 0).' kills.');
}

$parameters = ['groupID' => 30, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
$redis->set('zkb:titans', serialize(Stats::getTop('characterID', $parameters)));

$parameters = ['groupID' => 659, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
$redis->set('zkb:supers', serialize(Stats::getTop('characterID', $parameters)));

$wars = War::getWarsPageTables(true);
Util::out('Wars page cache refreshed: ' . count($wars) . ' tables.', 'wars page cache');

$result = $mdb->getCollection('information')->updateMany(
    [
        'name' => ['$type' => 'string'],
        '$expr' => [
            '$ne' => [
                ['$toLower' => '$name'],
                ['$toLower' => '$l_name'],
            ],
        ],
    ],
    [
        [
            '$set' => [
                'l_name' => ['$toLower' => '$name'],
            ],
        ],
    ]
);

Util::out('l_name has been updated: ' . $result->getModifiedCount() . ' rows modified.', 'l_name update');

$kvc->setex($key, 3600, true);
