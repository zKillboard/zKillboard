<?php

require_once '../init.php';

global $redis;

$key = "zkb:everyFifteen";
if ($redis->get($key) === true) exit();

$p = array();
$numDays = 7;
$p['limit'] = 10;
$p['pastSeconds'] = $numDays * 86400;
$p['kills'] = true;

$redis->setex('RC:Kills5b+', 3600, json_encode(Kills::getKills(array('iskValue' => 5000000000), true, false)));
$redis->setex('RC:Kills10b+', 3600, json_encode(Kills::getKills(array('iskValue' => 10000000000), true, false)));

$redis->setex('RC:TopIsk', 3600, json_encode(Stats::getTopIsk(array('pastSeconds' => ($numDays * 86400), 'categoryID' => 6, 'limit' => 5))));

function getStats($column)
{
    $result = Stats::getTop($column, ['isVictim' => false, 'pastSeconds' => 604800]);

    return $result;
}

$redis->keys('*'); // Helps purge expired ttl's

$redis->setex($key, 54000, true);
