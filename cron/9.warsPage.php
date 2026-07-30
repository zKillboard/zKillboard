<?php

require_once '../init.php';

$key = 'zkb:wars:page:prepopulate';
if ($kvc->get($key) == true) {
    exit();
}

$wars = War::getWarsPageTables(true);
$kvc->setex($key, 6666, true);

Util::out('Wars page cache refreshed: ' . count($wars) . ' tables.', 'wars page cache');
