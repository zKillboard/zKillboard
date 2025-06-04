<?php

require_once "../init.php";

global $bigKillBotWebhook;
$url = "testing 1 2 3";

Discord::webhook($bigKillBotWebhook, $url);
