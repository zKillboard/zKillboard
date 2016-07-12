<?php

use cvweiss\redistools\RedisQueue;

include_once '../init.php';

$queueCleanup = new RedisQueue('queueCleanup');

$timer = new Timer();
while ($timer->stop() < 58000) {
    $killID = $queueCleanup->pop();
    if ($killID === null) {
        exit();
    }

    $killmail = $mdb->findDoc('rawmails', ['killID' => $killID]);

    if (!isset($killmail['killID_str'])) {
        continue;
    }

    $killmail = cleanup($killmail);
    $mdb->save('rawmails', $killmail);
}

function cleanup($array)
{
    $removable = ['icon', 'href', 'name'];

    foreach ($array as $key => $value) {
        if (substr($key, -4) == '_str') {
            //Util::out("Unsetting _str $key");
            unset($array[$key]);
        } elseif (in_array($key, $removable, true)) {
            //Util::out("Unsetting removable $key");
            unset($array[$key]);
        } elseif (is_array($value)) {
            $array[$key] = cleanup($value);
        }
    }

    return $array;
}
