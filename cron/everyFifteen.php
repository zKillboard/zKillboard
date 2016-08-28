<?php

require_once '../init.php';

global $redis;

$time = time();
$time = $time - ($time % 900);
$key = "zkb:everyFifteen:$time";
if ($redis->get($key) == true) {
    exit();
}

$p = array();
$numDays = 7;
$p['limit'] = 10;
$p['pastSeconds'] = $numDays * 86400;
$p['kills'] = true;

$redis->set('zkb:TopIsk', json_encode(Stats::getTopIsk(array('pastSeconds' => ($numDays * 86400), 'categoryID' => 6, 'limit' => 5))));

function getStats($column)
{
    $result = Stats::getTop($column, ['isVictim' => false, 'pastSeconds' => 604800]);

    return $result;
}

$redis->keys('*'); // Helps purge expired ttl's

$redis->setex($key, 900, true);
