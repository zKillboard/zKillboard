<?php

require_once "../init.php";

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisQueue;

global $killBotWebhook, $fullAddr;

$queueDiscord = new RedisQueue('queueDiscord');

$hi = date('hi');
while ($hi == date('hi')) {
    $killID = (int) $queueDiscord->pop();
    if ($killID > 0) {
        Discord::webhook($killBotWebhook, "$fullAddr/kill/$killID/");
    }
    sleep(1);
}
