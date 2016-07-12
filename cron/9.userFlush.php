<?php

require_once "../init.php";

$redisKey = "zkb:userFlush";
if ($redis->get($redisKey) == true) exit();

Util::out("Flushing user preferences to disk.");

$keys = $redis->keys("user:*");
foreach ($keys as $key)
{
    $userSettings = $redis->hGetAll($key);
    $doc = $mdb->findDoc("users", ['userID' => $key]);
    if (isset($doc['_id'])) $userSettings['_id'] = @$doc['_id'];
    $userSettings['userID'] = $key;
    $mdb->save("users", $userSettings);
}
$redis->setex($redisKey, 3600, true);
